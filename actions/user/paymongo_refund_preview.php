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
    $category=$e instanceof PayMongoRefundVerificationFailure?$e->diagnosticCode:'unclassified';
    error_log('PayMongo refund preview failure: reference='.$reference.' stage='.$failureStage.' category='.$category.' exception='.get_class($e));
    echo json_encode([
        'success'=>false,
        'message'=>'PayMongo could not verify this refund preview. Retry verification or contact staff with the support reference.',
        'support_reference'=>$reference,
        'diagnostic_code'=>$failureStage.':'.$category,
    ]);
}
