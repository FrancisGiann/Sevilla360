<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/manual_payment.php';

function paymongo_verified_payment_method_label(array $attributes): string {
    // Read the successful payment's actual source, never the allowed-method list.
    $source = $attributes['source'] ?? null;
    $type = is_array($source) ? ($source['type'] ?? null) : null;
    return match ($type) {
        'gcash' => 'GCash',
        'paymaya' => 'Maya',
        'card' => 'Card',
        default => 'PayMongo Test',
    };
}

/** Local prototype: caller injects ONLY a disposable fixture connection. */
final class PayMongoPrototype {
    public function __construct(public readonly mysqli $db, private readonly bool $applicationSchema=false) {}
    public function query(string $sql, array $args = []): mysqli_result|bool {
        $s = $this->db->prepare($sql);
        if ($args) $s->bind_param(str_repeat('s', count($args)), ...$args);
        $s->execute();
        return $s->get_result();
    }
    public function row(string $sql, array $args = []): ?array {
        $r = $this->query($sql, $args); return $r instanceof mysqli_result ? $r->fetch_assoc() : null;
    }
    public function atomic(callable $fn): mixed {
        $this->db->begin_transaction();
        try { $value = $fn(); $this->db->commit(); return $value; }
        catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }
    public static function cents(string $value): int {
        if (!preg_match('/\A(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?\z/D', $value, $m)) throw new RuntimeException('Invalid money value.');
        return (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
    }
    public static function due(array $b): int {
        $total = self::cents((string)$b['total_amount']); $paid = self::cents((string)$b['amount_paid']);
        if ($paid < 0 || $total <= $paid) throw new RuntimeException('No balance is due.');
        if ($paid > 0) return $total - $paid;
        $percent = match ($b['payment_scheme']) { '100% Full'=>100, '50% Downpayment'=>50, '20% Reservation'=>20, default=>throw new RuntimeException('Invalid payment scheme.') };
        return min($total, intdiv($total * $percent + 50, 100));
    }
    public function booking(int $id, ?int $customer = null): array {
        $b = $this->row('SELECT b.*, v.category AS venue_category FROM bookings b JOIN venues v ON v.id=b.venue_id WHERE b.id=? FOR UPDATE', [$id]);
        if (!$b || ($customer !== null && (int)$b['customer_id'] !== $customer)) throw new RuntimeException('Booking not found or access denied.');
        if($this->applicationSchema) {
            $pending=$this->row("SELECT EXISTS(SELECT 1 FROM cancellations WHERE booking_id=? AND status='Pending') OR EXISTS(SELECT 1 FROM reschedule_requests WHERE booking_id=? AND status='Pending') OR EXISTS(SELECT 1 FROM manual_payment_submissions WHERE booking_id=? AND status='pending') AS pending",[$id,$id,$id]);
            $b['pending_change']=(int)$pending['pending'];
        }
        return $b;
    }
    private function currentEpoch(): string {
        if (!$this->applicationSchema) return '0';
        if (!function_exists('database_backup_authentication_epoch')) throw new RuntimeException('Restore authority guard unavailable.');
        return database_backup_authentication_epoch();
    }
    private function snapshot(array $b): string {
        $fields = array_intersect_key($b, array_flip(['venue_id','start_date','end_date','total_amount','amount_paid','payment_scheme','quote_version','quote_accepted']));
        $fields['resources'] = manual_payment_booking_resources($this->db, $b);
        return hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR));
    }
    private function eligible(array $b): void {
        if ($b['source'] !== 'Online' || !in_array($b['booking_status'], ['Awaiting Proof','Confirmed'], true)
            || (int)$b['pending_change'] || in_array($b['payment_status'], ['Paid','Refunded'], true)
            || $b['start_date'] < substr(manual_payment_db_now($this->db), 0, 10)) throw new RuntimeException('This booking cannot accept checkout.');
        if ($b['venue_category'] === 'Event Hall' && (!(int)$b['quote_accepted'] || (int)$b['quote_version'] < 1)) throw new RuntimeException('An accepted Event Hall quote is required.');
        if (self::cents($b['amount_paid']) === 0 && (!$b['payment_due_at'] || $b['payment_due_at'] <= manual_payment_db_now($this->db))) throw new RuntimeException('Booking payment deadline expired.');
    }
    /** Commit the local attempt BEFORE any provider creation; reopening never extends it. */
    public function begin(int $id, int $customer, bool $policyConsent=false): array {
        return $this->atomic(function () use ($id, $customer, $policyConsent) {
            $b = $this->booking($id, $customer); $this->eligible($b);
            $currentPolicy='terms-v4-paymongo-actual-fee';
            if (($b['policy_version']??'')!==$currentPolicy) {
                if (!$policyConsent) throw new RuntimeException('Review and accept the updated PayMongo refund terms before paying.');
                $this->query('UPDATE bookings SET policy_accepted_at=NOW(),policy_version=? WHERE id=? AND customer_id=?',[$currentPolicy,$id,$customer]);
                $b['policy_version']=$currentPolicy;
            }
            if ($this->row("SELECT r.payment_id FROM pm_receipts r JOIN pm_attempts a ON a.id=r.attempt_id WHERE a.booking_id=? AND r.disposition<>'applied' LIMIT 1", [$id])) throw new RuntimeException('A received payment needs resolution. Do not pay again.');
            $a = $this->row("SELECT * FROM pm_attempts WHERE booking_id=? AND status IN ('creating','open','uncertain') ORDER BY created_at DESC LIMIT 1 FOR UPDATE", [$id]);
            if ($a && $this->applicationSchema && !hash_equals($this->currentEpoch(), (string)($a['restore_epoch'] ?? '0'))) throw new RuntimeException('Checkout predates a restore. Recover or close it before paying again.');
            if ($a) return $a; // Includes expired/uncertain attempts: reconcile before replacement.
            $amount = self::due($b);
            if ($amount < 100) throw new RuntimeException('Amount is below the prototype minimum of PHP 1.');
            $token = bin2hex(random_bytes(16));
            $expiry = self::cents($b['amount_paid']) === 0
                ? manual_payment_acquire_step_hold_locked($this->db, $b, $token, true)
                : $this->row('SELECT DATE_ADD(NOW(), INTERVAL 15 MINUTE) expiry')['expiry'];
            if (!$expiry) throw new RuntimeException('Dates are no longer available. No payment was started.');
            $idAttempt = bin2hex(random_bytes(16));
            $this->query('INSERT INTO pm_attempts (id,booking_id,snapshot,amount_cents,base_paid_cents,hold_token,expires_at) VALUES (?,?,?,?,?,?,?)',
                [$idAttempt,$id,$this->snapshot($b),$amount,self::cents($b['amount_paid']),$token,$expiry]);
            if ($this->applicationSchema) $this->query('UPDATE pm_attempts SET restore_epoch=? WHERE id=?', [$this->currentEpoch(), $idAttempt]);
            return $this->row('SELECT * FROM pm_attempts WHERE id=?', [$idAttempt]);
        });
    }
    public function attach(string $attempt, array $resource): array {
        return $this->atomic(function () use ($attempt, $resource) {
            $ref = $this->row('SELECT booking_id FROM pm_attempts WHERE id=?', [$attempt]);
            if (!$ref) throw new RuntimeException('Unknown attempt.');
            $this->booking((int)$ref['booking_id']);
            $a = $this->row('SELECT * FROM pm_attempts WHERE id=? FOR UPDATE', [$attempt]);
            if (($resource['attributes']['livemode'] ?? null) !== false || ($resource['attributes']['reference_number'] ?? null) !== $attempt
                || !preg_match('/\Acs_[a-zA-Z0-9_]+\z/D', $resource['id'] ?? '')) throw new RuntimeException('Provider session mismatch.');
            if ($a['provider_session_id'] && $a['provider_session_id'] !== $resource['id']) throw new RuntimeException('Ambiguous provider creation.');
            $this->query("UPDATE pm_attempts SET provider_session_id=?,status=IF(status IN ('creating','uncertain'),'open',status) WHERE id=?", [$resource['id'],$attempt]);
            return $this->row('SELECT * FROM pm_attempts WHERE id=?', [$attempt]);
        });
    }
    /** Strict signature verification over exact bytes, test signature only; no real secret is loaded here. */
    public static function verify(string $raw, string $header, string $secret, int $now): array {
        if (strlen($raw) > 65536 || $secret === '') throw new RuntimeException('Invalid webhook.');
        $parts=[];
        foreach (explode(',', $header) as $part) {
            $pair=explode('=', trim($part), 2);
            if (count($pair)!==2 || isset($parts[$pair[0]])) throw new RuntimeException('Invalid signature header.');
            $parts[$pair[0]]=$pair[1];
        }
        if (!ctype_digit($parts['t'] ?? '') || abs($now-(int)$parts['t'])>300
            || !preg_match('/\A[a-f0-9]{64}\z/D', $parts['te'] ?? '') || ($parts['li'] ?? '') !== ''
            || !hash_equals(hash_hmac('sha256', $parts['t'].'.'.$raw, $secret), $parts['te'])) throw new RuntimeException('Webhook signature rejected.');
        $event=json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        // Newer documented delivery envelope has no stable event ID in its example.
        // Deduplicate exact bodies by digest, with payment ID providing accounting idempotency.
        if (($event['event_type'] ?? '')==='send.webhook' && ($event['data']['type'] ?? '')!=='event') {
            $d=$event['data'];
            $event=['data'=>['id'=>'evt_body_'.hash('sha256',$raw),'type'=>'event','attributes'=>[
                'livemode'=>$d['livemode']??null,'type'=>$d['type']??null,'data'=>$d['data']??null]]];
        }
        if (($event['data']['type'] ?? '') !== 'event' || ($event['data']['attributes']['livemode'] ?? null)!==false
            || !is_string($event['data']['attributes']['type'] ?? null)
            || !preg_match('/\Aevt_[a-zA-Z0-9_]+\z/D', $event['data']['id'] ?? '')) throw new RuntimeException('Only test-mode events are accepted.');
        return $event['data'];
    }
    public function webhook(string $raw, string $header, string $secret, ?int $now=null): string {
        $event=self::verify($raw,$header,$secret,$now ?? time());
        return $this->atomic(function () use ($raw,$event) {
            // Unique inbox insertion serializes concurrent deliveries and rolls back with accounting.
            $digest=hash('sha256',$raw);
            $this->query('INSERT INTO pm_events (id,digest) VALUES (?,?) ON DUPLICATE KEY UPDATE id=VALUES(id)', [$event['id'],$digest]);
            $saved=$this->row('SELECT * FROM pm_events WHERE id=? FOR UPDATE', [$event['id']]);
            if ($saved['digest']!==$digest) throw new RuntimeException('Event ID reused with different content.');
            if ($saved['outcome']!==null) return $saved['outcome'];
            $outcome='ignored';
            if ($event['attributes']['type'] === 'checkout_session.payment.paid') $outcome=$this->reconcile($event['attributes']['data']);
            $this->query('UPDATE pm_events SET outcome=? WHERE id=?', [$outcome,$event['id']]);
            return $outcome;
        });
    }
    /** Only server-side authenticated provider retrieval may call this; never accept browser resources. */
    public function reconcileRetrieved(array $session): string {
        return $this->atomic(fn()=>$this->reconcile($session));
    }
    /** A durable `creating` row proves no provider request was started yet. */
    public function expireUnsentAttempt(string $attempt,int $customer): bool {
        return $this->atomic(function()use($attempt,$customer){
            $ref=$this->row('SELECT booking_id FROM pm_attempts WHERE id=?',[$attempt]);
            if(!$ref)return false;
            $b=$this->booking((int)$ref['booking_id'],$customer);
            $a=$this->row('SELECT * FROM pm_attempts WHERE id=? FOR UPDATE',[$attempt]);
            if($a['status']!=='creating' || $a['provider_session_id'] || $a['expires_at']>manual_payment_db_now($this->db))return false;
            $this->query("UPDATE pm_attempts SET status='expired' WHERE id=? AND status='creating'",[$attempt]);
            if($b['payment_step_token']===$a['hold_token'])manual_payment_clear_step_hold_locked($this->db,$b);
            return true;
        });
    }
    /** Caller owns transaction. Both webhook and authenticated recovery use this single path. */
    private function reconcile(array $session): string {
        $sa=$session['attributes'] ?? [];
        if (($session['type'] ?? '')!=='checkout_session' || ($sa['livemode'] ?? null)!==false) throw new RuntimeException('Invalid test checkout.');
        $a=$this->row('SELECT * FROM pm_attempts WHERE provider_session_id=?', [$session['id'] ?? '']);
        if (!$a && preg_match('/\A[a-f0-9]{32}\z/D', $sa['reference_number'] ?? '')) {
            $candidate=$this->row('SELECT * FROM pm_attempts WHERE id=?',[$sa['reference_number']]);
            if ($candidate) {
                $this->booking((int)$candidate['booking_id']);
                $candidate=$this->row('SELECT * FROM pm_attempts WHERE id=? FOR UPDATE',[$candidate['id']]);
                if (!$candidate['provider_session_id'] && in_array($candidate['status'],['creating','uncertain'],true)
                    && preg_match('/\Acs_[A-Za-z0-9_]+\z/D',$session['id'] ?? '')) {
                    $this->query("UPDATE pm_attempts SET provider_session_id=?,status='open' WHERE id=?",[$session['id'],$candidate['id']]);
                    $a=$this->row('SELECT * FROM pm_attempts WHERE id=?',[$candidate['id']]);
                }
            }
        }
        if (!$a || ($sa['reference_number'] ?? '')!==$a['id']) throw new RuntimeException('Unmapped checkout; reconcile creation first.');
        $b=$this->booking((int)$a['booking_id']);
        $a=$this->row('SELECT * FROM pm_attempts WHERE id=? FOR UPDATE', [$a['id']]);
        $paid=array_values(array_filter($sa['payments'] ?? [], static fn($p)=>($p['attributes']['status'] ?? '')==='paid'));
        if (count($paid)!==1) throw new RuntimeException('Expected exactly one successful payment.');
        $p=$paid[0]; $pa=$p['attributes'];
        if (($p['type'] ?? '')!=='payment' || !preg_match('/\Apay_[a-zA-Z0-9_]+\z/D',$p['id'] ?? '')
            || ($pa['livemode'] ?? null)!==false || !is_int($pa['amount'] ?? null) || $pa['amount']<=0
            || $pa['amount']>999999999999 || !is_string($pa['currency'] ?? null) || strlen($pa['currency'])>8
            || (array_key_exists('fee',$pa) && (!is_int($pa['fee']) || $pa['fee']<0 || $pa['fee']>$pa['amount']))
            || (array_key_exists('net_amount',$pa) && $pa['net_amount']!==null && (!is_int($pa['net_amount']) || $pa['net_amount']<0))) throw new RuntimeException('Invalid test payment.');
        $receipt=$this->row('SELECT * FROM pm_receipts WHERE payment_id=? FOR UPDATE', [$p['id']]);
        if ($receipt) {
            if ($receipt['attempt_id']!==$a['id'] || (int)$receipt['amount_cents']!==$pa['amount'] || $receipt['currency']!==$pa['currency']) throw new RuntimeException('Payment identity conflict.');
            return $receipt['disposition'];
        }
        $reason='applied';
        if ($this->applicationSchema && !hash_equals($this->currentEpoch(), (string)($a['restore_epoch'] ?? '0'))) $reason='restored_checkout';
        elseif ($this->row('SELECT payment_id FROM pm_receipts WHERE attempt_id=? LIMIT 1', [$a['id']])) $reason='extra_payment';
        elseif ($pa['currency']!=='PHP' || $pa['amount']!==(int)$a['amount_cents']) $reason='amount_mismatch';
        elseif ($this->snapshot($b)!==$a['snapshot']) $reason='booking_changed';
        elseif ($a['status']!=='open' || $a['expires_at']<=manual_payment_db_now($this->db)) $reason='late_payment';
        else {
            try { $this->eligible($b); } catch (RuntimeException $e) { $reason='booking_not_payable'; }
        }
        if ($reason==='applied' && (int)$a['base_paid_cents']===0) {
            if (!manual_payment_consume_step_hold_locked($this->db,$b,$a['hold_token'])) $reason='hold_expired_or_released';

        }
        if ($reason==='applied' && !manual_payment_claim_inventory_locked($this->db,$b)) $reason='inventory_conflict';
        if ($reason==='applied') {
            $sum=self::cents($b['amount_paid'])+$pa['amount']; $total=self::cents($b['total_amount']);
            if ($sum>$total) throw new RuntimeException('Payment exceeds balance.');
            $decimal=intdiv($sum,100).'.'.str_pad((string)($sum%100),2,'0',STR_PAD_LEFT);
            $this->query("UPDATE bookings SET amount_paid=?,payment_status=?,booking_status='Confirmed' WHERE id=?", [$decimal,$sum===$total?'Paid':'Partial',$b['id']]);
        }
        if($reason==='applied' && $this->applicationSchema) {
            $amount=intdiv($pa['amount'],100).'.'.str_pad((string)($pa['amount']%100),2,'0',STR_PAD_LEFT);
            $fee=array_key_exists('fee',$pa)?$pa['fee']:null;
            $net=$pa['net_amount']??null;
            $feeTimeSql=$fee===null?'NULL':'NOW(6)';
            $this->query("INSERT INTO payments (booking_id,payment_method,amount,transaction_id,fee_cents,net_amount_cents,fee_verified_at,status) VALUES (?,?,?,?,?,?,{$feeTimeSql},'Success')",
                [$b['id'],paymongo_verified_payment_method_label($pa),$amount,$p['id'],$fee,$net]);
        }
        // Preserve every mapped paid receipt, even when fulfillment is unsafe. Never auto-refund.
        $this->query('INSERT INTO pm_receipts (payment_id,attempt_id,amount_cents,currency,disposition) VALUES (?,?,?,?,?)', [$p['id'],$a['id'],$pa['amount'],$pa['currency'],$reason]);
        $this->query('UPDATE pm_attempts SET status=? WHERE id=?', [$reason==='applied'?'paid':'review',$a['id']]);
        // A received payment that could not be safely applied remains reserved
        // for review. Only successful fulfillment consumes the durable hold.
        if ($reason==='applied' && $b['payment_step_token']===$a['hold_token']) manual_payment_clear_step_hold_locked($this->db,$b);
        return $reason;
    }
    /** Called after provider reconciliation; lock the booking before checking attempts. */
    public function expireUnpaid(int $id, int $customer, ?string $checkoutDeadline=null): bool {
        return $this->atomic(function () use ($id,$customer,$checkoutDeadline) {
            $b=$this->booking($id,$customer);
            $now=manual_payment_db_now($this->db);
            $lastAttempt=$this->row('SELECT expires_at FROM pm_attempts WHERE booking_id=? ORDER BY created_at DESC,id DESC LIMIT 1',[$id]);
            $deadline=$b['payment_step_expires_at'] ?: ($checkoutDeadline ?: ($lastAttempt['expires_at'] ?? ($b['payment_due_at'] ?? null)));
            if (!$deadline || $deadline>$now || $b['source']!=='Online'
                || !in_array($b['booking_status'],['Awaiting Proof','Confirmed'],true)
                || $b['payment_status']!=='Unpaid' || self::cents((string)$b['amount_paid'])!==0
                || (int)$b['pending_change']) return false;
            if ($this->row("SELECT id FROM pm_attempts WHERE booking_id=? AND status IN ('creating','open','uncertain','review') LIMIT 1 FOR UPDATE",[$id])
                || $this->row("SELECT r.payment_id FROM pm_receipts r JOIN pm_attempts a ON a.id=r.attempt_id WHERE a.booking_id=? LIMIT 1",[$id])) return false;
            manual_payment_clear_step_hold_locked($this->db,$b);
            // Keep a past deadline as the durable distinction between an
            // expired unpaid reservation and a customer/admin cancellation.
            $this->query("UPDATE bookings SET booking_status='Cancelled',payment_due_at=DATE_SUB(NOW(),INTERVAL 1 SECOND),updated_at=NOW() WHERE id=?",[$id]);
            $this->query("INSERT INTO audit_logs (user_id,module,action,ip_address) VALUES (NULL,'Payment Expiry',?,'System scheduler')",['Expired unpaid booking '.$b['reference_no'].' after its payment hold ended']);
            if($this->applicationSchema){
                try {
                    require_once dirname(__DIR__,2).'/includes/notifications.php';
                    require_once dirname(__DIR__,2).'/includes/realtime.php';
                    $owner=$this->row('SELECT user_id FROM customers WHERE id=?',[$customer]);
                    $userId=(int)($owner['user_id']??0);
                    if($userId>0){
                        create_user_notification($this->db,$userId,'Booking Expired','Your unpaid booking '.$b['reference_no'].' expired after its 15-minute payment hold. Start a new booking if you would like to try again.');
                        realtime_enqueue_event($this->db,'customer:'.$userId,'booking.expired',['booking_id'=>$id,'reference_no'=>(string)$b['reference_no']]);
                    }
                    realtime_enqueue_event($this->db,'admin','booking.expired',['booking_id'=>$id,'reference_no'=>(string)$b['reference_no']]);
                } catch (Throwable $notificationError) {
                    error_log('Payment expiry notification deferred: booking='.$id.' type='.get_class($notificationError));
                }
            }
            return true;
        });
    }
    /** Only after authoritative simulator expiry/no paid payment; never from a redirect. */
    public function close(string $attempt, int $customer, array $session): void {
        $this->atomic(function () use ($attempt,$customer,$session) {
            $ref=$this->row('SELECT booking_id FROM pm_attempts WHERE id=?', [$attempt]);
            if (!$ref) throw new RuntimeException('Attempt not found.');
            $b=$this->booking((int)$ref['booking_id'],$customer);
            $a=$this->row('SELECT * FROM pm_attempts WHERE id=? FOR UPDATE', [$attempt]);
            if (($session['attributes']['livemode'] ?? null)!==false || $session['id']!==$a['provider_session_id']
                || $session['attributes']['reference_number']!==$attempt || $session['attributes']['status']!=='expired'
                || !is_array($session['attributes']['payments']??null)
                || array_filter($session['attributes']['payments'],static fn($p)=>($p['attributes']['status']??'')!=='failed')) throw new RuntimeException('Provider expiry is not confirmed; recover payment first.');
            if (in_array($a['status'],['paid','review'],true)) return;
            $this->query("UPDATE pm_attempts SET status='expired' WHERE id=?", [$attempt]);
            if ($b['payment_step_token']===$a['hold_token']) {
                $this->query('INSERT IGNORE INTO payment_step_exits (token,booking_id) VALUES (?,?)',[$a['hold_token'],$b['id']]);
                manual_payment_clear_step_hold_locked($this->db,$b);
            }
        });
    }
}
