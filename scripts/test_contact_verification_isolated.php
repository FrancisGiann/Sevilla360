<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || $argc !== 2 || !str_starts_with($argv[1],'/tmp/sevilla360-phone-db/')) exit(2);
$_ENV['APP_KEY']=str_repeat('synthetic-key-',4);
$_ENV['PHONE_SIGNUP_ENABLED']='1'; $_ENV['UNISMS_SMS_ENABLED']='0'; $_ENV['UNISMS_DAILY_SMS_LIMIT']='0';
require_once __DIR__.'/../includes/contact_verification.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli('localhost','root','',null,0,$argv[1]);
$name='phone_test_'.bin2hex(random_bytes(4)); $db->query("CREATE DATABASE `$name`"); $db->select_db($name);
$assert=static function(bool $value,string $label): void { if (!$value) throw new RuntimeException($label); echo "PASS $label\n"; };
try {
    $db->query("CREATE TABLE users(id INT AUTO_INCREMENT PRIMARY KEY,email VARCHAR(255),role VARCHAR(20),status VARCHAR(20),is_verified TINYINT DEFAULT 0,reset_token VARCHAR(255),reset_token_hash CHAR(64),reset_expires_at DATETIME) ENGINE=InnoDB");
    $db->query("CREATE TABLE customers(user_id INT PRIMARY KEY,phone VARCHAR(32)) ENGINE=InnoDB");
    $migration=file_get_contents(__DIR__.'/../migrations/034_customer_contact_verification.sql');
    foreach (explode(';',$migration) as $sql) if (trim($sql) !== '') $db->query(trim($sql));
    $db->query("INSERT INTO users(id,email,role,status,is_verified,phone_signup_required) VALUES (1,'new@example.test','customer','active',0,1),(2,'other@example.test','customer','active',0,1),(3,'legacy@example.test','customer','active',1,0),(4,'staff@example.test','staff','active',1,0)");
    $db->query("INSERT INTO customers(user_id) VALUES(1),(2)");
    $db->query("INSERT INTO customer_verified_phones(user_id,phone_e164) VALUES(1,'+639171111111'),(2,'+639172222222')");
    $assert(!sms_ready(),'production flags prevent SMS');
    $assert(contact_reset_destination(['phone_signup_required'=>1,'email'=>'new@example.test','recovery_email'=>null,'recovery_email_verified_at'=>null])===null,'unverified recovery inbox cannot reset');
    $assert(contact_reset_destination(['phone_signup_required'=>0,'email'=>'legacy@example.test'])==='legacy@example.test','legacy reset path preserved');
    $assert(!contact_profile_phone_allowed(['phone_signup_required'=>1,'phone_e164'=>'+639171111111'],'+639179999999'),'new account profile cannot bypass phone verification');
    $assert(contact_profile_phone_allowed(['phone_signup_required'=>0],'09170000000'),'legacy profile behavior preserved');
    $assert(contact_code_hash(1,'signup_phone','+639171111111','123456')!==contact_code_hash(1,'signup_phone','+639171111111','123457'),'OTP hashes bind code');
    $id=contact_issue($db,1,'signup_phone','+639171111111','123456');
    $db->query("UPDATE contact_challenges SET provider_status='pending' WHERE id=$id");
    $assert(!contact_verify($db,1,'signup_phone','000000'),'wrong code');
    $assert(contact_verify($db,1,'signup_phone','123456'),'signup verification');
    $assert(!contact_verify($db,1,'signup_phone','123456'),'replay rejected');
    $assert((int)$db->query('SELECT is_verified FROM users WHERE id=1')->fetch_assoc()['is_verified']===1,'account activated once');
    $id=contact_issue($db,2,'signup_phone','+639172222222','654321'); $db->query("UPDATE contact_challenges SET provider_status='pending',expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=$id");
    $assert(!contact_verify($db,2,'signup_phone','654321'),'expired code');
    $db->query('UPDATE customer_verified_phones SET verified_at=NOW() WHERE user_id=2');
    $id=contact_issue($db,1,'change_phone','+639172222222','555555'); $db->query("UPDATE contact_challenges SET provider_status='pending' WHERE id=$id");
    try { contact_verify($db,1,'change_phone','555555'); $duplicate=false; } catch(mysqli_sql_exception $e) { $duplicate=true; }
    $assert($duplicate,'duplicate verified phone blocked by unique index');
    $db->query("INSERT INTO users(id,email,role,status,is_verified,phone_signup_required) VALUES (5,'pending@example.test','customer','active',0,1)");
    $db->query("INSERT INTO customer_verified_phones(user_id,phone_e164) VALUES (5,'+639171111111')");
    $assert($db->query('SELECT verified_at FROM customer_verified_phones WHERE user_id=5')->fetch_assoc()['verified_at']===null,'pending reservation does not monopolize phone');
    $assert($db->query('SELECT phone_e164 FROM customer_verified_phones WHERE user_id=1')->fetch_assoc()['phone_e164']==='+639171111111','old phone preserved on failed edit');
    $db->query("UPDATE users SET recovery_email_pending='recover@example.test' WHERE id=1");
    $id=contact_issue($db,1,'recovery_email','recover@example.test','111111');
    $assert(contact_verify($db,1,'recovery_email','111111'),'recovery inbox confirmation');
    $assert(!contact_verify($db,1,'recovery_email','111111'),'recovery code replay rejected');
    $assert($db->query('SELECT recovery_email FROM users WHERE id=1')->fetch_assoc()['recovery_email']==='recover@example.test','verified recovery address stored separately');
    $assert(contact_reset_destination($db->query('SELECT * FROM users WHERE id=1')->fetch_assoc())==='recover@example.test','reset routed only to confirmed recovery inbox');
    $assert(contact_allow($db,'synthetic-phone',2,3600) && contact_allow($db,'synthetic-phone',2,3600) && !contact_allow($db,'synthetic-phone',2,3600),'durable send limit');
    $assert((int)$db->query('SELECT phone_signup_required FROM users WHERE id=3')->fetch_assoc()['phone_signup_required']===0,'existing customer unaffected');
    $assert((int)$db->query('SELECT phone_signup_required FROM users WHERE id=4')->fetch_assoc()['phone_signup_required']===0,'staff unaffected');
    $id=contact_issue($db,2,'signup_phone','+639172222222','222222');
    $db->query("UPDATE contact_challenges SET provider_status='pending' WHERE id=$id");
    $db->close();
    $paths=[]; $children=[];
    for ($i=0;$i<2;$i++) {
        $path='/tmp/sevilla360-phone-race-'.bin2hex(random_bytes(8)); $paths[]=$path;
        $pid=pcntl_fork();
        if ($pid===0) {
            $child=new mysqli('localhost','root','',$name,0,$argv[1]);
            usleep(100000);
            try { file_put_contents($path,contact_verify($child,2,'signup_phone','222222')?'1':'0'); }
            catch(Throwable $e) { file_put_contents($path,'error'); }
            exit(0);
        }
        if ($pid<0) throw new RuntimeException('fork failed');
        $children[]=$pid;
    }
    foreach ($children as $pid) pcntl_waitpid($pid,$status);
    $results=array_map('file_get_contents',$paths); foreach($paths as $path) unlink($path);
    $assert(sort($results) && $results===['0','1'],'concurrent verification activates exactly once');
    $db=new mysqli('localhost','root','',$name,0,$argv[1]);

} finally { $db->query("DROP DATABASE `$name`"); }
