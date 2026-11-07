<?php
declare(strict_types=1);

require_once __DIR__.'/../prototype/paymongo/provider.php';
require_once __DIR__.'/refund_helper.php';

const PAYMONGO_REFUND_POLICY_VERSION = 'paymongo-original-method-v1';

function paymongo_refund_money_to_cents(string $value): int {
    if (!preg_match('/\A(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?\z/D', trim($value), $match)) {
        throw new RuntimeException('A payment amount could not be reconciled.');
    }
    $whole=(int)$match[1];
    return $whole*100+(int)str_pad($match[2]??'',2,'0',STR_PAD_RIGHT);
}

function paymongo_refund_cents_to_money(int $cents): string {
    if ($cents<0) throw new RuntimeException('Invalid refund amount.');
    return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);
}

function paymongo_refund_is_booking(mysqli $conn,int $bookingId): bool {
    $stmt=$conn->prepare("SELECT 1 FROM payments WHERE booking_id=? AND status='Success' AND LEFT(transaction_id,4)='pay_' LIMIT 1");
    if (!$stmt) throw new RuntimeException('Payment history could not be checked.');
    $stmt->bind_param('i',$bookingId);
    if (!$stmt->execute()) throw new RuntimeException('Payment history could not be checked.');
    if (!$stmt->get_result()->fetch_row()) return false;
    paymongo_refund_authoritative_payments($conn,$bookingId);
    return true;
}

/** A pay_ string is not proof of ownership; link every payment to its applied checkout receipt. */
function paymongo_refund_authoritative_payments(mysqli $conn,int $bookingId): array {
    // Receipt rows may use the database default collation while legacy payment
    // references keep the original table collation. These opaque IDs must match
    // byte-for-byte, regardless of either column's collation.
    $stmt=$conn->prepare("SELECT p.id,p.transaction_id,p.amount,p.fee_cents,p.net_amount_cents,p.fee_verified_at,r.payment_id AS receipt_payment_id,r.amount_cents AS receipt_amount_cents,r.currency AS receipt_currency,r.disposition,a.booking_id AS attempt_booking_id FROM payments p LEFT JOIN pm_receipts r ON BINARY r.payment_id=BINARY p.transaction_id LEFT JOIN pm_attempts a ON a.id=r.attempt_id WHERE p.booking_id=? AND p.status='Success' AND LEFT(p.transaction_id,4)='pay_' ORDER BY p.id");
    if (!$stmt) throw new RuntimeException('PayMongo receipt ownership could not be verified.');
    $stmt->bind_param('i',$bookingId);
    if (!$stmt->execute()) throw new RuntimeException('PayMongo receipt ownership could not be verified.');
    $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $row) {
        $gross=paymongo_refund_money_to_cents((string)$row['amount']);
        if (($row['receipt_payment_id']??null)!==$row['transaction_id']
            || ($row['disposition']??null)!=='applied'
            || (int)($row['attempt_booking_id']??0)!==$bookingId
            || (int)($row['receipt_amount_cents']??0)!==$gross
            || ($row['receipt_currency']??null)!=='PHP') {
            throw new RuntimeException('A PayMongo payment is not authoritatively linked to this booking. Contact staff to reconcile it.');
        }
    }
    $exceptions=$conn->prepare("SELECT 1 FROM pm_receipts r JOIN pm_attempts a ON a.id=r.attempt_id WHERE a.booking_id=? AND r.disposition<>'applied' LIMIT 1");
    if (!$exceptions) throw new RuntimeException('PayMongo payment exceptions could not be checked.');
    $exceptions->bind_param('i',$bookingId); $exceptions->execute();
    if ($exceptions->get_result()->fetch_row()) throw new RuntimeException('A PayMongo payment needs staff reconciliation before refunding.');
    return $rows;
}

function paymongo_refund_local_snapshot_matches(mysqli $conn,int $bookingId,array $snapshot): bool {
    $rows=paymongo_refund_authoritative_payments($conn,$bookingId);
    $stmt=$conn->prepare("SELECT transaction_id,amount,fee_cents FROM payments WHERE booking_id=? AND status='Success' ORDER BY id");
    if (!$stmt) throw new RuntimeException('Payment history could not be checked.');
    $stmt->bind_param('i',$bookingId);
    if (!$stmt->execute()) throw new RuntimeException('Payment history could not be checked.');
    $allRows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $byId=[];
    foreach ($allRows as $row) {
        if (!is_string($row['transaction_id']??null) || !str_starts_with($row['transaction_id'],'pay_')) return false;
        $byId[$row['transaction_id']]=[
            'gross'=>paymongo_refund_money_to_cents((string)$row['amount']),
            'fee'=>$row['fee_cents']===null?null:(int)$row['fee_cents'],
        ];
    }
    if (count($rows)!==count($byId)) return false;
    if (count($byId)!==count($snapshot['allocations']??[])) return false;
    foreach ($snapshot['allocations'] as $allocation) {
        $paymentId=(string)$allocation['payment_id'];
        if (!isset($byId[$paymentId]) || $byId[$paymentId]['gross']!==(int)$allocation['gross_cents']
            || $byId[$paymentId]['fee']!==(int)$allocation['fee_cents']) return false;
    }
    return true;
}

