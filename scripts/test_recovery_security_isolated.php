<?php
// Pure, disposable checks. No database, transport, credit, or real account access.
require_once __DIR__.'/../includes/recovery_security.php';
$_ENV['APP_KEY']=str_repeat('synthetic-test-key-',3);
$user=['id'=>9,'role'=>'customer','status'=>'active','is_verified'=>1,'phone_signup_required'=>1,'phone_e164'=>'+639171234567','verified_at'=>'2026-01-01','auth_version'=>2];
$GLOBALS['recovery_checks']=0;
function test_assert(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); $GLOBALS['recovery_checks']++; }
test_assert(recovery_sms_eligible($user),'verified customer eligible');
foreach (['verified_at'=>null,'is_verified'=>0,'phone_e164'=>'+639171234568','role'=>'staff','status'=>'suspended','phone_signup_required'=>0] as $field=>$value) {
    $altered=$user; $altered[$field]=$value;
    if ($field==='phone_e164') continue; // Another valid verified number is still eligible.
    test_assert(!recovery_sms_eligible($altered),'eligibility requires '.$field);
}
$hash=recovery_code_hash(9,$user['phone_e164'],'123456');
test_assert($hash!==recovery_code_hash(9,$user['phone_e164'],'654321'),'distinct code digest');
test_assert($hash!==contact_code_hash(9,'signup_phone',$user['phone_e164'],'123456'),'purpose separation');
$payload=recovery_sms_payload($user['phone_e164'],'123456');
test_assert(str_contains($payload['content'],'Sevilla360 password reset code: 123456'),'brand and purpose');
test_assert(str_contains($payload['content'],'Do not share this code.'),'sharing warning');
test_assert(strlen($payload['content'])<=160,'single GSM segment');
$sent=recovery_submit_sms($user['phone_e164'],'123456',static function(string $url,array $body): array {
    test_assert($url===UNISMS_SMS_ENDPOINT,'endpoint');
    test_assert($body['recipient']==='+639171234567','recipient');
    return ['http_status'=>201,'body'=>['message'=>['reference_id'=>'msg_mock','status'=>'pending']]];
});
test_assert($sent['status']==='pending','queued remains pending');
$grant=['recovery_user_id'=>9,'recovery_auth_version'=>2,'recovery_phone_e164'=>$user['phone_e164'],'recovery_expires_at'=>time()+600];
test_assert(recovery_grant_valid($grant,$user),'valid limited grant');
$changedPhone=$user; $changedPhone['phone_e164']='+639179999999';
test_assert(!recovery_grant_valid($grant,$changedPhone),'phone change revokes reset grant');
$user['auth_version']=3;
test_assert(!recovery_grant_valid($grant,$user),'password change revokes grant');
echo "Recovery security isolated: {$GLOBALS['recovery_checks']} checks passed.\n";
