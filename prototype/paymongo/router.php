<?php
declare(strict_types=1);
// Router never returns false: no repository, .env, or database file can be served.
$host=$_SERVER['HTTP_HOST'] ?? '';
if (PHP_SAPI!=='cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '',['127.0.0.1','::1'],true)
    || !in_array($host,['127.0.0.1:8787','localhost:8787'],true)) { http_response_code(403); exit('Local prototype only.'); }
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
$driver=getenv('SEVILLA_PM_DRIVER') ?: 'simulator';
if (!in_array($driver,['simulator','adapter_fixture','paymongo_test'],true)) { http_response_code(503); exit('Invalid checkout driver.'); }
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if ($path==='/assets/js/paymongo_checkout.js') { header('Content-Type: text/javascript'); readfile(dirname(__DIR__,2).$path); exit; }
if (in_array($path,['/app.css','/app.js'],true)) {
    header('Content-Type: '.($path==='/app.css'?'text/css':'text/javascript').'; charset=utf-8'); readfile(__DIR__.$path); exit;
}
require __DIR__.'/bootstrap.php';
require __DIR__.'/simulator.php';
require __DIR__.'/hosted_flow.php';
$routes=['/actions/user/pay_existing.php'=>'checkout','/actions/user/sync_payment.php'=>'recover','/actions/user/close_paymongo_checkout.php'=>'cancel'];
$routeAction=$routes[$path]??null;
if($path==='/actions/bookings/paymongo_webhook.php')$path='/webhook';
session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Strict','use_strict_mode'=>true]);
$_SESSION['csrf'] ??= bin2hex(random_bytes(24));
// Explicit synthetic identity, never a real customer login or account bypass in the app.
$customer=1;
try {
    $store=new PayMongoPrototype(pm_connect()); $sim=new PayMongoSimulator($store);
    $flow=null;
    if($driver==='adapter_fixture'){ require __DIR__.'/adapter_fixture.php'; $flow=new PayMongoHostedFlow($store,pm_fixture_provider($store)); }
    elseif($driver==='paymongo_test' && $path!=='/webhook' && ($_SERVER['REQUEST_METHOD']??'')==='POST') $flow=new PayMongoHostedFlow($store,PayMongoTestProvider::fromEnvironment());
    if ($path==='/webhook' && $_SERVER['REQUEST_METHOD']==='POST') {
        header('Content-Type: application/json');
        echo json_encode(['outcome'=>$store->webhook(file_get_contents('php://input'),$_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '',($driver==='paymongo_test' ? PayMongoTestProvider::webhookSecret() : PayMongoSimulator::SIGNING_FIXTURE))]); exit;
    }
    if (($path==='/api' || $routeAction!==null) && $_SERVER['REQUEST_METHOD']==='POST') {
        header('Content-Type: application/json');
        if (!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) { http_response_code(403); exit('{"error":"Session validation failed. Reload the page."}'); }
        $input=json_decode(file_get_contents('php://input'),true,16,JSON_THROW_ON_ERROR);
        $action=$routeAction ?? ($input['action'] ?? ''); $attempt=(string)($input['attempt'] ?? '');
        if ($attempt!=='') {
            $a=$store->row('SELECT * FROM pm_attempts WHERE id=?',[$attempt]);
            if (!$a) throw new RuntimeException('Unknown attempt.');
            $store->atomic(fn()=>$store->booking((int)$a['booking_id'],$customer));
        }
        if($flow && in_array($action,['checkout','recover','cancel'],true)) {
            $response=$action==='checkout' ? $flow->start((int)($input['booking']??0),$customer)
                : ['message'=>$flow->recover($attempt,$customer,$action==='cancel')];
            if($driver==='adapter_fixture' && isset($response['checkout_url'])) $response['checkout_url']='/?hosted='.$response['attempt'];
            echo json_encode($response);exit;
        }
        if($driver==='paymongo_test')throw new RuntimeException('Simulator actions are unavailable in PayMongo mode.');
        $message='';
        switch ($action) {
            case 'checkout':
                $a=$store->begin((int)($input['booking'] ?? 0),$customer);
                if ($a['status']==='creating') {
                    // First creation is sent exactly once in this synchronous fake. Unknown outcomes recover, never create again.
                    $store->query("UPDATE pm_attempts SET status='uncertain' WHERE id=? AND status='creating'",[$a['id']]);
                    $resource=$sim->create($a);
                    if (!empty($input['interrupt'])) { $message='Interrupted after simulated creation. Recover this attempt; do not pay again.'; break; }
                    $store->attach($a['id'],$resource);
                }
                $message='Checkout ready. Reopening uses the same attempt and expiry.'; break;
            case 'pay': $message=$sim->deliver($sim->settle($attempt)); break;
            case 'delay': $sim->settle($attempt); $message='Simulated payment received, webhook withheld. Recover to reconcile it.'; break;
            case 'recover': $message=$sim->recover($attempt,$customer); break;
            case 'cancel':
                $r=$sim->expire($attempt);
                if ($r['attributes']['payments']) $message=$sim->deliver($r);
                else { $store->close($attempt,$customer,$r); $message='Checkout expired and hold released.'; }
                break;
            case 'expire':
                // Laboratory clock shortcut, does not change real system time.
                $store->atomic(function () use ($store,$a,$customer) {
                    $b=$store->booking((int)$a['booking_id'],$customer);
                    $store->query('UPDATE pm_attempts SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=?',[$a['id']]);
                    if ($b['payment_step_token']===$a['hold_token']) {
                        $store->query('UPDATE bookings SET payment_step_expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=?',[$a['booking_id']]);
                        $store->query('UPDATE booking_locks SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE session_id=?',['pay:'.$a['hold_token']]);
                    }
                });
                $message=$sim->recover($attempt,$customer); break;
            case 'late': $message=$sim->deliver($sim->settle($attempt,true)); break;
            default: throw new RuntimeException('Unknown action.');
        }
        echo json_encode(['message'=>$message]); exit;
    }
    if ($path==='/state') {
        header('Content-Type: application/json');
        $bookings=$store->query('SELECT b.*,v.name FROM bookings b JOIN venues v ON v.id=b.venue_id WHERE customer_id=? ORDER BY b.id',[$customer])->fetch_all(MYSQLI_ASSOC);
        $attempts=$store->query('SELECT a.* FROM pm_attempts a JOIN bookings b ON b.id=a.booking_id WHERE b.customer_id=? ORDER BY a.created_at DESC,a.id',[$customer])->fetch_all(MYSQLI_ASSOC);
        $receipts=$store->query('SELECT r.* FROM pm_receipts r JOIN pm_attempts a ON a.id=r.attempt_id JOIN bookings b ON b.id=a.booking_id WHERE b.customer_id=? ORDER BY r.created_at DESC',[$customer])->fetch_all(MYSQLI_ASSOC);
        echo json_encode(compact('bookings','attempts','receipts','driver')); exit;
    }
    if ($path==='/' && $_SERVER['REQUEST_METHOD']==='GET') { require __DIR__.'/index.php'; exit; }
    http_response_code(404); echo 'Not found.';
} catch (Throwable $e) {
    http_response_code($e instanceof mysqli_sql_exception ? 503 : 422); header('Content-Type: application/json');
    echo json_encode(['error'=>$e instanceof mysqli_sql_exception ? 'Database operation failed; retry recovery. No partial change was committed.' : $e->getMessage()]);
}
