<?php
declare(strict_types=1);
require_once __DIR__.'/provider.php';
require_once __DIR__.'/service.php';
/** Same transaction/receipt engine as the simulator, with authoritative API retrieval. */
final class PayMongoHostedFlow {
    public function __construct(private PayMongoPrototype $store,private PayMongoTestProvider $provider) {}
    private function owned(string $id,int $customer): array {
        $a=$this->store->row('SELECT * FROM pm_attempts WHERE id=?',[$id]);
        if (!$a) throw new RuntimeException('Unknown attempt.');
        $this->store->atomic(fn()=>$this->store->booking((int)$a['booking_id'],$customer));
        return $a;
    }
    private function validate(array $a,array $r): void {
        if (($r['attributes']['reference_number']??null)!==$a['id'] || ($a['provider_session_id'] && $r['id']!==$a['provider_session_id'])) throw new RuntimeException('Provider checkout binding mismatch.');
        $items=$r['attributes']['line_items']??[];
        if (count($items)!==1 || ($items[0]['amount']??null)!==(int)$a['amount_cents'] || ($items[0]['currency']??null)!=='PHP' || ($items[0]['quantity']??null)!==1) throw new RuntimeException('Provider checkout price mismatch.');
    }
    public function start(int $booking,int $customer,bool $policyConsent=false): array {
        $a=$this->store->begin($booking,$customer,$policyConsent);
        $claimed=$this->store->atomic(function()use($a,$customer){
            $this->store->booking((int)$a['booking_id'],$customer);
            $current=$this->store->row('SELECT * FROM pm_attempts WHERE id=? FOR UPDATE',[$a['id']]);
            if($current['status']!=='creating')return false;
            $this->store->query("UPDATE pm_attempts SET status='uncertain' WHERE id=?",[$a['id']]); return true;
        });
        if($claimed){
            // Never retry POST: timeout, malformed response or failed local persistence are uncertain outcomes.
            try {
                $r=$this->provider->create($a); $this->validate($a,$r);
                $url=PayMongoTestProvider::checkoutUrl($r['attributes']['checkout_url']??null);
                $this->store->attach($a['id'],$r);
                $this->store->query('UPDATE pm_attempts SET checkout_url=? WHERE id=?',[$url,$a['id']]);
            } catch (Throwable $e) {
                // Keep the attempt identifier so the customer can reconcile the
                // one possible provider session; never create a second session.
                return ['attempt'=>$a['id'],'message'=>'uncertain'];
            }
        }
        $a=$this->owned($a['id'],$customer);
        if($a['status']==='expired')return ['attempt'=>$a['id'],'message'=>'expired'];
        if(!$a['provider_session_id'])return ['attempt'=>$a['id'],'message'=>'uncertain'];
        if($a['expires_at']<=manual_payment_db_now($this->store->db))throw new RuntimeException('Hold expired. Recover or close this checkout before starting another.');
        if(!$a['checkout_url']){
            $r=$this->provider->retrieve($a['provider_session_id']);$this->validate($a,$r);
            $a['checkout_url']=PayMongoTestProvider::checkoutUrl($r['attributes']['checkout_url']??null);
            $this->store->query('UPDATE pm_attempts SET checkout_url=? WHERE id=?',[$a['checkout_url'],$a['id']]);
        }
        return ['attempt'=>$a['id'],'checkout_url'=>$a['checkout_url'],'message'=>'PayMongo test checkout ready.'];
    }
    /** Reconcile expired provider sessions before releasing unpaid bookings. */
    public function expireDue(?int $customer=null,int $limit=20): int {
        $limit=max(1,min(100,$limit));
        $args=[]; $owner='';
        if ($customer!==null) { $owner=' AND b.customer_id=?'; $args[]=$customer; }
        $rows=$this->store->query("SELECT b.id,b.customer_id FROM bookings b WHERE b.source='Online' AND b.booking_status IN ('Awaiting Proof','Confirmed') AND b.payment_status='Unpaid' AND b.amount_paid=0 AND COALESCE(b.payment_step_expires_at,(SELECT MAX(a.expires_at) FROM pm_attempts a WHERE a.booking_id=b.id),b.payment_due_at)<=NOW()".$owner." ORDER BY COALESCE(b.payment_step_expires_at,(SELECT MAX(a.expires_at) FROM pm_attempts a WHERE a.booking_id=b.id),b.payment_due_at),b.id LIMIT ".$limit,$args);
        $expired=0;
        while ($b=$rows->fetch_assoc()) {
            try {
                $attempts=$this->store->query("SELECT id,status FROM pm_attempts WHERE booking_id=? AND status IN ('creating','open','uncertain') ORDER BY created_at",[$b['id']]);
                while ($a=$attempts->fetch_assoc()) {
                    if($a['status']==='creating' && $this->store->expireUnsentAttempt($a['id'],(int)$b['customer_id']))continue;
                    $this->recover($a['id'],(int)$b['customer_id'],true);
                }
                $expiredNow=$this->store->expireUnpaid((int)$b['id'],(int)$b['customer_id']);
                if(!$expiredNow){
                    $state=$this->store->row('SELECT booking_status,payment_status,amount_paid,payment_due_at FROM bookings WHERE id=? AND customer_id=?',[(int)$b['id'],(int)$b['customer_id']]);
                    $expiredNow=$state && $state['booking_status']==='Cancelled' && $state['payment_status']==='Unpaid'
                        && (float)$state['amount_paid']===0.0 && $state['payment_due_at']!==null
                        && $state['payment_due_at']<=manual_payment_db_now($this->store->db);
                }
                if($expiredNow)$expired++;
            } catch (Throwable $e) {
                // Unknown payment outcome retains the booking for reconciliation.
                error_log('Payment expiry deferred: booking='.(int)$b['id'].' type='.get_class($e));
            }
        }
        return $expired;
    }
    public function recover(string $id,int $customer,bool $close=false): string {
        $a=$this->owned($id,$customer);
        if(!$a['provider_session_id'])throw new RuntimeException('Unknown create outcome. A signed webhook can recover the binding; no automatic POST retry is allowed.');
        $r=$this->provider->retrieve($a['provider_session_id']);$this->validate($a,$r);
        if (!isset($r['attributes']['payments'])) throw new RuntimeException('Provider payments missing; cannot conclude unpaid.');
        if(array_filter($r['attributes']['payments'],static fn($p)=>($p['attributes']['status']??'')==='paid'))return $this->store->reconcileRetrieved($r);
        if($close || ($r['attributes']['status']??'')==='expired' || $a['expires_at']<=manual_payment_db_now($this->store->db)){
            if(($r['attributes']['status']??'')!=='expired'){
                // A 400/in-flight response is not expiry. Preserve attempt; caller can retrieve again.
                $this->provider->expire($a['provider_session_id']);
                $r=$this->provider->retrieve($a['provider_session_id']);$this->validate($a,$r);
            }
            if(array_filter($r['attributes']['payments']??[],static fn($p)=>($p['attributes']['status']??'')==='paid'))return $this->store->reconcileRetrieved($r);
            $deadline=$a['expires_at'];
            $this->store->close($id,$customer,$r);
            if ($deadline<=manual_payment_db_now($this->store->db)) $this->store->expireUnpaid((int)$a['booking_id'],$customer,$deadline);
            return 'expired';
        }
        return 'open';
    }
}