/**
 * Reconcile a booking's successful payments with PayMongo before any booking
 * lock is held. Every PayMongo fee and prior refund comes from its API resource.
 */
function paymongo_refund_prepare_snapshot(mysqli $conn,int $bookingId,PayMongoTestProvider $provider): array {
    if ($bookingId<1) throw new InvalidArgumentException('Invalid booking.');
    $stmt=$conn->prepare("SELECT id,transaction_id,amount,status,fee_cents,net_amount_cents,fee_verified_at FROM payments WHERE booking_id=? AND status='Success' ORDER BY id");
    if (!$stmt) throw new RuntimeException('Payment history could not be loaded.');
    $stmt->bind_param('i',$bookingId);
    if (!$stmt->execute()) throw new RuntimeException('Payment history could not be loaded.');
    $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $gatewayRows=array_values(array_filter($rows,static fn($row)=>is_string($row['transaction_id']??null)&&str_starts_with($row['transaction_id'],'pay_')));
    if (!$gatewayRows) return ['provider'=>'manual','gross_cents'=>0,'fee_cents'=>0,'prior_refund_cents'=>0,'refund_cents'=>0,'fingerprint'=>null,'allocations'=>[]];
    if (count($gatewayRows)!==count($rows)) throw new RuntimeException('This booking has mixed payment methods and needs staff review before a refund.');
    $authoritative=paymongo_refund_authoritative_payments($conn,$bookingId);
    if (count($authoritative)!==count($gatewayRows)) throw new RuntimeException('PayMongo payment ownership could not be reconciled.');

    $payments=[]; $grossTotal=0; $feeTotal=0; $priorTotal=0; $refundTotal=0;
    foreach ($gatewayRows as $row) {
        $paymentId=(string)$row['transaction_id'];
        if (!preg_match('/\Apay_[A-Za-z0-9_]+\z/D',$paymentId)) throw new RuntimeException('A PayMongo payment reference is invalid.');
        $gross=paymongo_refund_money_to_cents((string)$row['amount']);
        if ($gross<1) throw new RuntimeException('A PayMongo payment amount is invalid.');

        $storedFee=$row['fee_cents'];
        $verifiedAt=$row['fee_verified_at']??null;
        if ($storedFee===null || $verifiedAt===null) {
            $providerPayment=$provider->retrievePayment($paymentId);
            $attributes=$providerPayment['attributes'];
            if ($attributes['amount']!==$gross) throw new RuntimeException('The PayMongo payment amount no longer matches the receipt.');
            if ($storedFee!==null && (int)$storedFee!==$attributes['fee']) throw new RuntimeException('The stored PayMongo fee does not match the provider.');
            $fee=$attributes['fee']; $net=$attributes['net_amount'];
            $update=$conn->prepare('UPDATE payments SET fee_cents=?,net_amount_cents=?,fee_verified_at=NOW(6) WHERE id=? AND transaction_id=? AND status=\'Success\' AND amount=? AND (fee_cents IS NULL OR fee_cents=?)');
            if (!$update) throw new RuntimeException('The verified PayMongo fee could not be saved.');
            $grossMoney=paymongo_refund_cents_to_money($gross);
            $update->bind_param('iiissi',$fee,$net,$row['id'],$paymentId,$grossMoney,$fee);
            if (!$update->execute() || $update->affected_rows!==1) throw new RuntimeException('The payment changed while its PayMongo fee was being verified.');
        } else {
            $fee=(int)$storedFee; $net=$row['net_amount_cents']===null?null:(int)$row['net_amount_cents'];
            if ($fee<0 || $fee>$gross) throw new RuntimeException('The saved PayMongo fee is outside the payment amount.');
            // Re-fetching is intentionally deferred once the verified source is saved.
        }

        $providerRefunds=$provider->refundsForPayment($paymentId);
        $prior=0;
        foreach ($providerRefunds as $refund) {
            $status=$refund['attributes']['status'];
            // A refund not yet succeeded is not completed accounting. Fence it
            // until reconciliation so another request cannot over-refund or
            // cause the booking to be marked refunded prematurely.
            if (in_array($status,['pending','processing'],true)) {
                throw new RuntimeException('A PayMongo refund is still in progress and must be reconciled before another request.');
            }
            if ($status==='succeeded') $prior+=(int)$refund['attributes']['amount'];
        }
        if ($prior>$gross) throw new RuntimeException('PayMongo reports refunds greater than the original payment.');
        $remaining=max(0,$gross-$fee-$prior);
        if ($remaining>0 && $remaining<100) throw new RuntimeException('A remaining PayMongo refund allocation is below the supported minimum and needs staff review.');
        $payments[]=[
            'payment_id'=>$paymentId,'gross_cents'=>$gross,'fee_cents'=>$fee,
            'prior_refund_cents'=>$prior,'refund_cents'=>$remaining,
        ];
        $grossTotal+=$gross; $feeTotal+=$fee; $priorTotal+=$prior; $refundTotal+=$remaining;
    }

    $bookingStmt=$conn->prepare('SELECT amount_paid FROM bookings WHERE id=?');
    if (!$bookingStmt) throw new RuntimeException('Booking payment total could not be loaded.');
    $bookingStmt->bind_param('i',$bookingId); $bookingStmt->execute();
    $bookingRow=$bookingStmt->get_result()->fetch_assoc();
    if (!$bookingRow || paymongo_refund_money_to_cents((string)$bookingRow['amount_paid'])!==$grossTotal) {
        throw new RuntimeException('The PayMongo payment history does not match the booking total. Contact staff to reconcile the payments.');
    }
    if ($refundTotal<100) throw new RuntimeException('No PayMongo refund amount remains after the actual fees and prior refunds.');

    $canonical=[];
    foreach ($payments as $payment) $canonical[]=$payment['payment_id'].':'.$payment['gross_cents'].':'.$payment['fee_cents'].':'.$payment['prior_refund_cents'].':'.$payment['refund_cents'];
    return [
        'provider'=>'paymongo','gross_cents'=>$grossTotal,'fee_cents'=>$feeTotal,
        'prior_refund_cents'=>$priorTotal,'refund_cents'=>$refundTotal,
        'fingerprint'=>hash('sha256',implode('|',$canonical)),'allocations'=>$payments,
    ];
}

