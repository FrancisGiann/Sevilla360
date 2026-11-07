<?php
declare(strict_types=1);

// Isolated financial coordinator test. Requires an already-running temporary,
// socket-only MariaDB beneath /tmp; never loads application DB configuration.
if (PHP_SAPI !== 'cli' || $argc !== 2) {
    fwrite(STDERR, "Usage: php scripts/test_paymongo_refunds_isolated.php /tmp/sevilla360-paymongo-refunds.XXXX/server.sock\n");
    exit(2);
}
$socket = realpath($argv[1]);
$prefix = sys_get_temp_dir() . '/sevilla360-paymongo-refunds.';
if ($socket === false || !file_exists($socket) || !str_starts_with($socket, $prefix)) {
    fwrite(STDERR, "Refusing a non-isolated MariaDB socket.\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once dirname(__DIR__) . '/includes/paymongo_refunds.php';

$admin = new mysqli('localhost', 'root', '', null, 0, $socket);
$database = 'pm_refund_' . bin2hex(random_bytes(5));
$admin->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db = new mysqli('localhost', 'root', '', $database, 0, $socket);
$db->set_charset('utf8mb4');
$checks = 0;
$failures = [];
$check = static function (string $label, bool $ok) use (&$checks, &$failures): void {
    $checks++;
    if (!$ok) $failures[] = $label;
    fwrite(STDOUT, ($ok ? 'PASS ' : 'FAIL ') . $label . "\n");
};
$throws = static function (callable $work): bool {
    try { $work(); return false; } catch (Throwable) { return true; }
};

$migrationDatabase='pm_refund_migration_'.bin2hex(random_bytes(4));
$admin->query("CREATE DATABASE `{$migrationDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$migrationDb=new mysqli('localhost','root','',$migrationDatabase,0,$socket);
foreach ([
    "CREATE TABLE bookings (id INT PRIMARY KEY) ENGINE=InnoDB",
    "CREATE TABLE payments (id INT PRIMARY KEY AUTO_INCREMENT, booking_id INT, transaction_id VARCHAR(100), payment_method VARCHAR(120), amount DECIMAL(10,2), status ENUM('Success','Pending','Failed'), payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
    "CREATE TABLE cancellations (id INT PRIMARY KEY AUTO_INCREMENT, booking_id INT, status ENUM('Pending','Processed','Rejected'), reason TEXT, refund_amount DECIMAL(10,2), fee_deducted DECIMAL(10,2), fee_percent DECIMAL(6,3), refund_transaction_id VARCHAR(255)) ENGINE=InnoDB",
] as $sql) $migrationDb->query($sql);
foreach (['038_paymongo_test_checkout.sql','039_paymongo_original_payment_refunds.sql'] as $migrationFile) {
    $migrationSql=file_get_contents(dirname(__DIR__).'/migrations/'.$migrationFile);
    if (!is_string($migrationSql)) throw new RuntimeException('Isolated migration file could not be read.');
    $migrationSql=preg_replace('/^\s*--.*(?:\R|$)/m','',$migrationSql);
    foreach (explode(';',(string)$migrationSql) as $statement) {
        if (trim($statement)!=='') $migrationDb->query(trim($statement));
    }
}
$migrationAgain=(string)file_get_contents(dirname(__DIR__).'/migrations/039_paymongo_original_payment_refunds.sql');
$migrationAgain=preg_replace('/^\s*--.*(?:\R|$)/m','',$migrationAgain);
foreach (explode(';',(string)$migrationAgain) as $statement) if (trim($statement)!=='') $migrationDb->query(trim($statement));
$columns=$migrationDb->query("SELECT table_name,column_name FROM information_schema.columns WHERE table_schema='{$migrationDatabase}'")->fetch_all(MYSQLI_ASSOC);
$columnKeys=array_map(static fn($row)=>$row['table_name'].'.'.$row['column_name'],$columns);
$migrationTables=$migrationDb->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='{$migrationDatabase}' AND table_name IN ('pm_refund_snapshots','pm_refund_allocations','pm_refund_attempts','pm_refund_events')")->fetch_row()[0]??0;
$check('real 038 then 039 migrations apply twice to isolated pre-migration schema', in_array('payments.fee_cents',$columnKeys,true) && in_array('payments.fee_verified_at',$columnKeys,true) && in_array('cancellations.refund_snapshot_id',$columnKeys,true) && (int)$migrationTables===4);
$migrationDb->close();
$admin->query("DROP DATABASE `{$migrationDatabase}`");

foreach ([
    "CREATE TABLE bookings (id INT PRIMARY KEY, booking_status VARCHAR(30), payment_status VARCHAR(30), amount_paid DECIMAL(10,2), payment_due_at DATETIME NULL, updated_at DATETIME NULL) ENGINE=InnoDB",
    "CREATE TABLE payments (id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT, transaction_id VARCHAR(100), payment_method VARCHAR(40), amount DECIMAL(10,2), status VARCHAR(20), payment_date DATETIME DEFAULT CURRENT_TIMESTAMP, fee_cents BIGINT UNSIGNED NULL, net_amount_cents BIGINT UNSIGNED NULL, fee_verified_at DATETIME(6) NULL, UNIQUE KEY(transaction_id)) ENGINE=InnoDB",
    "CREATE TABLE pm_attempts (id CHAR(32) PRIMARY KEY, booking_id INT NOT NULL) ENGINE=InnoDB",
    "CREATE TABLE pm_receipts (payment_id VARCHAR(100) PRIMARY KEY, attempt_id CHAR(32), amount_cents BIGINT, currency VARCHAR(8), disposition VARCHAR(80)) ENGINE=InnoDB",
    "CREATE TABLE cancellations (id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT, reason TEXT, refund_amount DECIMAL(10,2), fee_deducted DECIMAL(10,2), fee_percent DECIMAL(6,3), refund_gross_amount DECIMAL(10,2), refund_provider VARCHAR(24), refund_provider_status VARCHAR(30), refund_policy_version VARCHAR(48), refund_snapshot_id CHAR(32), refund_transaction_id VARCHAR(255), status VARCHAR(30), admin_reply TEXT) ENGINE=InnoDB",
    "CREATE TABLE pm_refund_snapshots (id CHAR(32) PRIMARY KEY, cancellation_id INT, booking_id INT, gross_cents BIGINT UNSIGNED, fee_cents BIGINT UNSIGNED, prior_refund_cents BIGINT UNSIGNED, refund_cents BIGINT UNSIGNED, policy_version VARCHAR(48), created_by INT NULL, created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6)) ENGINE=InnoDB",
    "CREATE TABLE pm_refund_allocations (id CHAR(32) PRIMARY KEY, snapshot_id CHAR(32), payment_id VARCHAR(100), gross_cents BIGINT UNSIGNED, fee_cents BIGINT UNSIGNED, prior_refund_cents BIGINT UNSIGNED, refund_cents BIGINT UNSIGNED, created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6), UNIQUE KEY(snapshot_id,payment_id)) ENGINE=InnoDB",
    "CREATE TABLE pm_refund_attempts (id CHAR(32) PRIMARY KEY, allocation_id CHAR(32), correlation_note VARCHAR(255), provider_refund_id VARCHAR(100) NULL, status ENUM('ready','creating','uncertain','pending','processing','succeeded','failed','review') DEFAULT 'ready', error_code VARCHAR(100) NULL, create_started_at DATETIME(6) NULL, reconciled_at DATETIME(6) NULL, provider_updated_at BIGINT UNSIGNED NULL, created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6), updated_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6), UNIQUE KEY(correlation_note), UNIQUE KEY(provider_refund_id), INDEX(allocation_id,created_at)) ENGINE=InnoDB",
    "CREATE TABLE cancellation_history (id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT, cancellation_id INT NULL, action VARCHAR(40), reason TEXT, refund_amount DECIMAL(10,2), fee_deducted DECIMAL(10,2), fee_percent DECIMAL(6,3), admin_reply TEXT NULL, actor_user_id INT NULL) ENGINE=InnoDB",
    "CREATE TABLE audit_logs (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, module VARCHAR(80), action TEXT, ip_address VARCHAR(80), event_type VARCHAR(80), entity_type VARCHAR(80), entity_id VARCHAR(80), details_json JSON) ENGINE=InnoDB",
    "CREATE TABLE manual_payment_submissions (id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT, status VARCHAR(30), rejection_reason TEXT, reviewer_user_id INT NULL, reviewed_at DATETIME NULL) ENGINE=InnoDB",
] as $sql) $db->query($sql);

// Reproduce MariaDB deployments where legacy payment/cancellation columns use
// unicode_ci while newer PayMongo tables inherited a different DB default.
$collationResult=$db->query("SHOW COLLATION LIKE 'utf8mb4_uca1400_ai_ci'");
$newTableCollation=$collationResult && $collationResult->fetch_assoc() ? 'utf8mb4_uca1400_ai_ci' : 'utf8mb4_general_ci';
$db->query("ALTER TABLE payments MODIFY transaction_id VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->query("ALTER TABLE pm_receipts MODIFY payment_id VARCHAR(100) CHARACTER SET utf8mb4 COLLATE {$newTableCollation} NOT NULL");
$db->query("ALTER TABLE cancellations MODIFY refund_snapshot_id CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL");
$db->query("ALTER TABLE pm_refund_snapshots MODIFY id CHAR(32) CHARACTER SET utf8mb4 COLLATE {$newTableCollation} NOT NULL");
$db->query("ALTER TABLE pm_refund_allocations MODIFY snapshot_id CHAR(32) CHARACTER SET utf8mb4 COLLATE {$newTableCollation} NULL");

$state = ['fees'=>[], 'refunds'=>[], 'posts'=>0, 'lose_next_post'=>false, 'list_failure'=>false, 'refund_requests'=>[], 'paged_refund_list'=>false];
$transport = static function (string $method, string $url, array $headers, ?string $body) use (&$state): array {
    $path = (string)parse_url($url, PHP_URL_PATH);
    if ($method === 'GET' && preg_match('~\A/v1/payments/(pay_[A-Za-z0-9_]+)\z~D', $path, $match)) {
        $payment = $match[1];
        if (!isset($state['fees'][$payment])) return ['status'=>404,'body'=>'{}'];
        $fee = $state['fees'][$payment];
        $attributes = ['livemode'=>false,'status'=>'paid','amount'=>$fee['amount'],'currency'=>'PHP'];
        if ($fee['fee'] !== null) { $attributes['fee']=$fee['fee']; $attributes['net_amount']=$fee['amount']-$fee['fee']; }
        return ['status'=>200,'body'=>json_encode(['data'=>['id'=>$payment,'type'=>'payment','attributes'=>$attributes]],JSON_THROW_ON_ERROR)];
    }
    if ($method === 'GET' && $path === '/refunds') {
        $queryString=(string)parse_url($url,PHP_URL_QUERY);
        $query=[];
        foreach (explode('&',$queryString) as $part) {
            if ($part==='') continue;
            [$name,$value]=array_pad(explode('=',$part,2),2,'');
            $query[urldecode($name)]=urldecode($value);
        }
        $state['refund_requests'][]=['path'=>$path,'query'=>$query,'query_string'=>$queryString];
        if ($state['paged_refund_list']) {
            $payment=(string)($query['payment_id']??'');
            if (!isset($query['after'])) {
                $resource=['id'=>'ref_page_cursor','type'=>'refund','attributes'=>[
                    'amount'=>100,'currency'=>'PHP','livemode'=>false,'notes'=>'page fixture',
                    'payment_id'=>$payment,'reason'=>'others','status'=>'succeeded',
                ]];
                return ['status'=>200,'body'=>json_encode(['data'=>[$resource],'has_more'=>true],JSON_THROW_ON_ERROR)];
            }
            return ['status'=>200,'body'=>'{"data":[],"has_more":false}'];
        }
        if ($state['list_failure']) return ['status'=>200,'body'=>'{"data":[],"has_more":true}'];
        $payment = (string)($query['payment_id'] ?? '');
        $items = array_values(array_filter($state['refunds'],static fn($refund)=>$refund['attributes']['payment_id']===$payment));
        return ['status'=>200,'body'=>json_encode(['data'=>$items,'has_more'=>false],JSON_THROW_ON_ERROR)];
    }
    if ($method === 'GET' && preg_match('~\A/v1/refunds/(ref_[A-Za-z0-9_]+)\z~D',$path,$match)) {
        return isset($state['refunds'][$match[1]])
            ? ['status'=>200,'body'=>json_encode(['data'=>$state['refunds'][$match[1]]],JSON_THROW_ON_ERROR)]
            : ['status'=>404,'body'=>'{}'];
    }
    if ($method === 'POST' && $path === '/v1/refunds') {
        $request=json_decode((string)$body,true,16,JSON_THROW_ON_ERROR)['data']['attributes'];
        $state['posts']++;
        $id='ref_fixture_'.str_pad((string)$state['posts'],8,'0',STR_PAD_LEFT);
        $resource=['id'=>$id,'type'=>'refund','attributes'=>[
            'amount'=>(int)$request['amount'],'currency'=>'PHP','livemode'=>false,'notes'=>$request['notes'],
            'payment_id'=>$request['payment_id'],'reason'=>'others','status'=>'succeeded','updated_at'=>time(),
        ]];
        $state['refunds'][$id]=$resource;
        if ($state['lose_next_post']) { $state['lose_next_post']=false; $resource['attributes']['status']='pending'; $state['refunds'][$id]=$resource; return ['status'=>500,'body'=>'{}']; }
        return ['status'=>201,'body'=>json_encode(['data'=>$resource],JSON_THROW_ON_ERROR)];
    }
    return ['status'=>404,'body'=>'{}'];
};
$provider = new PayMongoTestProvider('sk_test_OFFLINE_REFUND_FIXTURE',$transport);
$state['refund_requests']=[];
$provider->refundsForPayment('pay_list_contract');
$listRequest=$state['refund_requests'][0]??null;
$check('refund history uses the documented route and plain collection query parameters',
    is_array($listRequest) && $listRequest['path']==='/refunds'
    && ($listRequest['query']['payment_id']??null)==='pay_list_contract'
    && ($listRequest['query']['limit']??null)==='100'
    && !array_key_exists('data.attributes.payment_id',$listRequest['query'])
    && str_contains($listRequest['query_string'],'payment_id=pay_list_contract')
    && !str_contains($listRequest['query_string'],'data.attributes.payment_id'));
$state['refund_requests']=[];
$state['paged_refund_list']=true;
$pagedHistory=$provider->refundsForPayment('pay_pagination_contract');
$state['paged_refund_list']=false;
$pageRequests=$state['refund_requests'];
$check('refund history follows the documented after cursor with the same payment filter',
    count($pagedHistory)===1 && $pagedHistory[0]['id']==='ref_page_cursor'
    && count($pageRequests)===2
    && ($pageRequests[0]['path']??null)==='/refunds'
    && ($pageRequests[0]['query']['payment_id']??null)==='pay_pagination_contract'
    && ($pageRequests[1]['query']['payment_id']??null)==='pay_pagination_contract'
    && ($pageRequests[1]['query']['after']??null)==='ref_page_cursor'
    && str_contains($pageRequests[1]['query_string'],'after=ref_page_cursor'));
$providerHttpFailure=new PayMongoTestProvider('sk_test_OFFLINE_REFUND_DIAGNOSTIC',static fn()=>['status'=>401,'body'=>'provider body contains secret and payment identifiers']);
try {
    $providerHttpFailure->retrievePayment('pay_diagnostic');
    $httpFailureCategory=''; $httpFailureMessage='';
} catch (PayMongoRefundVerificationFailure $e) {
    $httpFailureCategory=$e->diagnosticCode; $httpFailureMessage=$e->getMessage();
}
$check('payment lookup failures expose a safe category without provider response content', $httpFailureCategory==='payment_http_401' && !str_contains($httpFailureMessage,'secret') && !str_contains($httpFailureMessage,'pay_diagnostic'));
$sensitiveProviderBody=json_encode(['errors'=>[['code'=>'parameter_invalid','detail'=>'secret response text pay_sensitive_123','source'=>['pointer'=>'/data/attributes/payment_id'],'meta'=>['payment_id'=>'pay_sensitive_123']]]],JSON_THROW_ON_ERROR);
$providerHistoryHttp400=new PayMongoTestProvider('sk_test_OFFLINE_REFUND_HTTP400',static fn()=>['status'=>400,'body'=>$sensitiveProviderBody]);
try {
    $providerHistoryHttp400->refundsForPayment('pay_diagnostic');
    $historyHttp400Category=''; $historyHttp400Message='';
} catch (PayMongoRefundVerificationFailure $e) {
    $historyHttp400Category=$e->diagnosticCode; $historyHttp400Message=$e->getMessage();
}
$check('refund-history 400 exposes only sanitized provider code and allowlisted field',
    $historyHttp400Category==='refund_history_http_400_provider_parameter_invalid_field_payment_id'
    && !str_contains($historyHttp400Category,'secret') && !str_contains($historyHttp400Category,'pay_sensitive_123')
    && !str_contains($historyHttp400Message,'secret') && !str_contains($historyHttp400Message,'pay_sensitive_123'));
$providerHistoryAfter400=new PayMongoTestProvider('sk_test_OFFLINE_REFUND_AFTER400',static fn()=>[
    'status'=>400,'body'=>json_encode(['errors'=>[['code'=>'invalid-request','source'=>['parameter'=>'data.attributes.after'],'detail'=>'private detail']]],JSON_THROW_ON_ERROR),
]);
try {
    $providerHistoryAfter400->refundsForPayment('pay_diagnostic');
    $historyAfterCategory='';
} catch (PayMongoRefundVerificationFailure $e) {
    $historyAfterCategory=$e->diagnosticCode;
}
$check('refund-history diagnostics accept only known parameter names and normalize provider identifiers',
    $historyAfterCategory==='refund_history_http_400_provider_invalid_request_field_after');
$providerHistoryUnsafe400=new PayMongoTestProvider('sk_test_OFFLINE_REFUND_UNSAFE400',static fn()=>[
    'status'=>400,'body'=>json_encode(['errors'=>[['code'=>'pay_sensitive_123','source'=>['pointer'=>'/data/attributes/notes'],'detail'=>'sensitive body text']]],JSON_THROW_ON_ERROR),
]);
try {
    $providerHistoryUnsafe400->refundsForPayment('pay_diagnostic');
    $historyUnsafeCategory='';
} catch (PayMongoRefundVerificationFailure $e) {
    $historyUnsafeCategory=$e->diagnosticCode;
}
$check('unrecognized provider code and response field are omitted from the safe diagnostic',
    $historyUnsafeCategory==='refund_history_http_400');
$providerHistoryLimit400=new PayMongoTestProvider('sk_test_OFFLINE_REFUND_LIMIT400',static fn()=>[
    'status'=>400,'body'=>json_encode(['errors'=>[['code'=>'whsec_private_123','source'=>['pointer'=>'data/attributes/limit'],'detail'=>'sensitive body text']]],JSON_THROW_ON_ERROR),
]);
try {
    $providerHistoryLimit400->refundsForPayment('pay_diagnostic');
    $historyLimitCategory='';
} catch (PayMongoRefundVerificationFailure $e) {
    $historyLimitCategory=$e->diagnosticCode;
}
$check('secret-like provider codes are suppressed while exact no-leading-slash field pointers are allowlisted',
    $historyLimitCategory==='refund_history_http_400_field_limit');
$providerMalformedPayment=new PayMongoTestProvider('sk_test_OFFLINE_REFUND_MALFORMED',static fn()=>[
    'status'=>200,'body'=>json_encode(['data'=>['id'=>'pay_diagnostic','type'=>'payment','attributes'=>[
        'livemode'=>false,'status'=>'paid','amount'=>5000,'currency'=>'PHP'
    ]]],JSON_THROW_ON_ERROR)
]);
try {
    $providerMalformedPayment->retrievePayment('pay_diagnostic');
    $paymentResourceCategory='';
} catch (PayMongoRefundVerificationFailure $e) {
    $paymentResourceCategory=$e->diagnosticCode;
}
$check('missing authoritative payment fee is classified and remains unavailable', $paymentResourceCategory==='payment_resource_invalid');
$providerHistoryFailure=new PayMongoTestProvider('sk_test_OFFLINE_REFUND_HISTORY',static fn()=>['status'=>503,'body'=>'not logged or returned']);
try {
    $providerHistoryFailure->refundsForPayment('pay_diagnostic');
    $historyFailureCategory='';
} catch (PayMongoRefundVerificationFailure $e) {
    $historyFailureCategory=$e->diagnosticCode;
}
$check('refund-history HTTP failures receive a stable safe category', $historyFailureCategory==='refund_history_http_503');
$seedBooking = static function (int $id, string $amount) use ($db): void {
    $status='Confirmed'; $payment='Paid';
    $stmt=$db->prepare('INSERT INTO bookings (id,booking_status,payment_status,amount_paid) VALUES (?,?,?,?)');
    $stmt->bind_param('isss',$id,$status,$payment,$amount); $stmt->execute();
};
$seedPayment = static function (int $bookingId, string $paymentId, string $amount, ?int $fee, ?int $receiptBookingId=null) use ($db,&$state): void {
    $amountCents=paymongo_refund_money_to_cents($amount);
    $method='Card'; $success='Success';
    $stmt=$db->prepare('INSERT INTO payments (booking_id,transaction_id,payment_method,amount,status,fee_cents,net_amount_cents,fee_verified_at) VALUES (?,?,?,?,?,?,?,?)');
    $feeValue=$fee; $net=$fee===null?null:$amountCents-$fee; $verified=$fee===null?null:date('Y-m-d H:i:s.u');
    $stmt->bind_param('issssiis',$bookingId,$paymentId,$method,$amount,$success,$feeValue,$net,$verified); $stmt->execute();
    $attemptId=bin2hex(random_bytes(16)); $owner=$receiptBookingId??$bookingId;
    $stmt=$db->prepare('INSERT INTO pm_attempts (id,booking_id) VALUES (?,?)'); $stmt->bind_param('si',$attemptId,$owner); $stmt->execute();
    $currency='PHP'; $disposition='applied';
    $stmt=$db->prepare('INSERT INTO pm_receipts (payment_id,attempt_id,amount_cents,currency,disposition) VALUES (?,?,?,?,?)');
    $stmt->bind_param('ssiss',$paymentId,$attemptId,$amountCents,$currency,$disposition); $stmt->execute();
    $state['fees'][$paymentId]=['amount'=>$amountCents,'fee'=>$fee];
};
$saveCancellation = static function (array $snapshot,int $bookingId) use ($db): string {
    $reason='customer request'; $refund=paymongo_refund_snapshot_money((int)$snapshot['refund_cents']);
    $fee=paymongo_refund_snapshot_money((int)$snapshot['fee_cents']); $gross=paymongo_refund_snapshot_money((int)$snapshot['gross_cents']);
    $providerName='paymongo'; $providerStatus='ready'; $policy=PAYMONGO_REFUND_POLICY_VERSION; $pending='Pending'; $feePercent=0.0;
    $stmt=$db->prepare('INSERT INTO cancellations (booking_id,reason,refund_amount,fee_deducted,fee_percent,refund_gross_amount,refund_provider,refund_provider_status,refund_policy_version,status) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $stmt->bind_param('isddddssss',$bookingId,$reason,$refund,$fee,$feePercent,$gross,$providerName,$providerStatus,$policy,$pending); $stmt->execute();
    $cancellationId=(int)$db->insert_id;
    $snapshotId=paymongo_refund_persist_snapshot($db,$snapshot,$bookingId,$cancellationId,null);
    $stmt=$db->prepare('UPDATE cancellations SET refund_snapshot_id=? WHERE id=?'); $stmt->bind_param('si',$snapshotId,$cancellationId); $stmt->execute();
    return $snapshotId;
};

$seedBooking(9,'50.00'); $seedPayment(9,'pay_mixed_collation','50.00',100);
$mixedSnapshot=paymongo_refund_prepare_snapshot($db,9,$provider);
$mixedSnapshotId=$saveCancellation($mixedSnapshot,9);
$details=$db->prepare("SELECT (SELECT COALESCE(MAX(rs.prior_refund_cents),0)/100 FROM pm_refund_snapshots rs WHERE BINARY rs.id=BINARY cx.refund_snapshot_id) AS prior_amount, EXISTS(SELECT 1 FROM pm_refund_attempts at JOIN pm_refund_allocations al ON al.id=at.allocation_id WHERE BINARY al.snapshot_id=BINARY cx.refund_snapshot_id AND at.status IN ('creating','uncertain','pending','processing','succeeded','review')) AS provider_locked FROM cancellations cx WHERE cx.booking_id=9");
$details->execute(); $mixedDetails=$details->get_result()->fetch_assoc();
$check('mixed-collation payment receipts and refund details compare opaque IDs byte-for-byte', $mixedSnapshot['provider']==='paymongo' && $mixedSnapshotId!=='' && (float)$mixedDetails['prior_amount']===0.0 && (int)$mixedDetails['provider_locked']===0);
$refundSource=(string)file_get_contents(dirname(__DIR__).'/actions/admin/get_booking_details.php');
$assertedDetails=str_contains($refundSource,'BINARY rs.id=BINARY cx.refund_snapshot_id') && str_contains($refundSource,'BINARY al.snapshot_id=BINARY cx.refund_snapshot_id');
$check('admin View Details uses byte-exact snapshot comparisons for mixed collations', $assertedDetails);
$customerDetailsSource=(string)file_get_contents(dirname(__DIR__).'/actions/user/get_my_booking_details.php');
$check('customer View Details uses byte-exact snapshot comparisons for mixed collations', str_contains($customerDetailsSource,'BINARY rs.id=BINARY cx.refund_snapshot_id'));

try {
    $seedBooking(1,'50.00'); $seedPayment(1,'pay_crossbooking','50.00',null,99);
    $check('cross-booking pay_ reference is rejected by receipt ownership guard', $throws(static fn()=>paymongo_refund_is_booking($db,1)));

    $seedBooking(2,'50.00'); $seedPayment(2,'pay_fee_unknown','50.00',null);
    $check('unknown provider fee blocks preview instead of estimating zero', $throws(static fn()=>paymongo_refund_prepare_snapshot($db,2,$provider)));

    $seedBooking(3,'50.00'); $seedPayment(3,'pay_pagination','50.00',200);
    $state['list_failure']=true;
    $check('malformed refund pagination blocks fee preview', $throws(static fn()=>paymongo_refund_prepare_snapshot($db,3,$provider)));
    $state['list_failure']=false;

    $seedBooking(4,'100.00'); $seedPayment(4,'pay_prior','100.00',700);
    $state['refunds']['ref_prior']=['id'=>'ref_prior','type'=>'refund','attributes'=>['amount'=>1000,'currency'=>'PHP','livemode'=>false,'notes'=>'manual prior refund','payment_id'=>'pay_prior','reason'=>'others','status'=>'succeeded']];
    $priorSnapshot=paymongo_refund_prepare_snapshot($db,4,$provider);
    $againSnapshot=paymongo_refund_prepare_snapshot($db,4,$provider);
    $check('fee is deducted once across repeated partial-refund previews', $priorSnapshot['refund_cents']===8300 && $againSnapshot['refund_cents']===8300 && $priorSnapshot['prior_refund_cents']===1000);

    $seedBooking(5,'50.00'); $seedPayment(5,'pay_external_pending','50.00',200);
    $state['refunds']['ref_external']=['id'=>'ref_external','type'=>'refund','attributes'=>['amount'=>1000,'currency'=>'PHP','livemode'=>false,'notes'=>'external','payment_id'=>'pay_external_pending','reason'=>'others','status'=>'pending']];
    $check('external pending refund fences a new preview', $throws(static fn()=>paymongo_refund_prepare_snapshot($db,5,$provider)));

    $seedBooking(6,'50.00'); $seedPayment(6,'pay_lost_post','50.00',100);
    $lostSnapshot=paymongo_refund_prepare_snapshot($db,6,$provider); $lostId=$saveCancellation($lostSnapshot,6);
    $state['lose_next_post']=true; $beforePosts=$state['posts'];
    $first=paymongo_refund_admin_process($db,6,7,$provider,true,false);
    $afterLostPosts=$state['posts'];
    $check('lost create response is found by stable note and remains processing', $first['status']==='processing' && $afterLostPosts===$beforePosts+1);
    $second=paymongo_refund_admin_process($db,6,7,$provider,false,false);
    $check('read-only reconciliation never creates a duplicate refund', $second['status']==='processing' && $state['posts']===$afterLostPosts);
    foreach ($state['refunds'] as &$resource) if ($resource['attributes']['payment_id']==='pay_lost_post') $resource['attributes']['status']='succeeded';
    unset($resource);
    $third=paymongo_refund_admin_process($db,6,7,$provider,false,false);
    $booking=$db->query('SELECT booking_status,payment_status FROM bookings WHERE id=6')->fetch_assoc();
    $check('booking is marked refunded only after exact provider success', $third['processed'] && $booking['booking_status']==='Cancelled' && $booking['payment_status']==='Refunded');
    $check('refund attempt claim and completion are audited', (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE event_type IN ('booking.refund_attempt_claimed','booking.refund_processed')")->fetch_row()[0]===2);

    $seedBooking(7,'100.00'); $seedPayment(7,'pay_multi_a','50.00',100); $seedPayment(7,'pay_multi_b','50.00',200);
    $multi=paymongo_refund_prepare_snapshot($db,7,$provider); $multiId=$saveCancellation($multi,7);
    $allocations=$db->query("SELECT id,payment_id FROM pm_refund_allocations WHERE snapshot_id='{$db->real_escape_string($multiId)}' ORDER BY payment_id")->fetch_all(MYSQLI_ASSOC);
    $allAttempts=[];
    foreach ($allocations as $allocation) {
        $stmt=$db->prepare('SELECT id,correlation_note FROM pm_refund_attempts WHERE allocation_id=?'); $stmt->bind_param('s',$allocation['id']); $stmt->execute();
        $allAttempts[$allocation['payment_id']]=[$allocation,$stmt->get_result()->fetch_assoc()];
    }
    [$allocA,$attemptA]=$allAttempts['pay_multi_a']; [$allocB,$attemptB]=$allAttempts['pay_multi_b'];
    $succeeded='succeeded'; $successfulId='ref_multi_a';
    $stmt=$db->prepare('UPDATE pm_refund_attempts SET status=?,provider_refund_id=? WHERE id=?'); $stmt->bind_param('sss',$succeeded,$successfulId,$attemptA['id']); $stmt->execute();
    $state['refunds'][$successfulId]=['id'=>$successfulId,'type'=>'refund','attributes'=>['amount'=>4800,'currency'=>'PHP','livemode'=>false,'notes'=>$attemptA['correlation_note'],'payment_id'=>'pay_multi_a','reason'=>'others','status'=>'succeeded']];
    $failed='failed'; $errorCode='fixture_declined';
    $stmt=$db->prepare('UPDATE pm_refund_attempts SET status=?,error_code=? WHERE id=?'); $stmt->bind_param('sss',$failed,$errorCode,$attemptB['id']); $stmt->execute();
    for ($i=0;$i<3;$i++) {
        $id=bin2hex(random_bytes(16)); $note='SV360REF-'.$id; $allocationId=$allocB['id'];
        $stmt=$db->prepare("INSERT INTO pm_refund_attempts (id,allocation_id,correlation_note,status,error_code) VALUES (?,?,?,'failed',?)");
        $stmt->bind_param('ssss',$id,$allocationId,$note,$errorCode); $stmt->execute();
    }
    $oldPosts=$state['posts'];
    $multiResult=paymongo_refund_admin_process($db,7,7,$provider,true,true);
    $successCount=(int)$db->query("SELECT COUNT(*) FROM pm_refund_attempts WHERE allocation_id='{$db->real_escape_string($allocA['id'])}' AND status='succeeded'")->fetch_row()[0];
    $retryCount=(int)$db->query("SELECT COUNT(*) FROM pm_refund_attempts WHERE allocation_id='{$db->real_escape_string($allocB['id'])}' AND status='succeeded'")->fetch_row()[0];
    $check('multi-payment retry after three failures succeeds without refunding a successful allocation twice', $multiResult['processed'] && $state['posts']===$oldPosts+1 && $successCount===1 && $retryCount===1);
    $check('each allocation has exactly one success before booking completion', (int)$db->query("SELECT COUNT(*) FROM (SELECT allocation_id,SUM(status='succeeded') AS n FROM pm_refund_attempts WHERE allocation_id IN ('{$db->real_escape_string($allocA['id'])}','{$db->real_escape_string($allocB['id'])}') GROUP BY allocation_id HAVING n<>1) x")->fetch_row()[0]===0);

    $seedBooking(8,'10.00'); $seedPayment(8,'pay_legacy','10.00',100);
    $legacy=paymongo_refund_prepare_snapshot($db,8,$provider);
    $legacyCancellation=1; // The old pending request intentionally has no immutable snapshot.
    $stmt=$db->prepare("INSERT INTO cancellations (booking_id,reason,refund_amount,fee_deducted,fee_percent,refund_provider,status) VALUES (8,'old request',10.00,0,0,'paymongo','Pending')"); $stmt->execute();
    $check('legacy full-refund request cannot enter PayMongo create path without fresh snapshot', $throws(static fn()=>paymongo_refund_admin_process($db,8,7,$provider,true,false)));
    $adminSource=file_get_contents(dirname(__DIR__).'/actions/admin/update_booking_status.php');
    $ownershipGuardPosition=is_string($adminSource)?strpos($adminSource,'catch (Throwable $ownershipError)'):false;
    $manualBranchPosition=is_string($adminSource)?strpos($adminSource,'// The original manual / walk-in refund branch below remains unchanged'):false;
    $check('PayMongo payment ownership failure exits before legacy manual refund branch', $ownershipGuardPosition!==false && $manualBranchPosition!==false && $ownershipGuardPosition<$manualBranchPosition);

} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR ' . get_class($error) . ': ' . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n");
    $failures[]='unexpected isolated test error';
} finally {
    $db->query('SET FOREIGN_KEY_CHECKS=0');
    $db->close();
    $admin->query("DROP DATABASE IF EXISTS `{$database}`");
    $admin->close();
}
fwrite(STDOUT, "PayMongo refund isolated checks: {$checks}; failures: " . count($failures) . "\n");
if ($failures) { foreach ($failures as $failure) fwrite(STDERR, "- {$failure}\n"); exit(1); }
