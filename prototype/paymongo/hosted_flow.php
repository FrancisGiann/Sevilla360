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
                if (($r['attributes']['status']??null)!=='active') throw new RuntimeException('Provider checkout is not active.');
                $url=PayMongoTestProvider::checkoutUrl($r['attributes']['checkout_url']??null);
                $this->store->attach($a['id'],$r);
                $this->store->query('UPDATE pm_attempts SET checkout_url=? WHERE id=?',[$url,$a['id']]);
            } catch (Throwable $e) {
                // Keep the attempt identifier so the customer can reconcile the
                // one possible provider session; never create a second session.
                return ['attempt'=>$a['id'],'message'=>'uncertain'];
            }
            return ['attempt'=>$a['id'],'checkout_url'=>$url,'message'=>'PayMongo test checkout ready.'];
        }
        try {
            // A repeated customer action must reconcile the existing provider
            // session before it can reuse the URL or allow a later fresh attempt.
            return ['attempt'=>$a['id']]+$this->recoverState($a['id'],$customer,false);
        } catch (Throwable $e) {
            // Preserve the create fence on provider/network ambiguity. Never
            // repeat the create POST to recover an uncertain attempt.
            return ['attempt'=>$a['id'],'message'=>'uncertain'];
        }
    }
    /** Reconcile expired provider sessions before releasing unpaid bookings. */
    public function expireDue(?int $customer=null,int $limit=20): int {
        $limit=max(1,min(100,$limit));
        $args=[]; $owner='';
        if ($customer!==null) { $owner=' AND b.customer_id=?'; $args[]=$customer; }
        $expiredAttempts=$this->store->query("SELECT a.id,a.status,b.id AS booking_id,b.customer_id FROM pm_attempts a JOIN bookings b ON b.id=a.booking_id WHERE a.status IN ('creating','open','uncertain') AND a.expires_at<=NOW() AND b.source='Online' AND b.booking_status IN ('Awaiting Proof','Confirmed') AND b.payment_status IN ('Unpaid','Partial') AND a.base_paid_cents=CAST(ROUND(b.amount_paid*100) AS UNSIGNED)".$owner." ORDER BY a.expires_at,a.id LIMIT ".$limit,$args);
        while ($attempt=$expiredAttempts->fetch_assoc()) {
            try {
                if ($attempt['status']==='creating' && $this->store->expireUnsentAttempt($attempt['id'],(int)$attempt['customer_id'])) continue;
                // Retire an expired attempt and its 15-minute hold independently
                // of the booking deadline. Event Hall quotes stay payable for 24 hours.
                $this->recover($attempt['id'],(int)$attempt['customer_id']);
            } catch (Throwable $e) {
                error_log('Payment attempt expiry deferred: booking='.(int)$attempt['booking_id'].' type='.get_class($e));
            }
        }
        $bookingDeadline="CASE WHEN v.category='Event Hall' THEN b.payment_due_at ELSE COALESCE(b.payment_step_expires_at,(SELECT MIN(a.expires_at) FROM pm_attempts a WHERE a.booking_id=b.id AND a.base_paid_cents=CAST(ROUND(b.amount_paid*100) AS UNSIGNED))) END";
        $rows=$this->store->query("SELECT b.id,b.customer_id FROM bookings b JOIN venues v ON v.id=b.venue_id WHERE b.source='Online' AND b.booking_status IN ('Awaiting Proof','Confirmed') AND b.payment_status='Unpaid' AND b.amount_paid=0 AND {$bookingDeadline}<=NOW()".$owner." ORDER BY {$bookingDeadline},b.id LIMIT ".$limit,$args);
        $expired=0;
        while ($b=$rows->fetch_assoc()) {
            try {
                $attempts=$this->store->query("SELECT id,status FROM pm_attempts WHERE booking_id=? AND status IN ('creating','open','uncertain') ORDER BY created_at",[$b['id']]);
                while ($a=$attempts->fetch_assoc()) {
                    if($a['status']==='creating' && $this->store->expireUnsentAttempt($a['id'],(int)$b['customer_id']))continue;
                    $this->recover($a['id'],(int)$b['customer_id']);
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
    public function recover(string $id,int $customer): string {
        return (string)$this->recoverState($id,$customer)['message'];
    }
    /** Cancel a booking only after PayMongo confirms its checkout expired unpaid. */
    public function cancelCheckout(string $id,int $customer): string {
        $a=$this->owned($id,$customer);
        if (!$a['provider_session_id']) return 'cancel_uncertain';
        try {
            $r=$this->provider->retrieve($a['provider_session_id']);
            $this->validate($a,$r);
        } catch (Throwable $e) {
            error_log('PayMongo cancellation verification deferred: attempt='.$id.' type='.get_class($e));
            return 'cancel_uncertain';
        }
        if (!is_array($r['attributes']['payments']??null)) return 'cancel_uncertain';
        if (array_filter($r['attributes']['payments'],static fn($p)=>($p['attributes']['status']??'')==='paid')) {
            return $this->store->reconcileRetrieved($r);
        }
        if (($r['attributes']['status']??null)!=='expired') {
            if (($r['attributes']['status']??null)!=='active') return 'cancel_uncertain';
            try { $this->provider->expire($a['provider_session_id']); }
            catch (Throwable $e) { error_log('PayMongo expire request deferred: attempt='.$id.' type='.get_class($e)); }
            try {
                $r=$this->provider->retrieve($a['provider_session_id']);
                $this->validate($a,$r);
            } catch (Throwable $e) {
                error_log('PayMongo cancellation recheck deferred: attempt='.$id.' type='.get_class($e));
                return 'cancel_uncertain';
            }
        }
        if (!is_array($r['attributes']['payments']??null)) return 'cancel_uncertain';
        if (array_filter($r['attributes']['payments'],static fn($p)=>($p['attributes']['status']??'')==='paid')) {
            return $this->store->reconcileRetrieved($r);
        }
        if (($r['attributes']['status']??null)!=='expired'
            || array_filter($r['attributes']['payments'],static fn($p)=>($p['attributes']['status']??'')!=='failed')) return 'cancel_uncertain';
        return $this->store->cancelUnpaidAfterProviderExpiry($id,$customer,$r) ? 'cancelled' : 'cancel_uncertain';
    }
    /** Return a checkout URL only after retrieving and validating an active session. */
    private function recoverState(string $id,int $customer): array {
        $a=$this->owned($id,$customer);
        if(!$a['provider_session_id'])throw new RuntimeException('Unknown create outcome. A signed webhook can recover the binding; no automatic POST retry is allowed.');
        $r=$this->provider->retrieve($a['provider_session_id']);$this->validate($a,$r);
        if (!isset($r['attributes']['payments'])) throw new RuntimeException('Provider payments missing; cannot conclude unpaid.');
        if(array_filter($r['attributes']['payments'],static fn($p)=>($p['attributes']['status']??'')==='paid'))return ['message'=>$this->store->reconcileRetrieved($r)];
        $deadlinePassed=$a['expires_at']<=manual_payment_db_now($this->store->db);
        if(($r['attributes']['status']??'')==='expired' || $deadlinePassed){
            if(($r['attributes']['status']??'')!=='expired'){
                // Only the original 15-minute deadline may trigger expiration.
                $this->provider->expire($a['provider_session_id']);
                $r=$this->provider->retrieve($a['provider_session_id']);$this->validate($a,$r);
            }
            if(array_filter($r['attributes']['payments']??[],static fn($p)=>($p['attributes']['status']??'')==='paid'))return ['message'=>$this->store->reconcileRetrieved($r)];
            if(($r['attributes']['status']??'')!=='expired'
                || array_filter($r['attributes']['payments']??[],static fn($p)=>($p['attributes']['status']??'')!=='failed')) {
                return ['message'=>'uncertain'];
            }
            $deadline=$a['expires_at'];
            $this->store->close($id,$customer,$r);
            if ($deadlinePassed) $this->store->expireUnpaid((int)$a['booking_id'],$customer,$deadline);
            return ['message'=>'expired'];
        }
        // Payment methods in a nonterminal state may still settle. Do not send
        // the customer back into a checkout that could create another payment.
        if (($a['status']??null)!=='open' || ($r['attributes']['status']??null)!=='active') return ['message'=>'uncertain'];
        foreach ($r['attributes']['payments'] as $payment) {
            if (($payment['attributes']['status']??null)!=='failed') return ['message'=>'uncertain'];
        }
        $providerUrl=$r['attributes']['checkout_url']??null;
        $url=PayMongoTestProvider::checkoutUrl(is_string($providerUrl)?$providerUrl:($a['checkout_url']??null));
        if (is_string($providerUrl) && $providerUrl!==($a['checkout_url']??null)) {
            $this->store->query('UPDATE pm_attempts SET checkout_url=? WHERE id=? AND status=\'open\'',[$url,$id]);
        }
        return ['message'=>'open','checkout_url'=>$url];
    }
}