/** Persist one immutable fee/refund snapshot while request_cancel owns the booking transaction. */
function paymongo_refund_persist_snapshot(mysqli $conn,array $snapshot,int $bookingId,int $cancellationId,?int $actorUserId): string {
    if (($snapshot['provider']??'')!=='paymongo' || !is_array($snapshot['allocations']??null) || !$snapshot['allocations']) {
        throw new InvalidArgumentException('A verified PayMongo refund snapshot is required.');
    }
    $id=bin2hex(random_bytes(16));
    $insert=$conn->prepare('INSERT INTO pm_refund_snapshots (id,cancellation_id,booking_id,gross_cents,fee_cents,prior_refund_cents,refund_cents,policy_version,created_by) VALUES (?,?,?,?,?,?,?,?,?)');
    if (!$insert) throw new RuntimeException('The refund snapshot could not be saved.');
    $gross=(int)$snapshot['gross_cents']; $fee=(int)$snapshot['fee_cents']; $prior=(int)$snapshot['prior_refund_cents']; $refund=(int)$snapshot['refund_cents'];
    $policy=PAYMONGO_REFUND_POLICY_VERSION;
    $insert->bind_param('siiiiiisi',$id,$cancellationId,$bookingId,$gross,$fee,$prior,$refund,$policy,$actorUserId);
    if (!$insert->execute()) throw new RuntimeException('The refund snapshot could not be saved.');

    $allocationInsert=$conn->prepare('INSERT INTO pm_refund_allocations (id,snapshot_id,payment_id,gross_cents,fee_cents,prior_refund_cents,refund_cents) VALUES (?,?,?,?,?,?,?)');
    if (!$allocationInsert) throw new RuntimeException('Refund allocations could not be saved.');
    foreach ($snapshot['allocations'] as $allocation) {
        $allocationId=bin2hex(random_bytes(16));
        $paymentId=(string)$allocation['payment_id']; $paymentGross=(int)$allocation['gross_cents'];
        $paymentFee=(int)$allocation['fee_cents']; $paymentPrior=(int)$allocation['prior_refund_cents']; $paymentRefund=(int)$allocation['refund_cents'];
        $allocationInsert->bind_param('sssiiii',$allocationId,$id,$paymentId,$paymentGross,$paymentFee,$paymentPrior,$paymentRefund);
        if (!$allocationInsert->execute()) throw new RuntimeException('Refund allocations could not be saved.');
    }
    $create=$conn->prepare("INSERT INTO pm_refund_attempts (id,allocation_id,correlation_note,status) VALUES (?,?,?,'ready')");
    if (!$create) throw new RuntimeException('Refund create claims could not be saved.');
    foreach ($snapshot['allocations'] as $allocation) {
        if ((int)$allocation['refund_cents']<100) continue;
        $allocationQuery=$conn->prepare('SELECT id FROM pm_refund_allocations WHERE snapshot_id=? AND payment_id=?');
        if (!$allocationQuery) throw new RuntimeException('Refund allocation lookup failed.');
        $allocationQuery->bind_param('ss',$id,$allocation['payment_id']); $allocationQuery->execute();
        $saved=$allocationQuery->get_result()->fetch_assoc();
        if (!$saved) throw new RuntimeException('Refund allocation lookup failed.');
        $attemptId=bin2hex(random_bytes(16)); $note='SV360REF-'.$attemptId;
        $create->bind_param('sss',$attemptId,$saved['id'],$note);
        if (!$create->execute() || $create->affected_rows!==1) throw new RuntimeException('Refund create claims could not be saved.');
    }
    return $id;
}

