<?php
declare(strict_types=1);
require_once __DIR__.'/paymongo_mode.php';
require_once __DIR__.'/../prototype/paymongo/hosted_flow.php';
function paymongo_native_store(mysqli $conn): PayMongoPrototype {
    if(!paymongo_capstone_enabled())throw new RuntimeException('Checkout unavailable.');
    require_once __DIR__.'/database_backup.php';
    if(database_backup_env('BACKUP_DIR')==='')throw new RuntimeException('Private restore fencing is required for checkout.');
    database_backup_authentication_epoch();
    $name=$conn->query('SELECT DATABASE() name')->fetch_assoc()['name'];
    if($name!==($_ENV['PAYMONGO_CAPSTONE_DB_NAME']??getenv('PAYMONGO_CAPSTONE_DB_NAME')) || !$conn->query("SELECT purpose FROM pm_capstone_identity WHERE purpose='capstone-test-v1'")->fetch_assoc())throw new RuntimeException('Isolated capstone database not configured.');
    $conn->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    return new PayMongoPrototype($conn,true);
}
function paymongo_native_provider(): PayMongoTestProvider {
    if(!paymongo_capstone_enabled())throw new RuntimeException('Provider requests require capstone test mode.');
    $base=rtrim((string)($_ENV['APP_BASE_URL']??getenv('APP_BASE_URL')),'/');
    return new PayMongoTestProvider((string)($_ENV['PAYMONGO_SECRET_KEY']??getenv('PAYMONGO_SECRET_KEY')),null,$base.'/user_dashboard.php',PayMongoTestProvider::paymentMethods($_ENV['PAYMONGO_TEST_PAYMENT_METHODS']??getenv('PAYMONGO_TEST_PAYMENT_METHODS')?:'card,gcash,paymaya'));
}
function paymongo_native_expire_due(mysqli $conn, ?int $customer=null, int $limit=20): int {
    paymongo_native_gate();
    $flow=new PayMongoHostedFlow(paymongo_native_store($conn),paymongo_native_provider());
    return $flow->expireDue($customer,$limit);
}
function paymongo_native_gate():void {
    require_once __DIR__.'/database_backup.php';
    database_backup_enforce_web_write_gate();
}
function paymongo_native_checkout_failure(Throwable $error): array {
    $known = [
        'Review and accept the updated PayMongo refund terms before paying.' => ['updated_terms_required', 'Review and accept the current PayMongo payment terms before opening checkout.'],
        'An accepted, confirmed Event Hall quote is required.' => ['quote_acceptance_required', 'Review and accept the finalized Event Hall quotation before paying.'],
        'The Event Hall 24-hour payment deadline expired.' => ['quote_deadline_expired', 'The Event Hall payment deadline has passed. Contact staff if you believe this is incorrect.'],
        'A received payment needs resolution. Do not pay again.' => ['payment_review_required', 'A received payment is still being reconciled. Do not pay again; contact staff to review the booking.'],
        'Checkout predates a restore. Recover or close it before paying again.' => ['checkout_restore_review_required', 'An earlier checkout needs review before another payment can start. Contact staff to check its status.'],
        'This booking cannot accept checkout.' => ['booking_not_payable', 'This booking is not currently eligible for payment. Refresh its status and contact staff if the balance is still due.'],
        'The original checkout hold expired. No new payment session was started.' => ['checkout_window_expired', 'The original payment window has expired. Refresh the booking status and contact staff if a balance is still due.'],
        'No balance is due.' => ['no_balance_due', 'This booking has no remaining balance to pay. Refresh the booking status.'],
        'Invalid payment scheme.' => ['payment_scheme_unavailable', 'The booking payment schedule could not be verified. Contact staff before trying again.'],
        'Invalid money value.' => ['booking_total_unavailable', 'The booking balance could not be verified. Contact staff before trying again.'],
        'Booking not found or access denied.' => ['booking_unavailable', 'This booking could not be verified for your account. Refresh the page or contact staff.'],
        'Private restore fencing is required for checkout.' => ['restore_fencing_unavailable', 'The payment security check is unavailable. Contact staff before trying again.'],
        'Isolated capstone database not configured.' => ['checkout_database_unavailable', 'The payment service is not available for this booking. Contact staff with the support reference.'],
        'Authentication generation is missing; explicit initialization is required.' => ['restore_authority_uninitialized', 'The payment security check is not initialized. Contact staff before trying again.'],
        'Authentication generation is invalid.' => ['restore_authority_invalid', 'The payment security check needs staff attention. Contact staff with the support reference.'],
        'Authentication generation is unreadable.' => ['restore_authority_unreadable', 'The payment security check needs staff attention. Contact staff with the support reference.'],
        'Unable to load booking resources.' => ['booking_resources_unavailable', 'The booking resources could not be checked. Contact staff before trying again.'],
        'This booking is not ready to accept payment.' => ['booking_payment_details_unavailable', 'The booking payment details could not be verified. Contact staff before trying again.'],
    ];
    return $known[$error->getMessage()] ?? [
        'checkout_verification_failed',
        'Checkout could not be verified. Refresh the booking status before trying again. Do not pay again while the payment outcome is uncertain.',
    ];
}
function paymongo_native_checkout_diagnostic(Throwable $error, string $stage): string {
    [$code] = paymongo_native_checkout_failure($error);
    if ($code !== 'checkout_verification_failed') return $code;
    if ($error instanceof mysqli_sql_exception) {
        $sqlState = method_exists($error, 'getSqlState') ? strtoupper((string)$error->getSqlState()) : '';
        if (!preg_match('/\A[A-Z0-9]{5}\z/D', $sqlState)) $sqlState = 'unknown';
        $driverCode = is_numeric($error->getCode()) ? (string)(int)$error->getCode() : 'unknown';
        return $stage . ':database:' . $driverCode . ':' . strtolower($sqlState);
    }
    $class = match (true) {
        $error instanceof JsonException => 'json',
        $error instanceof TypeError => 'type',
        $error instanceof Error => 'engine',
        $error instanceof RuntimeException => 'runtime',
        default => 'exception',
    };
    $fingerprint = substr(hash('sha256', get_class($error) . ':' . $error->getMessage()), 0, 12);
    return $stage . ':' . $class . ':' . $fingerprint;
}
function paymongo_native_customer_action(string $action):void {
    require_once __DIR__.'/session_init.php';
    header('Content-Type: application/json');header('Cache-Control: no-store');
    if(!in_array($action,['checkout','recover','cancel'],true)){http_response_code(404);echo '{"error":"Checkout action unavailable"}';return;}
    $failureStage='request_validation';
    try {
        paymongo_native_gate();
        if(!paymongo_capstone_enabled()){http_response_code(410);echo '{"error":"Checkout unavailable"}';return;}
        if($_SERVER['REQUEST_METHOD']!=='POST' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'],$_SERVER['HTTP_X_CSRF_TOKEN']??'')){http_response_code(403);echo '{"error":"Session validation failed"}';return;}
        $failureStage='account_validation';
        require __DIR__.'/../config/db_connect.php';require_once __DIR__.'/session_account_guard.php';
        if(!session_account_require_active_json($conn,['customer'],['error'=>'Sign in'],['error'=>'Account unavailable']))return;
        $failureStage='customer_lookup';
        $s=$conn->prepare('SELECT id FROM customers WHERE user_id=?');$s->bind_param('i',$_SESSION['user_id']);$s->execute();$customer=(int)($s->get_result()->fetch_assoc()['id']??0);
        $failureStage='checkout_initialization';
        $store=paymongo_native_store($conn);
        // Test injection is possible only from a CLI test server bootstrap, never from request data/config.
        $provider=defined('PAYMONGO_NATIVE_FIXTURE') && PHP_SAPI==='cli-server' ? $GLOBALS['paymongo_native_fixture'] : paymongo_native_provider();
        $flow=new PayMongoHostedFlow($store,$provider);
        $failureStage='checkout_start';
        $raw=file_get_contents('php://input',false,null,0,8193);if(strlen($raw)>8192)throw new RuntimeException('Request too large');
        $data=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
        $attempt=(string)($data['attempt']??'');
        $result=match($action){
            'checkout'=>$flow->start((int)($data['booking']??0),$customer,($data['policy_consent']??null)===true),
            'cancel'=>['message'=>$flow->cancelCheckout($attempt,$customer)],
            default=>['message'=>$flow->recover($attempt,$customer)],
        };
        if(defined('PAYMONGO_NATIVE_FIXTURE') && PHP_SAPI==='cli-server' && isset($result['checkout_url']))$result['checkout_url']='/local-hosted.php?attempt='.$result['attempt'];
        echo json_encode($result);
    }catch(PayMongoCheckoutConflictException $e){http_response_code(409);echo json_encode(['error'=>$e->getMessage(),'code'=>'dates_unavailable']);}
    catch(Throwable $e){
        $reference=bin2hex(random_bytes(8));
        [$code,$message]=paymongo_native_checkout_failure($e);
        $diagnosticCode=paymongo_native_checkout_diagnostic($e,$failureStage);
        error_log('PayMongo customer checkout failure: reference='.$reference.' diagnostic='.$diagnosticCode.' exception='.get_class($e));
        http_response_code(422);
        echo json_encode(['error'=>$message,'code'=>$code,'diagnostic_code'=>$diagnosticCode,'support_reference'=>$reference],JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
