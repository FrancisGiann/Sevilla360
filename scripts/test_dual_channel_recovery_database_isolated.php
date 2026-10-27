<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || $argc!==2 || !str_starts_with($argv[1],'/tmp/sevilla360-phone-db/')) exit(2);
$_ENV['APP_KEY']=str_repeat('synthetic-integration-key-',3);
$_ENV['PHONE_SIGNUP_ENABLED']='0'; $_ENV['UNISMS_SMS_ENABLED']='0'; $_ENV['PASSWORD_SMS_RECOVERY_ENABLED']='0';
require_once __DIR__.'/../includes/recovery_security.php';
require_once __DIR__.'/../includes/session_account_guard.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli('localhost','root','',null,0,$argv[1]);
$name='dual_test_'.bin2hex(random_bytes(5)); $db->query("CREATE DATABASE `$name`"); $db->select_db($name);
$count=0;
$check=static function(bool $value,string $label) use (&$count): void { if (!$value) throw new RuntimeException($label); $count++; echo "PASS $label\n"; };
$race=static function(string $operation,string $codeOrHash,array $grant=[]) use ($argv,$name): array {
    $paths=[]; $pids=[];
    for($i=0;$i<2;$i++) {
        $path='/tmp/sevilla360-dual-race-'.bin2hex(random_bytes(8)); $paths[]=$path;
        $pid=pcntl_fork();
        if($pid===0) {
            $child=new mysqli('localhost','root','',$name,0,$argv[1]); usleep(100000);
            try {
                $ok=$operation==='verify'
                    ? recovery_consume_code($child,'new@example.test',$codeOrHash)!==null
                    : ($operation==='budget'
                        ? contact_allow($child,$codeOrHash,1,86400)
                        : recovery_reset_password($child,$grant,$codeOrHash));
                file_put_contents($path,$ok?'1':'0');
            } catch(Throwable $error) { file_put_contents($path,'error'); }
            exit(0);
        }
        if($pid<0) throw new RuntimeException('fork unavailable'); $pids[]=$pid;
    }
    foreach($pids as $pid) pcntl_waitpid($pid,$status);
    $results=array_map('file_get_contents',$paths); foreach($paths as $path) unlink($path);
    sort($results); return $results;
};
try {
    $db->query("CREATE TABLE users(id INT PRIMARY KEY,email VARCHAR(255),password_hash VARCHAR(255),role VARCHAR(20),status VARCHAR(20),is_verified TINYINT,reset_token VARCHAR(255),reset_token_hash CHAR(64),reset_expires_at DATETIME) ENGINE=InnoDB");
    $db->query("CREATE TABLE customers(user_id INT PRIMARY KEY,phone VARCHAR(32)) ENGINE=InnoDB");
    $db->query("CREATE TABLE staff(user_id INT PRIMARY KEY,status VARCHAR(20)) ENGINE=InnoDB");
    foreach (['034_customer_contact_verification.sql','035_dual_channel_recovery.sql'] as $file) {
        $sql=file_get_contents(__DIR__.'/../migrations/'.$file);
        foreach(explode(';',$sql) as $part) if(trim($part)!=='') $db->query(trim($part));
    }
    $check((int)$db->query("SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$name' AND TABLE_NAME='users' AND COLUMN_NAME IN ('phone_signup_required','recovery_email','auth_version')")->fetch_assoc()['n']===3,'migrations 034 and 035 apply in order');
    $db->query("INSERT INTO users VALUES (1,'new@example.test','old-hash','customer','active',1,NULL,NULL,NULL,1,NULL,NULL,NULL,0),(2,'legacy@example.test','legacy-hash','customer','active',1,NULL,NULL,NULL,0,NULL,NULL,NULL,0),(3,'staff@example.test','staff-hash','staff','active',1,NULL,NULL,NULL,0,NULL,NULL,NULL,0)");
    $db->query("INSERT INTO customers(user_id,phone) VALUES(1,'+639171111111')");
    $db->query("INSERT INTO staff(user_id,status) VALUES(3,'active')");
    $_SESSION=['logged_in'=>true,'user_id'=>2,'role'=>'customer'];
    $check(session_account_revalidation_state($db)==='active' && ($_SESSION['auth_version']??null)===0,'existing customer session adopts version zero');
    $_SESSION=['logged_in'=>true,'user_id'=>3,'role'=>'staff'];
    $check(session_account_revalidation_state($db)==='active' && ($_SESSION['auth_version']??null)===0,'existing staff session adopts version zero');
    $_SESSION=[];
    $db->query("INSERT INTO customer_verified_phones(user_id,phone_e164,verified_at) VALUES(1,'+639171111111',NOW())");
    $check(!recovery_sms_enabled(),'all SMS flags remain disabled');
    $eligible=$db->query('SELECT u.*,p.phone_e164,p.verified_at FROM users u JOIN customer_verified_phones p ON p.user_id=u.id WHERE u.id=1')->fetch_assoc();
    $check(recovery_sms_eligible($eligible),'new verified customer eligible');
    $check(!recovery_sms_eligible($db->query('SELECT * FROM users WHERE id=2')->fetch_assoc()),'legacy customer not silently opted into SMS');
    $check(!recovery_sms_eligible($db->query('SELECT * FROM users WHERE id=3')->fetch_assoc()),'staff not silently opted into SMS');
    $check(recovery_consume_code($db,'new@example.test','123456')===null,'missing challenge cannot grant reset');
    $hash=recovery_code_hash(1,'+639171111111','123456');
    $stmt=$db->prepare("INSERT INTO recovery_sms_challenges(user_id,phone_e164,code_hash,expires_at,provider_status) VALUES (1,'+639171111111',?,DATE_ADD(NOW(),INTERVAL 5 MINUTE),'pending')"); $stmt->bind_param('s',$hash); $stmt->execute(); $id=$db->insert_id;
    $check(recovery_consume_code($db,'new@example.test','000000')===null,'wrong recovery code rejected');
    $check((int)$db->query("SELECT failed_attempts FROM recovery_sms_challenges WHERE id=$id")->fetch_assoc()['failed_attempts']===1,'wrong attempt persists');
    $grantRow=recovery_consume_code($db,'new@example.test','123456');
    $check(is_array($grantRow) && $grantRow['phone_e164']==='+639171111111','correct code consumes challenge');
    $check(recovery_consume_code($db,'new@example.test','123456')===null,'recovery replay rejected');
    $stmt=$db->prepare("INSERT INTO recovery_sms_challenges(user_id,phone_e164,code_hash,expires_at,provider_status) VALUES (1,'+639171111111',?,DATE_SUB(NOW(),INTERVAL 1 SECOND),'pending')"); $stmt->bind_param('s',$hash); $stmt->execute();
    $check(recovery_consume_code($db,'new@example.test','123456')===null,'expired code rejected');
    $db->query('UPDATE recovery_sms_challenges SET consumed_at=NOW() WHERE user_id=1 AND consumed_at IS NULL');
    $stmt=$db->prepare("INSERT INTO recovery_sms_challenges(user_id,phone_e164,code_hash,expires_at,provider_status) VALUES (1,'+639171111111',?,DATE_ADD(NOW(),INTERVAL 5 MINUTE),'rejected')"); $stmt->bind_param('s',$hash); $stmt->execute();
    $check(recovery_consume_code($db,'new@example.test','123456')===null,'provider rejection fails closed');
    $db->query('UPDATE recovery_sms_challenges SET consumed_at=NOW() WHERE user_id=1 AND consumed_at IS NULL');
    $stmt=$db->prepare("INSERT INTO recovery_sms_challenges(user_id,phone_e164,code_hash,expires_at,provider_status) VALUES (1,'+639171111111',?,DATE_ADD(NOW(),INTERVAL 5 MINUTE),'pending')"); $stmt->bind_param('s',$hash); $stmt->execute();
    $db->close();
    $check($race('verify','123456')===['0','1'],'concurrent code submissions yield one grant');
    $db=new mysqli('localhost','root','',$name,0,$argv[1]);
    $grant=['recovery_user_id'=>1,'recovery_auth_version'=>0,'recovery_phone_e164'=>'+639171111111','recovery_expires_at'=>time()+600];
    $check(recovery_grant_valid($grant,$db->query('SELECT u.*,p.phone_e164,p.verified_at FROM users u JOIN customer_verified_phones p ON p.user_id=u.id WHERE u.id=1')->fetch_assoc()),'reset-only grant valid for verified phone');
    $db->close();
    $check($race('reset','new-hash',$grant)===['0','1'],'concurrent reset commits once');
    $db=new mysqli('localhost','root','',$name,0,$argv[1]);
    $row=$db->query('SELECT password_hash,auth_version FROM users WHERE id=1')->fetch_assoc();
    $check($row['password_hash']==='new-hash' && (int)$row['auth_version']===1,'reset updates password and revokes old sessions');
    $_SESSION=['logged_in'=>true,'user_id'=>1,'role'=>'customer'];
    $check(session_account_revalidation_state($db)==='invalid','pre-migration session cannot bypass changed password');
    $_SESSION=[];
    $check(!recovery_reset_password($db,$grant,'replayed-hash'),'reset grant cannot replay');
    $db->query("UPDATE users SET auth_version=1,password_hash='before-rollback' WHERE id=1");
    $stmt=$db->prepare("INSERT INTO recovery_sms_challenges(user_id,phone_e164,code_hash,expires_at,provider_status) VALUES (1,'+639171111111',?,DATE_ADD(NOW(),INTERVAL 5 MINUTE),'pending')"); $stmt->bind_param('s',$hash); $stmt->execute();
    $db->query("CREATE TRIGGER reject_recovery_consumption BEFORE UPDATE ON recovery_sms_challenges FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic rollback' ");
    $newGrant=$grant; $newGrant['recovery_auth_version']=1;
    try { recovery_reset_password($db,$newGrant,'must-rollback'); $failed=false; } catch(mysqli_sql_exception $error) { $failed=true; }
    $check($failed,'synthetic post-update failure raised');
    $row=$db->query('SELECT password_hash,auth_version FROM users WHERE id=1')->fetch_assoc();
    $check($row['password_hash']==='before-rollback' && (int)$row['auth_version']===1,'password and version roll back atomically');
    $db->query('DROP TRIGGER reject_recovery_consumption');
    $check(contact_allow($db,'sms-global-synthetic',1,86400) && !contact_allow($db,'sms-global-synthetic',1,86400),'shared daily budget reservation is atomic');
    $db->close();
    $check($race('budget','sms-global-concurrent')===['0','1'],'concurrent shared spend budget admits one request');
    $db=new mysqli('localhost','root','',$name,0,$argv[1]);
    $db->query("UPDATE users SET reset_token_hash='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',reset_expires_at=DATE_ADD(NOW(),INTERVAL 1 HOUR) WHERE id=1");
    $changeId=contact_issue($db,1,'change_phone','+639189999999','333333');
    $db->query("UPDATE contact_challenges SET provider_status='pending' WHERE id=$changeId");
    $check(contact_verify($db,1,'change_phone','333333'),'verified phone change succeeds');
    $current=$db->query('SELECT u.auth_version,u.reset_token_hash,p.phone_e164 FROM users u JOIN customer_verified_phones p ON p.user_id=u.id WHERE u.id=1')->fetch_assoc();
    $check((int)$current['auth_version']===2 && $current['reset_token_hash']===null && $current['phone_e164']==='+639189999999','phone change revokes grants and email reset link');
    $check((int)$db->query('SELECT COUNT(*) AS n FROM recovery_sms_challenges WHERE user_id=1 AND consumed_at IS NULL')->fetch_assoc()['n']===0,'phone change consumes old recovery challenges');
    $backId=contact_issue($db,1,'change_phone','+639171111111','444444');
    $db->query("UPDATE contact_challenges SET provider_status='pending' WHERE id=$backId");
    $check(contact_verify($db,1,'change_phone','444444'),'phone can change back with fresh code');
    $current=$db->query('SELECT u.*,p.phone_e164,p.verified_at FROM users u JOIN customer_verified_phones p ON p.user_id=u.id WHERE u.id=1')->fetch_assoc();
    $check(!recovery_grant_valid($newGrant,$current),'old grant stays revoked after phone changes back');

    echo "Combined disposable recovery database: $count checks passed.\n";
} finally { $db->query("DROP DATABASE `$name`"); }