function paymongo_refund_snapshot_money(int $cents): float {
    return round($cents/100,2);
}

function paymongo_refund_mark_attempt(mysqli $conn,string $attemptId,string $status,?string $refundId=null,?string $errorCode=null,?int $updatedAt=null): void {
    if (!in_array($status,['uncertain','pending','processing','succeeded','failed','review'],true)) throw new InvalidArgumentException('Invalid PayMongo refund status.');
    $stmt=$conn->prepare('UPDATE pm_refund_attempts SET status=?,provider_refund_id=COALESCE(?,provider_refund_id),error_code=?,provider_updated_at=COALESCE(?,provider_updated_at),reconciled_at=NOW(6) WHERE id=? AND status<>\'succeeded\'');
    if (!$stmt) throw new RuntimeException('Refund status could not be saved.');
    $stmt->bind_param('sssis',$status,$refundId,$errorCode,$updatedAt,$attemptId);
    if (!$stmt->execute() || $stmt->affected_rows>1) throw new RuntimeException('Refund status could not be saved.');
}

function paymongo_refund_mark_resource(mysqli $conn,string $attemptId,array $resource): void {
    $attributes=$resource['attributes']??[];
    $status=$attributes['status']??'';
    $refundId=$resource['id']??null;
    $updatedAt=is_int($attributes['updated_at']??null)?$attributes['updated_at']:null;
    $expectedStmt=$conn->prepare('SELECT al.payment_id,al.refund_cents,at.correlation_note,at.provider_refund_id FROM pm_refund_attempts at JOIN pm_refund_allocations al ON al.id=at.allocation_id WHERE at.id=?');
    if (!$expectedStmt) throw new RuntimeException('Refund allocation could not be verified.');
    $expectedStmt->bind_param('s',$attemptId); $expectedStmt->execute();
    $expected=$expectedStmt->get_result()->fetch_assoc();
    if (!$expected || !is_string($refundId) || !in_array($status,['pending','processing','succeeded','failed'],true)
        || ($attributes['payment_id']??null)!==$expected['payment_id']
        || ($attributes['amount']??null)!==(int)$expected['refund_cents']
        || ($attributes['currency']??null)!=='PHP' || ($attributes['livemode']??null)!==false
        || ($attributes['notes']??null)!==$expected['correlation_note']
        || ($expected['provider_refund_id']!==null && $expected['provider_refund_id']!==$refundId)) {
        paymongo_refund_mark_attempt($conn,$attemptId,'review',null,'invalid_provider_resource');
        return;
    }
    paymongo_refund_mark_attempt($conn,$attemptId,$status,$refundId,null,$updatedAt);
}

