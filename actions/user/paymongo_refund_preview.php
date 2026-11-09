<?php
require_once __DIR__.'/../../includes/session_init.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__.'/../../config/db_connect.php';
require_once __DIR__.'/../../includes/session_account_guard.php';
require_once __DIR__.'/../../includes/paymongo_refunds.php';

if (($_SERVER['REQUEST_METHOD']??'')!=='POST' || ($_SESSION['role']??'')!=='customer') {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Session validation failed.']); exit;
}
$csrf=$_SERVER['HTTP_X_CSRF_TOKEN']??'';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'],$csrf)) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Session validation failed.']); exit;
}
if (!session_account_require_active_json($conn,['customer'],['success'=>false,'message'=>'Sign in again.'],['success'=>false,'message'=>'Account unavailable.'])) exit;

$failureStage='request_validation';
function paymongo_refund_preview_failure_message(Throwable $error): string {
    if ($error instanceof PayMongoRefundVerificationFailure) {
        if ($error->diagnosticCode === 'payment_request_transport') {
            return 'PayMongo could not be reached to verify the original payment. No refund request was submitted. Retry the fee check later.';
        }
        if (str_starts_with($error->diagnosticCode, 'refund_history_')) {
            return 'PayMongo could not confirm existing refunds on this payment. No refund request was submitted. Retry verification later or contact staff.';
        }
        return 'PayMongo could not verify this original payment. No refund request was submitted. Retry verification or contact staff.';
    }

    return match ($error->getMessage()) {
        'A PayMongo payment is not authoritatively linked to this booking. Contact staff to reconcile it.' => 'This payment could not be safely linked to the booking. No refund request was submitted. Contact staff to reconcile it.',
        'A PayMongo payment needs staff reconciliation before refunding.' => 'A payment exception needs staff review before a refund can be requested. No refund request was submitted.',
        'This booking has mixed payment methods and needs staff review before a refund.' => 'This booking has more than one payment method and needs staff review before a refund can be requested.',
        'The PayMongo payment history does not match the booking total. Contact staff to reconcile the payments.' => 'The recorded PayMongo payments do not match the booking total. No refund request was submitted. Contact staff to reconcile the booking.',
        'A PayMongo refund is still in progress and must be reconciled before another request.' => 'A refund is already in progress with PayMongo. Wait for staff to reconcile it before submitting another request.',
        'No PayMongo refund amount remains after the actual fees and prior refunds.' => 'No refundable balance remains after PayMongo fees and prior refunds. Contact staff if you think this is incorrect.',
        default => 'PayMongo could not verify this refund preview. No refund request was submitted. Retry verification or contact staff with the support reference.',
    };
}
try {
    $raw=file_get_contents('php://input',false,null,0,4097);
    if (!is_string($raw) || strlen($raw)>4096) throw new InvalidArgumentException('Invalid request.');
    $data=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
    $bookingId=filter_var($data['booking_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if ($bookingId===false) throw new InvalidArgumentException('Invalid booking.');
    $failureStage='booking_ownership';
    $owner=$conn->prepare('SELECT b.id,b.amount_paid,b.booking_status,c.id AS customer_id FROM bookings b JOIN customers c ON c.id=b.customer_id WHERE b.id=? AND c.user_id=?');
    if (!$owner) throw new RuntimeException('Refund preview is unavailable.');
    $userId=(int)$_SESSION['user_id'];
    $owner->bind_param('ii',$bookingId,$userId); $owner->execute();
    $booking=$owner->get_result()->fetch_assoc();
    if (!$booking) throw new InvalidArgumentException('Booking not found.');
    $failureStage='payment_ownership';
    if (!paymongo_refund_is_booking($conn,(int)$bookingId)) {
        echo json_encode(['success'=>true,'provider'=>'manual','paid'=>(string)$booking['amount_paid']]); exit;
    }
    $failureStage='provider_mode_gate';
    require_once __DIR__.'/../../includes/paymongo_native.php';
    paymongo_native_gate();
    $failureStage='refund_snapshot_verification';
    $snapshot=paymongo_refund_prepare_snapshot($conn,(int)$bookingId,paymongo_native_provider());
    if (($snapshot['provider']??'')!=='paymongo') throw new RuntimeException('PayMongo refund details could not be verified.');
    echo json_encode([
        'success'=>true,'provider'=>'paymongo','paid'=>paymongo_refund_cents_to_money((int)$snapshot['gross_cents']),
        'fee'=>paymongo_refund_cents_to_money((int)$snapshot['fee_cents']),
        'prior_refund'=>paymongo_refund_cents_to_money((int)$snapshot['prior_refund_cents']),
        'refund'=>paymongo_refund_cents_to_money((int)$snapshot['refund_cents']),
        'fingerprint'=>$snapshot['fingerprint'],'policy_version'=>PAYMONGO_REFUND_POLICY_VERSION,
        'message'=>'Approved refunds return to the original payment method. The actual PayMongo processing fee is deducted once; timing depends on the provider.'
    ]);
} catch (Throwable $e) {
    http_response_code(422);
    if ($e instanceof InvalidArgumentException) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        exit;
    }
    $reference=bin2hex(random_bytes(8));
    $category=$e instanceof PayMongoRefundVerificationFailure?$e->diagnosticCode:match ($e->getMessage()) {
        'A PayMongo payment is not authoritatively linked to this booking. Contact staff to reconcile it.' => 'payment_receipt_unlinked',
        'A PayMongo payment needs staff reconciliation before refunding.' => 'payment_receipt_exception',
        'This booking has mixed payment methods and needs staff review before a refund.' => 'mixed_payment_methods',
        'The PayMongo payment history does not match the booking total. Contact staff to reconcile the payments.' => 'booking_payment_total_mismatch',
        'A PayMongo refund is still in progress and must be reconciled before another request.' => 'refund_in_progress',
        'No PayMongo refund amount remains after the actual fees and prior refunds.' => 'no_refundable_balance',
        default => 'unclassified',
    };
    error_log('PayMongo refund preview failure: reference='.$reference.' stage='.$failureStage.' category='.$category.' exception='.get_class($e));
    echo json_encode([
        'success'=>false,
        'message'=>paymongo_refund_preview_failure_message($e),
        'support_reference'=>$reference,
        'diagnostic_code'=>$failureStage.':'.$category,
    ]);
}
