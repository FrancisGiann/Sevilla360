<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli-server' || !in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)
    || ($_SERVER['HTTP_HOST']??'')!=='127.0.0.1:8787') {http_response_code(403);exit;}
define('SEVILLA_PM_FULL_APP',true);
// This harness never instantiates the real provider transport or loads existing ENV.
$root=dirname(__DIR__,2);$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
// Prevent CDN/analytics/provider requests if this harness is opened in a browser.
header("Content-Security-Policy: default-src 'self' data:; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
// Translate only this harness's synthetic maintenance control into the real gate.
require_once $root.'/config/env.php';
$marker=$_ENV['BACKUP_DIR'].'/maintenance.json';
if(is_file(getenv('SEVILLA_PM_TEST_MAINTENANCE')?:'/nonexistent'))file_put_contents($marker,json_encode(['active'=>true,'job_id'=>'job-'.str_repeat('f',32).'.json','phase'=>'Synthetic maintenance']));
elseif(is_file($marker))unlink($marker);
if ($path==='/test-session') {
    require_once $root.'/includes/session_init.php';
    if (empty($_SESSION['logged_in'])) {http_response_code(401);exit('{}');}
    header('Content-Type: application/json');echo json_encode(['csrf'=>$_SESSION['csrf_token']]);exit;
}
$routes=['/actions/user/pay_existing.php'=>'checkout','/actions/user/sync_payment.php'=>'recover','/actions/user/close_paymongo_checkout.php'=>'cancel'];
if($path==='/actions/bookings/paymongo_webhook.php'){require $root.$path;exit;}
if(isset($routes[$path])) {
    require_once $root.'/config/db_connect.php';
    require_once __DIR__.'/hosted_flow.php';require_once __DIR__.'/adapter_fixture.php';
    define('PAYMONGO_NATIVE_FIXTURE',true);
    $GLOBALS['paymongo_native_fixture']=pm_fixture_provider(new PayMongoPrototype($conn,true));
    $file=$root.$path;chdir(dirname($file));require $file;exit;
}
if(isset($routes[$path]) || $path==='/test-settle' || $path==='/test-state' || $path==='/test-return'){
    require_once $root.'/includes/session_init.php';require_once $root.'/config/db_connect.php';require_once $root.'/includes/session_account_guard.php';
    header('Content-Type: application/json');
    if(!session_account_require_active_json($conn,['customer'],['error'=>'Sign in.'],['error'=>'Account unavailable.']))exit;
    $q=$conn->prepare('SELECT id FROM customers WHERE user_id=?');$q->bind_param('i',$_SESSION['user_id']);$q->execute();$customer=(int)($q->get_result()->fetch_assoc()['id']??0);
    require_once __DIR__.'/hosted_flow.php';require_once __DIR__.'/adapter_fixture.php';
    $store=new PayMongoPrototype($conn,true);$flow=new PayMongoHostedFlow($store,pm_fixture_provider($store));$sim=new PayMongoSimulator($store);
    if($path==='/test-state'){
        echo json_encode(['bookings'=>$store->query('SELECT id,reference_no,booking_status,payment_status,total_amount,amount_paid FROM bookings WHERE customer_id=?',[$customer])->fetch_all(MYSQLI_ASSOC),
            'payments'=>$store->query('SELECT p.* FROM payments p JOIN bookings b ON b.id=p.booking_id WHERE b.customer_id=?',[$customer])->fetch_all(MYSQLI_ASSOC)]);exit;
    }
    try {
        if($_SERVER['REQUEST_METHOD']!=='POST' || !hash_equals($_SESSION['csrf_token']??'',$_SERVER['HTTP_X_CSRF_TOKEN']??'') || empty($_SESSION['csrf_token'])){http_response_code(403);exit('{"error":"Invalid CSRF"}');}
        $input=json_decode(file_get_contents('php://input'),true,16,JSON_THROW_ON_ERROR);$attempt=(string)($input['attempt']??'');
        if($path==='/test-settle'){
            $a=$store->row('SELECT * FROM pm_attempts WHERE id=?',[$attempt]);if(!$a)throw new RuntimeException('Unknown attempt.');
            $store->atomic(fn()=>$store->booking((int)$a['booking_id'],$customer));
            $r=$sim->settle($attempt,!empty($input['allow_late'])); // Explicit adversarial fake-only test control.
            echo json_encode(['message'=>!empty($input['withhold'])?'withheld':$sim->deliver($r)]);exit;
        }
        $action=$routes[$path]??'recover';
        $response=$action==='checkout'?$flow->start((int)($input['booking']??0),$customer):['message'=>$flow->recover($attempt,$customer,$action==='cancel')];
        if(isset($response['checkout_url']))$response['checkout_url']='/local-hosted.php?attempt='.$response['attempt'];
        echo json_encode($response);
    }catch(Throwable $e){http_response_code(422);echo json_encode(['error'=>$e->getMessage()]);}
    exit;
}
if($path==='/')$path='/auth.php';
// Only selected original application entrypoints and static code; no generic PHP/filesystem exposure.
$allowed=['/fixture-staff-login.php','/admin_dashboard.php','/actions/user/submit_manual_payment.php','/actions/admin/review_manual_payment.php','/actions/admin/update_booking_status.php','/actions/admin/get_booking_details.php','/actions/admin/get_bookings_page.php','/actions/user/request_cancel.php','/auth.php','/booking.php','/user_dashboard.php','/local-hosted.php','/actions/auth/login_process.php','/actions/auth/logout.php','/actions/auth/session_status.php','/actions/bookings/lock_dates.php','/actions/bookings/submit_online.php','/actions/bookings/get_occupied_dates.php','/actions/user/get_my_booking_details.php'];
// PayMongo mode has no staff payment approval or manual ledger-write action.
if ($path === '/actions/admin/update_booking_status.php') {
    $action = json_decode(file_get_contents('php://input'), true)['action'] ?? '';
    if (!in_array($action, ['finalize_event_invoice','refund','reject_refund'], true)) {
        http_response_code(403); header('Content-Type: application/json');
        exit('{"success":false,"message":"Manual payment confirmation is disabled in local PayMongo mode."}');
    }
}
if(in_array($path,$allowed,true)){
    $file=$root.$path;chdir(dirname($file));$_SERVER['SCRIPT_NAME']=$path;
    // Emit opt-in only in this guarded synthetic runtime, without changing authentication.
    if(in_array($path,['/booking.php','/user_dashboard.php'],true))ob_start(static fn($html)=>str_replace('<head>','<head><meta name="sevilla-paymongo-local" content="enabled">',$html));
    require $file;exit;
}
if(in_array($path, ['/assets/js/manual_payment.js','/assets/css/manual_payment.css'], true)){http_response_code(404);exit;}
if(preg_match('~\A/assets/(?:js|css)/[A-Za-z0-9_./-]+\.(js|css)\z~D',$path,$m) && !str_contains($path,'..') && is_file($root.$path)){
    header('Content-Type: '.($m[1]==='js'?'text/javascript':'text/css'));readfile($root.$path);exit;
}
http_response_code(404);echo 'Not found.';
