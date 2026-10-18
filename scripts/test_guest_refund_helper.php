<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/refund_helper.php';
$checks = 0;
function check(bool $ok, string $message): void { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }
$cash = ['method'=>'Cash','account_name'=>'  QA Guest Recipient  ','account_identifier'=>'must not retain','bank_name'=>'must not retain'];
try { normalize_refund_destination($cash); throw new RuntimeException('Customer cash request accepted'); } catch (InvalidArgumentException $e) { check(true,'Cash must require explicit staff context'); }
$normalized = normalize_refund_destination($cash,true);
check($normalized === ['method'=>'Cash','account_name'=>'QA Guest Recipient','account_identifier'=>null,'bank_name'=>null], 'Cash retains only the recipient, with no fabricated account details');
check(refund_destination_is_valid($normalized,true),'Saved staff cash destination is valid');
check(!refund_destination_is_valid($normalized),'Customer context still rejects cash');
foreach (['','Q',str_repeat('X',121),"QA\x00Guest"] as $name) {
 try { normalize_refund_destination(['method'=>'Cash','account_name'=>$name],true); throw new RuntimeException('Invalid cash recipient accepted'); } catch (InvalidArgumentException $e) { check(true,'Cash recipient must be bounded and valid'); }
}
foreach ([0,50,100,100.01] as $paid) { $r=calculate_refund_breakdown($paid); check($r['refund']===$paid*1.0 && $r['fee']===0.0,'Refund preserves full paid amount including cents'); }
$bank=normalize_refund_destination(['method'=>'Bank Transfer','account_name'=>'QA Guest','account_identifier'=>'QA-1234','bank_name'=>'QA Bank'],true);
check($bank['method']==='Bank Transfer' && $bank['account_identifier']==='QA-1234','Digital destination validation remains available to staff');
echo "Guest refund helper checks passed ($checks assertions).\n";
