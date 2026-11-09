<?php
declare(strict_types=1);
require_once __DIR__.'/hosted_flow.php';
require_once __DIR__.'/simulator.php';
/** Dedicated synthetic dataset only. No customer identity, booking ID or price is accepted from clients. */
function pm_public_demo(PayMongoPrototype $store,string $base,bool $secure,string $driver,?PayMongoTestProvider $fixture=null): void {
    if (!in_array($driver,['simulator','paymongo_test','adapter_fixture'],true) || ($driver==='adapter_fixture' && !$fixture)) throw new RuntimeException('Invalid demo driver.');
    header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    $action=$_GET['action']??'';
    if ($action==='script') {header('Content-Type: text/javascript');readfile(__DIR__.'/public_client.js');return;}
    if ($action==='webhook') {
        header('Content-Type: application/json');
        if ($driver!=='paymongo_test' || $_SERVER['REQUEST_METHOD']!=='POST') {http_response_code(404);echo '{}';return;}
        $raw=file_get_contents('php://input',false,null,0,65537);
        try {PayMongoPrototype::verify($raw,$_SERVER['HTTP_PAYMONGO_SIGNATURE']??'',(string)getenv('PAYMONGO_DEMO_WEBHOOK_SECRET'),time());}
        catch(Throwable $e){http_response_code(400);echo '{"error":"Webhook signature rejected"}';return;}
        try {echo json_encode(['outcome'=>$store->webhook($raw,$_SERVER['HTTP_PAYMONGO_SIGNATURE']??'',(string)getenv('PAYMONGO_DEMO_WEBHOOK_SECRET'))]);}
        catch(Throwable $e){http_response_code(503);echo '{"error":"Webhook reconciliation unavailable; retry"}';}return;
    }
    session_name('SEVILLA_PAYMONGO_DEMO');
    session_start(['cookie_httponly'=>true,'cookie_secure'=>$secure,'cookie_samesite'=>'Lax','cookie_path'=>'/paymongo-demo/','use_strict_mode'=>true,'use_only_cookies'=>true]);
    $_SESSION['pm_csrf']??=bin2hex(random_bytes(32));
    if (empty($_SESSION['pm_visitor'])) {
        $visitor=$store->atomic(function()use($store){
            $store->query('INSERT IGNORE INTO pm_demo_budget(day) VALUES(CURDATE())');
            $budget=$store->row('SELECT visitors FROM pm_demo_budget WHERE day=CURDATE() FOR UPDATE');
            if ((int)$budget['visitors']>=200)throw new RuntimeException('Demo visitor limit reached.');
            $store->query('UPDATE pm_demo_budget SET visitors=visitors+1 WHERE day=CURDATE()');
            $store->query('INSERT INTO pm_demo_visitors () VALUES ()');$id=$store->db->insert_id;
            $store->query("INSERT INTO venues (name,category,status) VALUES ('Fictional demo room','Hotel Room','Available')");$venue=$store->db->insert_id;
            // Customer IDs in this DB are synthetic visitor IDs only, never real account IDs.
            $store->query("INSERT INTO bookings (customer_id,reference_no,venue_id,start_date,end_date,total_amount,payment_scheme,payment_due_at) VALUES (?,?,?,DATE_ADD(CURDATE(),INTERVAL 30 DAY),DATE_ADD(CURDATE(),INTERVAL 32 DAY),6000,'50% Downpayment',DATE_ADD(NOW(),INTERVAL 1 DAY))",[$id,'TEST-'.bin2hex(random_bytes(6)),$venue]);
            return ['customer'=>$id,'booking'=>$store->db->insert_id];
        });$_SESSION['pm_visitor']=$visitor;
    }
    $visitor=$_SESSION['pm_visitor'];$customer=(int)$visitor['customer'];$booking=(int)$visitor['booking'];
    $json=static function(array $data):void {header('Content-Type: application/json');echo json_encode($data,JSON_THROW_ON_ERROR);};
    if ($action==='state') {
        $b=$store->row('SELECT reference_no,total_amount,amount_paid,payment_status,booking_status FROM bookings WHERE id=? AND customer_id=?',[$booking,$customer]);
        $a=$store->row('SELECT id,status FROM pm_attempts WHERE booking_id=? ORDER BY created_at DESC LIMIT 1',[$booking]);
        $json(['demo'=>true,'real_reservation'=>false,'driver'=>$driver,'booking'=>$b,'attempt'=>$a]);return;
    }
    if ($action!=='') {
        if ($_SERVER['REQUEST_METHOD']!=='POST' || !hash_equals($_SESSION['pm_csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??'')) {http_response_code(403);$json(['error'=>'Session validation failed.']);return;}
        if (!in_array($action,['checkout','recover','simulate'],true) || ($action==='simulate' && $driver!=='simulator')) {http_response_code(404);$json(['error'=>'Unavailable demo action.']);return;}
        try {
            // Fixed daily global cap also bounds credential-bearing API actions if enabled later.
            $store->atomic(function()use($store){
                $store->query('INSERT IGNORE INTO pm_demo_budget(day) VALUES(CURDATE())');
                $r=$store->row('SELECT operations FROM pm_demo_budget WHERE day=CURDATE() FOR UPDATE');
                if ((int)$r['operations']>=100)throw new RuntimeException('Demo daily limit reached.');
                $store->query('UPDATE pm_demo_budget SET operations=operations+1 WHERE day=CURDATE()');
            });
            $body=file_get_contents('php://input',false,null,0,8193);
            if(strlen($body)>8192)throw new RuntimeException('Demo request too large.');
            $input=json_decode($body,true,8,JSON_THROW_ON_ERROR);$attempt=(string)($input['attempt']??'');
            $sim=new PayMongoSimulator($store);$flow=null;
            if ($driver==='paymongo_test') {
                if (getenv('SEVILLA_PM_ALLOW_TEST_HTTP')!=='1') throw new RuntimeException('Provider requests are disabled.');
                $flow=new PayMongoHostedFlow($store,new PayMongoTestProvider((string)getenv('PAYMONGO_DEMO_TEST_SECRET_KEY'),null,$base,PayMongoTestProvider::paymentMethods(getenv('PAYMONGO_DEMO_PAYMENT_METHODS')?:'card,gcash,paymaya')));
            } elseif($fixture) $flow=new PayMongoHostedFlow($store,$fixture);
            if ($action!=='checkout') {
                $a=$store->row('SELECT * FROM pm_attempts WHERE id=? AND booking_id=?',[$attempt,$booking]);
                if (!$a)throw new RuntimeException('Checkout is not owned by this demo session.');
            }
            if ($flow) {
                $result=$action==='checkout'?$flow->start($booking,$customer):['message'=>$flow->recover($attempt,$customer)];
            } elseif ($action==='checkout') {
                $a=$store->begin($booking,$customer);$a=$store->attach($a['id'],$sim->create($a));$result=['attempt'=>$a['id'],'message'=>'Local simulation ready. No provider request.'];
            } elseif ($action==='simulate') $result=['message'=>$sim->deliver($sim->settle($attempt))];
            else $result=['message'=>$sim->recover($attempt,$customer)];
            $json($result);
        }catch(Throwable $e){http_response_code(422);$json(['error'=>'Demo action could not complete. Recover the existing checkout before trying another payment.']);}return;
    }
    header('Content-Type: text/html; charset=utf-8');
    $csrf=htmlspecialchars($_SESSION['pm_csrf'],ENT_QUOTES);$mode=$driver==='simulator'?'Local simulation: no PayMongo request.':'PayMongo TEST mode: use test payment details only.';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="'.$csrf.'"><title>TEST ONLY · Sevilla360 checkout demo</title><script defer src="?action=script"></script></head><body><main><h1>TEST PAYMENTS ONLY</h1><h2>No real money. No real reservation.</h2><p>This is a separate fictional demo. A test payment never reserves a real room or changes your real booking. Do not enter real card, billing or personal details.</p><p>'.$mode.'</p><p>Fictional room total: PHP 6,000. Test deposit: PHP 3,000; test balance: PHP 3,000.</p><pre id="state" aria-live="polite"></pre><p id="message" role="status"></p><button data-action="checkout">Open test checkout</button><button data-action="recover">Check test payment</button>'.($driver==='simulator'?'<button data-action="simulate">Simulate successful test payment</button>':'').'<p id="link"></p></main></body></html>';
}
