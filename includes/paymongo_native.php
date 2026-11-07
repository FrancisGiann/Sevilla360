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
function paymongo_native_customer_action(string $action):void {
    require_once __DIR__.'/session_init.php';
    header('Content-Type: application/json');header('Cache-Control: no-store');
    try {
        paymongo_native_gate();
        if(!paymongo_capstone_enabled()){http_response_code(410);echo '{"error":"Checkout unavailable"}';return;}
        if($_SERVER['REQUEST_METHOD']!=='POST' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'],$_SERVER['HTTP_X_CSRF_TOKEN']??'')){http_response_code(403);echo '{"error":"Session validation failed"}';return;}
        require __DIR__.'/../config/db_connect.php';require_once __DIR__.'/session_account_guard.php';
        if(!session_account_require_active_json($conn,['customer'],['error'=>'Sign in'],['error'=>'Account unavailable']))return;
        $s=$conn->prepare('SELECT id FROM customers WHERE user_id=?');$s->bind_param('i',$_SESSION['user_id']);$s->execute();$customer=(int)($s->get_result()->fetch_assoc()['id']??0);
        $store=paymongo_native_store($conn);
        // Test injection is possible only from a CLI test server bootstrap, never from request data/config.
        $provider=defined('PAYMONGO_NATIVE_FIXTURE') && PHP_SAPI==='cli-server' ? $GLOBALS['paymongo_native_fixture'] : paymongo_native_provider();
        $flow=new PayMongoHostedFlow($store,$provider);
        $raw=file_get_contents('php://input',false,null,0,8193);if(strlen($raw)>8192)throw new RuntimeException('Request too large');
        $data=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
        $result=$action==='checkout'?$flow->start((int)($data['booking']??0),$customer,($data['policy_consent']??null)===true):['message'=>$flow->recover((string)($data['attempt']??''),$customer,$action==='cancel')];
        if(defined('PAYMONGO_NATIVE_FIXTURE') && PHP_SAPI==='cli-server' && isset($result['checkout_url']))$result['checkout_url']='/local-hosted.php?attempt='.$result['attempt'];
        echo json_encode($result);
    }catch(Throwable $e){http_response_code(422);echo '{"error":"Checkout could not complete. Recover the existing checkout before paying again."}';}
}