/** Claim each provider create once, then perform every PayMongo request after commit. */
function paymongo_refund_admin_process(mysqli $conn,int $bookingId,int $actorId,PayMongoTestProvider $provider,bool $allowCreate=false,bool $allowRetry=false): array {
    if ($bookingId<1 || $actorId<1) throw new InvalidArgumentException('Invalid refund request.');
    $claimed=[]; $snapshotId=''; $cancellationId=0;
    $conn->begin_transaction();
    try {
        $bookingStmt=$conn->prepare('SELECT booking_status,payment_status,amount_paid FROM bookings WHERE id=? FOR UPDATE');
        if (!$bookingStmt) throw new RuntimeException('Booking could not be locked.');
        $bookingStmt->bind_param('i',$bookingId); $bookingStmt->execute();
        $booking=$bookingStmt->get_result()->fetch_assoc();
        if (!$booking) throw new RuntimeException('Booking was not found.');
        if (in_array($booking['booking_status'],['Cancelled','Completed'],true) || $booking['payment_status']==='Refunded') {
            throw new RuntimeException('This booking is no longer eligible for refund processing.');
        }
        $cancelStmt=$conn->prepare("SELECT id,reason,refund_amount,fee_deducted,fee_percent,refund_provider,refund_provider_status,refund_snapshot_id,status FROM cancellations WHERE booking_id=? AND status='Pending' ORDER BY id DESC LIMIT 1 FOR UPDATE");
        if (!$cancelStmt) throw new RuntimeException('Refund request could not be locked.');
        $cancelStmt->bind_param('i',$bookingId); $cancelStmt->execute();
        $cancellation=$cancelStmt->get_result()->fetch_assoc();
        if (!$cancellation) throw new RuntimeException('No pending refund request exists for this booking.');
        if (($cancellation['refund_provider']??null)!=='paymongo' || !preg_match('/\A[a-f0-9]{32}\z/D',$cancellation['refund_snapshot_id']??'')) {
            throw new RuntimeException('This PayMongo request predates the original-method fee preview. Reject it and ask the customer to submit a refreshed request.');
        }
        $cancellationId=(int)$cancellation['id']; $snapshotId=$cancellation['refund_snapshot_id'];
        $snapshotStmt=$conn->prepare('SELECT gross_cents FROM pm_refund_snapshots WHERE id=? AND cancellation_id=? AND booking_id=?');
        if (!$snapshotStmt) throw new RuntimeException('Refund snapshot could not be verified.');
        $snapshotStmt->bind_param('sii',$snapshotId,$cancellationId,$bookingId); $snapshotStmt->execute();
        $snapshotRow=$snapshotStmt->get_result()->fetch_assoc();
        if (!$snapshotRow || paymongo_refund_money_to_cents((string)$booking['amount_paid'])!==(int)$snapshotRow['gross_cents']) {
            throw new RuntimeException('The paid amount changed after this request. Reject it and ask the customer to submit a new preview.');
        }
        $allocationStmt=$conn->prepare('SELECT a.id,a.payment_id,a.gross_cents,a.fee_cents,a.prior_refund_cents,a.refund_cents FROM pm_refund_allocations a WHERE a.snapshot_id=? ORDER BY a.payment_id');
        if (!$allocationStmt) throw new RuntimeException('Refund allocations could not be verified.');
        $allocationStmt->bind_param('s',$snapshotId); $allocationStmt->execute();
        $allocations=$allocationStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        if (!$allocations) throw new RuntimeException('Refund allocations are missing.');
        if (!paymongo_refund_local_snapshot_matches($conn,$bookingId,['allocations'=>$allocations])) {
            throw new RuntimeException('The PayMongo payment-to-booking ledger changed. Contact staff to reconcile before refunding.');
        }

        foreach ($allocations as $allocation) {
            if ((int)$allocation['refund_cents']<100) throw new RuntimeException('A refund allocation is below the supported minimum.');
            $attemptStmt=$conn->prepare('SELECT id,status FROM pm_refund_attempts WHERE allocation_id=? ORDER BY created_at DESC,id DESC FOR UPDATE');
            if (!$attemptStmt) throw new RuntimeException('Refund attempt history could not be loaded.');
            $attemptStmt->bind_param('s',$allocation['id']); $attemptStmt->execute();
            $attempts=$attemptStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $succeeded=0; $active=false; $failedCount=0; $readyId=null;
            foreach ($attempts as $attempt) {
                if ($attempt['status']==='succeeded') $succeeded++;
                if (in_array($attempt['status'],['creating','uncertain','pending','processing','review'],true)) $active=true;
                if ($attempt['status']==='failed') $failedCount++;
                if ($attempt['status']==='ready' && $readyId===null) $readyId=(string)$attempt['id'];
            }
            if ($succeeded>1) throw new RuntimeException('Duplicate successful PayMongo refunds need staff reconciliation.');
            if ($succeeded===1 || $active) continue;
            if (!$readyId && $allowRetry && $failedCount>0) {
                $readyId=bin2hex(random_bytes(16)); $note='SV360REF-'.$readyId; $allocationId=(string)$allocation['id'];
                $newAttempt=$conn->prepare("INSERT INTO pm_refund_attempts (id,allocation_id,correlation_note,status) VALUES (?,?,?,'ready')");
                if (!$newAttempt) throw new RuntimeException('A safe refund retry could not be recorded.');
                $newAttempt->bind_param('sss',$readyId,$allocationId,$note);
                if (!$newAttempt->execute()) throw new RuntimeException('A safe refund retry could not be recorded.');
            }
            if (!$readyId || !$allowCreate) continue;
            $claim=$conn->prepare("UPDATE pm_refund_attempts SET status='creating',create_started_at=NOW(6),error_code=NULL WHERE id=? AND status='ready'");
            if (!$claim) throw new RuntimeException('Refund create could not be claimed.');
            $claim->bind_param('s',$readyId);
            if (!$claim->execute() || $claim->affected_rows!==1) throw new RuntimeException('Refund create was already claimed.');
            $claimed[]=[
                'id'=>$readyId,'payment_id'=>(string)$allocation['payment_id'],'gross_cents'=>(int)$allocation['gross_cents'],
                'fee_cents'=>(int)$allocation['fee_cents'],'prior_refund_cents'=>(int)$allocation['prior_refund_cents'],
                'refund_cents'=>(int)$allocation['refund_cents'],'correlation_note'=>'SV360REF-'.$readyId,
                'retry'=>$failedCount>0,
            ];
        }
        if ($claimed) {
            $audit=$conn->prepare("INSERT INTO audit_logs (user_id,module,action,ip_address,event_type,entity_type,entity_id,details_json) VALUES (?,'Refunds',?,?, 'booking.refund_attempt_claimed','booking',?,?)");
            if (!$audit) throw new RuntimeException('Refund attempt audit could not be saved.');
            $ip=function_exists('request_client_ip')?request_client_ip():'System';
            foreach ($claimed as $attempt) {
                $action=($attempt['retry']?'Retry':'Create').' PayMongo refund attempt for booking '.$bookingId;
                $entity=(string)$bookingId;
                $details=json_encode([
                    'booking_id'=>$bookingId,'cancellation_id'=>$cancellationId,'snapshot_id'=>$snapshotId,
                    'attempt_id'=>$attempt['id'],'payment_id'=>$attempt['payment_id'],
                    'refund_cents'=>$attempt['refund_cents'],'retry'=>$attempt['retry'],
                ],JSON_THROW_ON_ERROR);
                $audit->bind_param('issss',$actorId,$action,$ip,$entity,$details);
                if (!$audit->execute()) throw new RuntimeException('Refund attempt audit could not be saved.');
            }
        }
        $providerStatus=$claimed?'creating':(string)($cancellation['refund_provider_status']??'pending');
        $statusUpdate=$conn->prepare('UPDATE cancellations SET refund_provider_status=? WHERE id=? AND status=\'Pending\'');
        if (!$statusUpdate) throw new RuntimeException('Refund request status could not be saved.');
        $statusUpdate->bind_param('si',$providerStatus,$cancellationId); $statusUpdate->execute();
        if (!$conn->commit()) throw new RuntimeException('Refund create claim could not be committed.');
    } catch (Throwable $e) { try { $conn->rollback(); } catch (Throwable) {} throw $e; }

    foreach ($claimed as $attempt) {
        try {
            $providerRefunds=$provider->refundsForPayment($attempt['payment_id']);
            $succeededAmount=0;
            foreach ($providerRefunds as $knownRefund) {
                $knownStatus=$knownRefund['attributes']['status'];
                if (in_array($knownStatus,['pending','processing'],true)) {
                    throw new PayMongoRefundOutcomeUnknown('A PayMongo refund is already in progress for this payment.');
                }
                if ($knownStatus==='succeeded') $succeededAmount+=(int)$knownRefund['attributes']['amount'];
            }
            if ($succeededAmount!==(int)$attempt['prior_refund_cents']
                || $succeededAmount+(int)$attempt['refund_cents']>(int)$attempt['gross_cents']-(int)$attempt['fee_cents']) {
                throw new RuntimeException('PayMongo refund history changed after this preview. Reject the request and ask the customer to review a new amount.');
            }
            $resource=$provider->createRefund($attempt['payment_id'],(int)$attempt['refund_cents'],$attempt['correlation_note']);
            paymongo_refund_mark_resource($conn,$attempt['id'],$resource);
        } catch (PayMongoRefundRejected $e) {
            paymongo_refund_mark_attempt($conn,$attempt['id'],'failed',null,$e->providerCode);
        } catch (PayMongoRefundOutcomeUnknown $e) {
            paymongo_refund_mark_attempt($conn,$attempt['id'],'uncertain',null,'provider_outcome_unknown');
        } catch (Throwable $e) {
            // A non-provider local check can stop creation; after the claim, we
            // preserve an uncertain fence rather than making the claim reusable.
            paymongo_refund_mark_attempt($conn,$attempt['id'],'uncertain',null,'reconciliation_required');
            error_log('PayMongo refund create deferred: booking='.(int)$bookingId.' type='.get_class($e));
        }
    }

    // Reconcile every pre-existing in-flight claim by provider refund ID or by
    // the stable note. A missing match stays uncertain; POST is never repeated.
    $lookup=$conn->prepare('SELECT at.id,at.status,at.provider_refund_id,at.correlation_note,al.payment_id FROM pm_refund_attempts at JOIN pm_refund_allocations al ON al.id=at.allocation_id WHERE al.snapshot_id=? AND at.status IN (\'creating\',\'uncertain\',\'pending\',\'processing\')');
    if (!$lookup) throw new RuntimeException('Refund reconciliation could not be loaded.');
    $lookup->bind_param('s',$snapshotId); $lookup->execute();
    foreach ($lookup->get_result()->fetch_all(MYSQLI_ASSOC) as $attempt) {
        try {
            if ($attempt['provider_refund_id']) {
                $resource=$provider->retrieveRefund((string)$attempt['provider_refund_id']);
                paymongo_refund_mark_resource($conn,(string)$attempt['id'],$resource);
            } else {
                $matches=array_values(array_filter($provider->refundsForPayment((string)$attempt['payment_id']),static fn($refund)=>($refund['attributes']['notes']??null)===$attempt['correlation_note']));
                if (count($matches)>1) {
                    paymongo_refund_mark_attempt($conn,(string)$attempt['id'],'review',null,'duplicate_correlation_match');
                } elseif (count($matches)===1) {
                    paymongo_refund_mark_resource($conn,(string)$attempt['id'],$matches[0]);
                } else {
                    paymongo_refund_mark_attempt($conn,(string)$attempt['id'],'uncertain',null,'create_outcome_unresolved');
                }
            }
        } catch (Throwable $e) {
            error_log('PayMongo refund reconciliation deferred: booking='.(int)$bookingId.' type='.get_class($e));
        }
    }

    $conn->begin_transaction();
    try {
        $lock=$conn->prepare('SELECT id FROM bookings WHERE id=? FOR UPDATE');
        if (!$lock) throw new RuntimeException('Booking could not be locked.');
        $lock->bind_param('i',$bookingId); $lock->execute();
        if (!$lock->get_result()->fetch_assoc()) throw new RuntimeException('Booking could not be locked.');
        $cancelLock=$conn->prepare("SELECT id,reason,refund_amount,fee_deducted,fee_percent,refund_provider_status,refund_snapshot_id FROM cancellations WHERE id=? AND booking_id=? AND status='Pending' FOR UPDATE");
        if (!$cancelLock) throw new RuntimeException('Refund request could not be locked.');
        $cancelLock->bind_param('ii',$cancellationId,$bookingId); $cancelLock->execute();
        $current=$cancelLock->get_result()->fetch_assoc();
        if (!$current) throw new RuntimeException('Refund request changed during provider reconciliation.');
        $summary=$conn->prepare("SELECT SUM(status='succeeded') AS succeeded,SUM(status IN ('pending','processing')) AS in_flight,SUM(status IN ('uncertain','creating','review')) AS unresolved,SUM(status='failed') AS failed FROM pm_refund_attempts at JOIN pm_refund_allocations al ON al.id=at.allocation_id WHERE al.snapshot_id=?");
        if (!$summary) throw new RuntimeException('Refund status could not be reconciled.');
        $summary->bind_param('s',$snapshotId); $summary->execute(); $counts=$summary->get_result()->fetch_assoc();
        $allocCountStmt=$conn->prepare("SELECT COUNT(*) AS total,SUM((SELECT COUNT(*) FROM pm_refund_attempts sa WHERE sa.allocation_id=al.id AND sa.status='succeeded')=1) AS exact_success,SUM((SELECT COUNT(*) FROM pm_refund_attempts da WHERE da.allocation_id=al.id AND da.status='succeeded')>1) AS duplicates FROM pm_refund_allocations al WHERE snapshot_id=? AND refund_cents>=100");
        if (!$allocCountStmt) throw new RuntimeException('Refund allocations could not be checked.');
        $allocCountStmt->bind_param('s',$snapshotId); $allocCountStmt->execute(); $allocCounts=$allocCountStmt->get_result()->fetch_assoc();
        $allocCount=(int)$allocCounts['total'];
        $allSucceeded=$allocCount>0 && (int)$allocCounts['exact_success']===$allocCount
            && (int)($allocCounts['duplicates']??0)===0 && (int)($counts['in_flight']??0)===0
            && (int)($counts['unresolved']??0)===0;
        $providerStatus=$allSucceeded?'succeeded':((int)$counts['unresolved']>0?'uncertain':((int)$counts['in_flight']>0?'processing':((int)$counts['succeeded']>0 && (int)$counts['failed']>0?'partially_refunded':((int)$counts['failed']>0?'failed':'pending'))));
        $updateStatus=$conn->prepare('UPDATE cancellations SET refund_provider_status=? WHERE id=? AND status=\'Pending\'');
        if (!$updateStatus) throw new RuntimeException('Refund status could not be saved.');
        $updateStatus->bind_param('si',$providerStatus,$cancellationId); $updateStatus->execute();
        $processed=false;
        if ($allSucceeded) {
            require_once __DIR__.'/booking_lifecycle.php';
            require_once __DIR__.'/manual_payment.php';
            manual_payment_reject_pending_for_terminal_booking($conn,$bookingId,$actorId,'Booking was cancelled after all PayMongo refund allocations succeeded.');
            $bookingUpdate=$conn->prepare("UPDATE bookings SET booking_status='Cancelled',payment_status='Refunded',payment_due_at=NULL,updated_at=NOW() WHERE id=?");
            if (!$bookingUpdate) throw new RuntimeException('Booking refund state could not be saved.');
            $bookingUpdate->bind_param('i',$bookingId); if (!$bookingUpdate->execute()) throw new RuntimeException('Booking refund state could not be saved.');
            $reference='paymongo:'.$snapshotId;
            $cancelUpdate=$conn->prepare("UPDATE cancellations SET status='Processed',refund_provider_status='succeeded',refund_transaction_id=? WHERE id=? AND status='Pending'");
            if (!$cancelUpdate) throw new RuntimeException('Refund completion could not be saved.');
            $cancelUpdate->bind_param('si',$reference,$cancellationId);
            if (!$cancelUpdate->execute() || $cancelUpdate->affected_rows!==1) throw new RuntimeException('Refund completion could not be saved.');
            record_cancellation_history($conn,$bookingId,$cancellationId,'processed',(string)$current['reason'], (float)$current['refund_amount'],(float)$current['fee_deducted'],(float)$current['fee_percent'],null,$actorId);
            $audit=$conn->prepare("INSERT INTO audit_logs (user_id,module,action,ip_address,event_type,entity_type,entity_id,details_json) VALUES (?,'Refunds',? ,?,'booking.refund_processed','booking',?,?)");
            if (!$audit) throw new RuntimeException('Refund audit entry could not be saved.');
            $action='PayMongo original-method refund succeeded for booking '.$bookingId;
            $ip=function_exists('request_client_ip')?request_client_ip():'System';
            $entity=(string)$bookingId; $details=json_encode(['booking_id'=>$bookingId,'cancellation_id'=>$cancellationId,'snapshot_id'=>$snapshotId,'refund_amount'=>(float)$current['refund_amount'],'fee_deducted'=>(float)$current['fee_deducted'],'provider'=>'paymongo'],JSON_THROW_ON_ERROR);
            $audit->bind_param('issss',$actorId,$action,$ip,$entity,$details);
            if (!$audit->execute()) throw new RuntimeException('Refund audit entry could not be saved.');
            if (function_exists('realtime_enqueue_event')) realtime_enqueue_event($conn,'admin','booking.updated',['booking_id'=>$bookingId,'action'=>'refund','provider'=>'paymongo']);
            $processed=true;
        }
        if (!$conn->commit()) throw new RuntimeException('Refund reconciliation could not be committed.');
        return ['processed'=>$processed,'status'=>$providerStatus,'cancellation_id'=>$cancellationId,'refund_amount'=>(float)$current['refund_amount'],'fee_deducted'=>(float)$current['fee_deducted']];
    } catch (Throwable $e) { try { $conn->rollback(); } catch (Throwable) {} throw $e; }
}
