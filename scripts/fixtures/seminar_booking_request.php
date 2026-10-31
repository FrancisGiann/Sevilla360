<?php
/** Subprocess request harness used only with the disposable finalization test database. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$fixture = json_decode(base64_decode($argv[2] ?? '', true) ?: '', true, 512, JSON_THROW_ON_ERROR);
if (!is_array($fixture) || !preg_match('/\Aseminar_finalize_test_[a-f0-9]{12}\z/', $fixture['database'] ?? '')
    || !str_starts_with(ini_get('mysqli.default_socket'), '/tmp/')) throw new RuntimeException('Synthetic database and socket required.');
$route = $argv[1] ?? '';
if (!in_array($route, ['lock_dates','submit_online','submit_walkin','get_room_availability','lock_addon_rooms'], true)) throw new RuntimeException('Unknown synthetic route.');
foreach (['DB_HOST'=>'localhost','DB_USER'=>'root','DB_PASS'=>'','DB_NAME'=>$fixture['database'],'BACKUP_DIR'=>''] as $key=>$value) $_ENV[$key]=$_SERVER[$key]=$value;
$root=dirname(__DIR__,2);
chdir($root.'/actions/bookings');
session_start();
$_SESSION=['logged_in'=>true,'user_id'=>$fixture['role']==='customer'?2:1,'role'=>$fixture['role'],'csrf_token'=>'synthetic-csrf'];
if(str_starts_with($route,'submit_')) $_SESSION['locked_venue_id']=100;
$_SERVER['REQUEST_METHOD']=$route==='get_room_availability'?'GET':'POST';
$_SERVER['HTTP_X_CSRF_TOKEN']='synthetic-csrf'; $_SERVER['REMOTE_ADDR']='127.0.0.1';
$_POST=$fixture['post']; $_GET=$fixture['post'];
register_shutdown_function(static function():void { if(session_status()===PHP_SESSION_ACTIVE){$_SESSION=[];session_destroy();} });
require $root.'/actions/bookings/'.$route.'.php';
