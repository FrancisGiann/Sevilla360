<?php
declare(strict_types=1);
require_once __DIR__ . '/service.php';
/** NO HTTP client and NO provider credentials. This is an explicit fake, not a PayMongo SDK. */
final class PayMongoSimulator {
    // Public fixture string, deliberately not a credential. Only localhost synthetic events use it.
    public const SIGNING_FIXTURE = 'public-synthetic-webhook-fixture-not-a-secret';
    public function __construct(private PayMongoPrototype $store) {}
    public function find(string $reference): ?array {
        $row=$this->store->row('SELECT resource FROM pm_sim_sessions WHERE reference_no=?',[$reference]);
        return $row ? json_decode($row['resource'],true,32,JSON_THROW_ON_ERROR) : null;
    }
    public function create(array $a): array {
        // Fake-only reference uniqueness; do not assume PayMongo create supports this guarantee.
        $id='cs_sim_'.$a['id'];
        $r=['id'=>$id,'type'=>'checkout_session','attributes'=>['livemode'=>false,'reference_number'=>$a['id'],
            'status'=>'active','payments'=>[],'line_items'=>[['amount'=>(int)$a['amount_cents'],'currency'=>'PHP','quantity'=>1,'name'=>'Synthetic booking installment']]]];
        $this->store->query('INSERT INTO pm_sim_sessions (id,reference_no,resource) VALUES (?,?,?) ON DUPLICATE KEY UPDATE id=VALUES(id)',[$id,$a['id'],json_encode($r,JSON_THROW_ON_ERROR)]);
        return $this->find($a['id']);
    }
    public function settle(string $ref, bool $allowLate=false): array {
        return $this->store->atomic(function () use ($ref,$allowLate) {
            $row=$this->store->row('SELECT resource FROM pm_sim_sessions WHERE reference_no=? FOR UPDATE',[$ref]);
            if (!$row) throw new RuntimeException('Simulated session not found.');
            $r=json_decode($row['resource'],true,32,JSON_THROW_ON_ERROR);
            if (!$r['attributes']['payments']) {
                if ($r['attributes']['status']==='expired' && !$allowLate) throw new RuntimeException('Checkout expired. Use the explicit late-settlement test.');
                $r['attributes']['payments']=[['id'=>'pay_sim_'.$ref,'type'=>'payment','attributes'=>['livemode'=>false,'status'=>'paid','currency'=>'PHP','amount'=>$r['attributes']['line_items'][0]['amount']]]];
                $this->save($r);
            }
            return $r;
        });
    }
    private function save(array $r): void {
        $this->store->query('UPDATE pm_sim_sessions SET resource=? WHERE id=?',[json_encode($r,JSON_THROW_ON_ERROR),$r['id']]);
    }
    public function expire(string $ref): array {
        return $this->store->atomic(function () use ($ref) {
            $row=$this->store->row('SELECT resource FROM pm_sim_sessions WHERE reference_no=? FOR UPDATE',[$ref]);
            if (!$row) throw new RuntimeException('Unknown simulated session.');
            $r=json_decode($row['resource'],true,32,JSON_THROW_ON_ERROR);
            if (!$r['attributes']['payments']) { $r['attributes']['status']='expired'; $this->save($r); }
            return $r;
        });
    }
    public static function event(array $r, ?string $eventId=null, string $type='checkout_session.payment.paid'): array {
        $raw=json_encode(['data'=>['id'=>$eventId ?? 'evt_sim_'.bin2hex(random_bytes(12)),'type'=>'event','attributes'=>['livemode'=>false,'type'=>$type,'data'=>$r]]],JSON_THROW_ON_ERROR);
        $t=time(); return [$raw,'t='.$t.',te='.hash_hmac('sha256',$t.'.'.$raw,self::SIGNING_FIXTURE).',li='];
    }
    public function deliver(array $r, ?string $eventId=null): string {
        [$raw,$sig]=self::event($r,$eventId);
        return $this->store->webhook($raw,$sig,self::SIGNING_FIXTURE);
    }
    public function recover(string $attempt, int $customer): string {
        $a=$this->store->row('SELECT * FROM pm_attempts WHERE id=?',[$attempt]);
        if (!$a) throw new RuntimeException('Unknown attempt.');
        $this->store->atomic(fn()=>$this->store->booking((int)$a['booking_id'],$customer));
        $r=$this->find($attempt);
        if (!$r) throw new RuntimeException('Creation outcome is unknown. Do not start another payment.');
        $this->store->attach($attempt,$r);
        if ($r['attributes']['payments']) return $this->deliver($r);
        if ($a['expires_at']<=manual_payment_db_now($this->store->db)) $r=$this->expire($attempt);
        if ($r['attributes']['status']==='expired') { $this->store->close($attempt,$customer,$r); return 'expired'; }
        return 'open';
    }
}
