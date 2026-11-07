<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('zend.exception_ignore_args','1');
// No application config, existing .env, production session or database is loaded.
if (getenv('SEVILLA_PM_PUBLIC_DEMO')!=='1') {http_response_code(503);exit('Test demo is disabled.');}
try {
    $base=(string)getenv('PAYMONGO_DEMO_BASE_URL');$url=parse_url($base);
    if (!$url || ($url['scheme']??'')!=='https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || isset($url['port'])
        || ($url['path']??'')!=='/paymongo-demo/index.php' || ($_SERVER['HTTP_HOST']??'')!==$url['host'] || ($_SERVER['HTTPS']??'')!=='on') throw new RuntimeException('Invalid demo origin.');
    $name=(string)getenv('PAYMONGO_DEMO_DB_NAME');$user=(string)getenv('PAYMONGO_DEMO_DB_USER');$password=(string)getenv('PAYMONGO_DEMO_DB_PASSWORD');
    if (!preg_match('/\A[A-Za-z0-9_]*sevilla_pm_demo\z/D',$name) || !preg_match('/\A[A-Za-z0-9_]*pm_demo\z/D',$user) || $password==='') throw new RuntimeException('Dedicated demo database required.');
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $db=new mysqli(getenv('PAYMONGO_DEMO_DB_HOST')?:'localhost',$user,$password,$name);unset($password);
    if (!$db->query("SELECT purpose FROM pm_demo_identity WHERE purpose='isolated-public-test-demo-v1'")->fetch_assoc()) throw new RuntimeException('Demo database identity missing.');
    $db->set_charset('utf8mb4');$db->query("SET time_zone='+08:00'");$db->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');$db->query('SET SESSION innodb_lock_wait_timeout=5');
    require __DIR__.'/public_runtime.php';
    pm_public_demo(new PayMongoPrototype($db),$base,true,getenv('PAYMONGO_DEMO_DRIVER')?:'simulator');
} catch(Throwable $e) {http_response_code(503);echo 'Test demo unavailable. No real reservation or payment was made.';}
