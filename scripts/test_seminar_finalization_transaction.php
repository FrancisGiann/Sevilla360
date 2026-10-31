<?php
/** Synthetic integration test. Requires an explicitly supplied isolated MariaDB socket; never loads application DB config. */
declare(strict_types=1);
$socket = getenv('SEVILLA_TEST_DB_SOCKET');
if (!$socket || !str_starts_with($socket, '/tmp/')) { fwrite(STDERR, "Set SEVILLA_TEST_DB_SOCKET to an isolated /tmp MariaDB socket.\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = dirname(__DIR__);
require_once $root . '/includes/seminars.php';
require_once $root . '/includes/seminar_payments.php';
require_once $root . '/includes/hotel_rooms.php';
// Load the actual endpoint functions without dispatching requests, sessions, mail or application configuration.
$endpoint = file_get_contents($root . '/actions/admin/seminars.php');
$from = strpos($endpoint, 'function seminar_json_body(');
$to = strpos($endpoint, "\ntry {\n    if (\$_SERVER", $from);
if ($from === false || $to === false) throw new RuntimeException('Endpoint function boundaries changed.');
eval(substr($endpoint, $from, $to - $from));
$_SESSION = ['user_id' => 1, 'role' => 'admin'];
$dbName = 'seminar_finalize_test_' . bin2hex(random_bytes(6));
function connectTest(?string $name = null): mysqli {
    global $socket, $dbName;
    $conn = new mysqli('localhost', 'root', '', $name ?? $dbName, 0, $socket);
    $conn->set_charset('utf8mb4'); return $conn;
}
$admin = new mysqli('localhost', 'root', '', '', 0, $socket);
$admin->query("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException('FAIL ' . $label); $checks++; echo "PASS $label\n"; }
function scalar(mysqli $c, string $sql): mixed { return array_values($c->query($sql)->fetch_assoc())[0]; }
function input(array $overrides = []): array { return array_replace(['name' => 'Synthetic 100-person seminar','agreed_price' => null,'hall_venue_id' => 1,'hall_start' => '2035-06-01','hall_end' => '2035-06-02','hotel_check_in' => '2035-06-01','hotel_check_out' => '2035-06-03','room_ids' => range(100,119)], $overrides); }
function roster(): array { $people=[]; for ($i=0;$i<100;$i++) $people[]=['full_name'=>'Synthetic '.($i+1),'gender'=>'male','location'=>'Test','contact'=>'']; return $people; }
function rooms(mysqli $c, int $id): string { return json_encode($c->query("SELECT venue_id,resource_kind,start_date,end_date,allow_mixed_gender FROM seminar_reservations WHERE seminar_id=$id ORDER BY venue_id")->fetch_all(MYSQLI_ASSOC)); }
function bookingRequest(string $route, string $role, array $extra = []): string {
    global $dbName,$socket,$root;
    $post=array_replace(['source'=>$role==='customer'?'online':'walkin','start_date'=>'2035-06-01','end_date'=>'2035-06-03','venue_id'=>'100','room_type'=>'Dorm','room_name'=>'Synthetic Wing','guests'=>'1','policy_consent'=>'1','contact_phone'=>'09123456789','guest_phone'=>'09123456789','guest_name'=>'Synthetic Walkin','guest_email'=>'synthetic@example.invalid','building_name'=>'Synthetic Wing','check_in'=>'2035-06-01','check_out'=>'2035-06-03'], $extra);
    $payload=base64_encode(json_encode(['database'=>$dbName,'role'=>$role,'post'=>$post]));
    $command=[PHP_BINARY,'-d','mysqli.default_socket='.$socket,'-d','session.save_path=/tmp',$root.'/scripts/fixtures/seminar_booking_request.php',$route,$payload];
    $process=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
    if(!is_resource($process))throw new RuntimeException('Unable to run booking request fixture.');
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);foreach($pipes as $pipe)fclose($pipe);$code=proc_close($process);
    if($code!==0 || $err!=='')throw new RuntimeException("Booking request failed: $out $err");
    return $out;
}
function expectConflict(callable $operation): SeminarInventoryConflict { try {$operation();} catch (SeminarInventoryConflict $e) {return $e;} throw new RuntimeException('Expected inventory conflict.'); }
try {
    $c=connectTest();
    $ddl = [
        "CREATE TABLE users(id INT PRIMARY KEY,role VARCHAR(30),status VARCHAR(30),auth_version INT NOT NULL DEFAULT 0) ENGINE=InnoDB",
        'CREATE TABLE staff(user_id INT PRIMARY KEY,status VARCHAR(30)) ENGINE=InnoDB',
        'CREATE TABLE customers(id INT PRIMARY KEY,user_id INT,first_name VARCHAR(40),last_name VARCHAR(40),email VARCHAR(100),phone VARCHAR(30)) ENGINE=InnoDB',
        'CREATE TABLE venues(id INT PRIMARY KEY,name VARCHAR(100),category VARCHAR(30),status VARCHAR(30)) ENGINE=InnoDB',
        'CREATE TABLE hotel_rooms(venue_id INT PRIMARY KEY,room_number VARCHAR(20),room_type VARCHAR(30),floor_label VARCHAR(30),max_capacity INT,base_capacity INT,nightly_rate DECIMAL(10,2),room_group_id INT) ENGINE=InnoDB',
        'CREATE TABLE event_halls(venue_id INT PRIMARY KEY,capacity_theater INT,capacity_classroom INT,capacity_banquet INT) ENGINE=InnoDB',
        'CREATE TABLE bookings(id INT AUTO_INCREMENT PRIMARY KEY,venue_id INT,booking_status VARCHAR(30),source VARCHAR(30),start_date DATE,end_date DATE,payment_step_token CHAR(32),payment_step_expires_at DATETIME,payment_step_seen_at DATETIME) ENGINE=InnoDB',
        'CREATE TABLE booking_rooms(id INT AUTO_INCREMENT PRIMARY KEY,booking_id INT,venue_id INT,start_date DATE,end_date DATE) ENGINE=InnoDB',
        'CREATE TABLE maintenance(id INT AUTO_INCREMENT PRIMARY KEY,venue_id INT,is_blocking INT,status VARCHAR(30),start_date DATE,end_date DATE) ENGINE=InnoDB',
        'CREATE TABLE booking_locks(id INT AUTO_INCREMENT PRIMARY KEY,venue_id INT,session_id VARCHAR(100),source VARCHAR(30),expires_at DATETIME,start_date DATE,end_date DATE) ENGINE=InnoDB',
        'CREATE TABLE hotel_room_groups(id INT PRIMARY KEY,building_name VARCHAR(100),display_name VARCHAR(30),sort_order INT,room_type_code VARCHAR(30)) ENGINE=InnoDB',
        'CREATE TABLE hotel_room_types(type_code VARCHAR(30) PRIMARY KEY,active INT) ENGINE=InnoDB',
        'CREATE TABLE payment_step_exits(token CHAR(32) PRIMARY KEY,booking_id INT) ENGINE=InnoDB',
        'CREATE TABLE audit_logs(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,module VARCHAR(100),action VARCHAR(500),ip_address VARCHAR(100)) ENGINE=InnoDB'
    ];
    foreach($ddl as $sql)$c->query($sql);
    foreach(['028_seminars.sql','030_seminar_agreed_price.sql','031_seminar_payments.sql'] as $migration) foreach(explode(';',file_get_contents($root.'/migrations/'.$migration)) as $sql) if(trim($sql)!=='')$c->query($sql);
    $c->query("INSERT INTO users(id,role,status) VALUES(1,'admin','Active'),(2,'customer','Active')");
    $c->query("INSERT INTO staff VALUES(1,'Active')");
    $c->query("INSERT INTO customers VALUES(2,2,'Synthetic','Customer','synthetic@example.invalid','09123456789')");
    $c->query("INSERT INTO venues VALUES(1,'Synthetic Hall','Event Hall','Available'),(2,'Other Hall','Event Hall','Available')");
    $c->query('INSERT INTO event_halls VALUES(1,200,120,100),(2,200,120,100)');
    $c->query("INSERT INTO hotel_room_groups VALUES(1,'Synthetic Wing','Dorm',1,'dorm')"); $c->query("INSERT INTO hotel_room_types VALUES('dorm',1)");
    for($id=100;$id<141;$id++) { $c->query("INSERT INTO venues VALUES($id,'Synthetic Wing','Hotel Room','Available')"); $c->query("INSERT INTO hotel_rooms VALUES($id,'$id','Dorm','1',5,5,100,1)"); }
    check(scalar($c,'SELECT @@tx_isolation')==='REPEATABLE-READ','test server defaults to REPEATABLE-READ');
    $allocation=null; $first=seminar_mutate_reservation($c,input(),null,roster(),$allocation);
    check(count($allocation['assignments'])===100 && count(array_count_values($allocation['assignments']))===20,'100 attendees assigned to 20 five-bed rooms');
    check(!seminar_has_resource_conflict($c,100,'2035-06-01','2035-06-03'),'saved draft does not reserve a physical room');
    check(!seminar_has_resource_conflict($c,1,'2035-06-01','2035-06-02'),'saved draft does not reserve hall dates');
    $units=hotel_available_group_units($c,'2035-06-01','2035-06-03','customer');
    check(count($units[1])===41,'all draft selections remain in customer group inventory');
    seminar_assert_hotel_room_available($c,100,'2035-06-01','2035-06-03',0);
    check(true,'walk-in/admin add-on assertion permits a draft selection');
    foreach(['customer','admin'] as $role){
        $out=bookingRequest('lock_dates',$role);
        check(str_starts_with($out,'Success|'),"actual $role date-lock endpoint permits draft room");
        check((int)scalar($c,'SELECT COUNT(*) FROM booking_locks')===($role==='customer'?0:1),"actual $role endpoint preserves selection-only online and timed walk-in behavior");
        $c->query('DELETE FROM booking_locks');
    }
    $addon=json_decode(bookingRequest('lock_addon_rooms','admin',['groups'=>json_encode([['building_name'=>'Synthetic Wing','room_type'=>'Dorm','quantity'=>41]])]),true);
    check(($addon['success']??false)===true && ($addon['held_count']??0)===41,'actual walk-in add-on endpoint can hold draft-selected rooms');
    $c->query('DELETE FROM booking_locks');
    $availability=json_decode(bookingRequest('get_room_availability','customer'),true);
    check(($availability['available']??0)===41,'actual availability endpoint counts draft selections as available');
    $c->begin_transaction();
    check(manual_payment_claim_inventory_locked($c,['id'=>9999,'venue_id'=>100,'start_date'=>'2035-06-01','end_date'=>'2035-06-03']),'current customer proof-claim helper permits draft selections');
    $c->rollback();
    $second=seminar_mutate_reservation($c,input(['name'=>'Competing draft']),null,roster(),$allocation);
    check($second!==$first,'another draft can prefer the same hall and rooms');
    // A customer temporarily holding one of the draft rooms wins over later finalization.
    $c->query("INSERT INTO bookings(id,venue_id,booking_status,source,start_date,end_date) VALUES(9000,100,'Awaiting Proof','Online','2035-06-01','2035-06-03')");
    booking_begin_mutation_transaction($c);
    $paymentExpiry=manual_payment_acquire_step_hold_locked($c,['id'=>9000,'venue_id'=>100,'start_date'=>'2035-06-01','end_date'=>'2035-06-03'],str_repeat('a',32));$c->commit();
    check($paymentExpiry!==null && (int)scalar($c,'SELECT COUNT(*) FROM booking_locks')===1,'current payment-entry helper acquires hold while seminar is draft');
    $error=expectConflict(fn()=>seminar_finalize($c,$first));
    check($error->conflicts[0]['venue_id']===100 && str_contains($error->getMessage(),'Room 100'),'finalization names conflicting room and dates');
    check(scalar($c,"SELECT status FROM seminars WHERE id=$first")==='draft','failed finalization keeps recoverable draft');
    $c->query('DELETE FROM booking_locks');$c->query('DELETE FROM bookings WHERE id=9000');
    $result=seminar_finalize($c,$first);
    check(scalar($c,"SELECT status FROM seminars WHERE id=$first")==='finalized' && !$result['warning'],'finalization reserves without payment or agreed price prerequisite');
    check(count(hotel_available_group_units($c,'2035-06-01','2035-06-03','other')[1])===21,'all 20 finalized rooms excluded from public inventory');
    foreach(['customer','admin'] as $role){
        $out=bookingRequest('lock_dates',$role);
        check(str_starts_with($out,'Error|') && (str_contains($out,'booked')||str_contains($out,'locked')), "actual $role date-lock endpoint rejects finalized room");
    }
    check((int)scalar($c,'SELECT COUNT(*) FROM booking_locks')===0,'finalized room rejection leaves no temporary locks');
    check(str_contains(bookingRequest('submit_online','customer'),'reserved for a seminar'),'actual customer submission rejects finalized room');
    check(str_contains(bookingRequest('submit_walkin','admin'),'reserved for a seminar'),'actual walk-in submission rejects finalized room');
    $addon=json_decode(bookingRequest('lock_addon_rooms','admin',['groups'=>json_encode([['building_name'=>'Synthetic Wing','room_type'=>'Dorm','quantity'=>22]])]),true);
    check(($addon['success']??true)===false && (int)scalar($c,'SELECT COUNT(*) FROM booking_locks')===0,'actual walk-in add-on endpoint refuses quantity requiring finalized rooms');
    $availability=json_decode(bookingRequest('get_room_availability','customer'),true);
    check(($availability['available']??0)===21,'actual availability endpoint excludes finalized rooms');
    $c->begin_transaction();
    check(!manual_payment_claim_inventory_locked($c,['id'=>9999,'venue_id'=>100,'start_date'=>'2035-06-01','end_date'=>'2035-06-03']),'current customer proof-claim helper rejects finalized room');
    $c->rollback();
    booking_begin_mutation_transaction($c);
    check(manual_payment_acquire_step_hold_locked($c,['id'=>9999,'venue_id'=>100,'start_date'=>'2035-06-01','end_date'=>'2035-06-03'],str_repeat('b',32))===null,'current payment-entry helper cannot hold a finalized seminar room');
    $c->rollback();
    check(seminar_has_resource_conflict($c,100,'2035-06-02','2035-06-04'),'overlapping hotel stay rejected');
    check(!seminar_has_resource_conflict($c,100,'2035-06-03','2035-06-04'),'hotel checkout day available to next check-in');
    check(seminar_has_resource_conflict($c,1,'2035-06-02','2035-06-02') && !seminar_has_resource_conflict($c,1,'2035-06-03','2035-06-03'),'hall dates remain inclusive');
    $error=expectConflict(fn()=>seminar_finalize($c,$second));
    check(count($error->conflicts)===21 && str_contains($error->getMessage(),'Edit reservations'),'competing draft receives all hall and room replacement conflicts');
    $before=rooms($c,$first);
    $c->query("INSERT INTO bookings(venue_id,booking_status,source,start_date,end_date) VALUES(120,'Confirmed','Online','2035-06-01','2035-06-03')");
    expectConflict(fn()=>seminar_mutate_reservation($c,input(['room_ids'=>range(120,139),'reassign_attendees'=>true]),$first));
    check(rooms($c,$first)===$before && scalar($c,"SELECT status FROM seminars WHERE id=$first")==='finalized','conflicting finalized amendment preserves original reservation');
    $c->query('DELETE FROM bookings');
    try {seminar_mutate_reservation($c,input(['room_ids'=>range(120,139)]),$first);$rejected=false;}catch(InvalidArgumentException $e){$rejected=true;}
    check($rejected && rooms($c,$first)===$before,'room replacement requires explicit reassignment confirmation');
    seminar_mutate_reservation($c,input(['room_ids'=>range(120,139),'reassign_attendees'=>true]),$first);
    check(!seminar_has_resource_conflict($c,100,'2035-06-01','2035-06-03') && seminar_has_resource_conflict($c,120,'2035-06-01','2035-06-03'),'successful replacement atomically releases old and secures new rooms');
    check((int)scalar($c,"SELECT COUNT(*) FROM seminar_attendees WHERE seminar_id=$first AND assigned_venue_id BETWEEN 120 AND 139")===100,'replacement regenerates all 100 valid assignments');
    $before=rooms($c,$first);
    try {seminar_mutate_reservation($c,input(['room_ids'=>[140],'reassign_attendees'=>true]),$first);$rejected=false;}catch(InvalidArgumentException $e){$rejected=true;}
    check($rejected && rooms($c,$first)===$before,'insufficient replacement capacity rolls entire amendment back');
    $c->query("UPDATE seminar_reservations SET allow_mixed_gender=1 WHERE seminar_id=$first AND venue_id=120");
    seminar_mutate_reservation($c,input(['room_ids'=>range(120,139),'hall_start'=>'2035-07-01','hall_end'=>'2035-07-02','hotel_check_in'=>'2035-07-01','hotel_check_out'=>'2035-07-03']),$first);
    check(!seminar_has_resource_conflict($c,120,'2035-06-01','2035-06-03') && seminar_has_resource_conflict($c,120,'2035-07-01','2035-07-03'),'date amendment releases only old dates and reserves new interval');
    check((int)scalar($c,"SELECT allow_mixed_gender FROM seminar_reservations WHERE seminar_id=$first AND venue_id=120")===1,'date edits preserve existing mixed-gender approval');
    seminar_mutate_reservation($c,input(['room_ids'=>range(120,139),'agreed_price'=>'1000','hall_start'=>'2035-07-01','hall_end'=>'2035-07-02','hotel_check_in'=>'2035-07-01','hotel_check_out'=>'2035-07-03']),$first);
    $c->query("INSERT INTO seminar_payments(seminar_id,amount,payment_method,idempotency_key,created_by) VALUES($first,500,'Cash','synthetic-payment',1)");
    try {seminar_mutate_reservation($c,input(['agreed_price'=>'400']),$first);$rejected=false;}catch(InvalidArgumentException $e){$rejected=true;}
    check($rejected && scalar($c,"SELECT agreed_price FROM seminars WHERE id=$first")==='1000.00','posted-payment price floor preserved during finalized edit');
    seminar_cancel($c,$first);
    check(!seminar_has_resource_conflict($c,120,'2035-07-01','2035-07-03'),'cancellation releases finalized rooms');
    check((int)scalar($c,"SELECT COUNT(*) FROM seminar_payments WHERE seminar_id=$first")===1,'cancellation retains payment history');
    // Finalize two competing drafts concurrently; exact application transaction must allow only one.
    $competing=[];for($i=0;$i<2;$i++)$competing[]=seminar_mutate_reservation($c,input(['name'=>'Concurrent '. $i]),null,roster(),$allocation);
    $workers=[];
    foreach($competing as $id){
        [$parent,$child]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);
        $pid=pcntl_fork(); if($pid===-1)throw new RuntimeException('fork failed');
        if($pid===0){fclose($parent);fgets($child);try{$worker=connectTest();seminar_finalize($worker,$id);$out='finalized';}catch(SeminarInventoryConflict $e){$out='conflict';}catch(Throwable $e){$out='error:'.$e->getMessage();}fwrite($child,$out."\n");fflush($child);posix_kill(getmypid(),SIGKILL);}
        fclose($child);stream_set_timeout($parent,20);$workers[]=[$parent,$pid];
    }
    foreach($workers as [$pipe,$pid]){fwrite($pipe,"go\n");fflush($pipe);}
    $outcomes=[];foreach($workers as [$pipe,$pid]){$outcomes[]=trim((string)fgets($pipe));fclose($pipe);pcntl_waitpid($pid,$status);}sort($outcomes);
    check($outcomes===['conflict','finalized'],'concurrent finalizations produce one winner and one recoverable conflict');
    foreach($competing as $id)seminar_cancel($c,$id);
    // Reproduce the previous stale-snapshot schedule: customer owns room row while finalize waits.
    $late=seminar_mutate_reservation($c,input(),null,roster(),$allocation);
    $holder=connectTest();$holder->begin_transaction();$holder->query('SELECT id FROM venues WHERE id=100 FOR UPDATE')->free();
    [$parent,$child]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);
    $pid=pcntl_fork();if($pid===0){
        fclose($parent);
        class ProbeConnection extends mysqli {public mixed $signal; public function prepare(string $query): mysqli_stmt|false {if($query==='SELECT id FROM venues WHERE id=? FOR UPDATE'){fwrite($this->signal,"locking\n");fflush($this->signal);}return parent::prepare($query);}}
        try{$worker=new ProbeConnection('localhost','root','',$dbName,0,$socket);$worker->signal=$child;seminar_finalize($worker,$late);$out='incorrect-success';}catch(SeminarInventoryConflict $e){$out='conflict';}catch(Throwable $e){$out='error:'.$e->getMessage();}fwrite($child,$out."\n");fflush($child);posix_kill(getmypid(),SIGKILL);
    }
    fclose($child);stream_set_timeout($parent,20);check(trim((string)fgets($parent))==='locking','finalizer reaches mutex acquisition after reading draft resources');
    $holder->query("INSERT INTO bookings(venue_id,booking_status,source,start_date,end_date) VALUES(100,'Confirmed','Online','2035-06-01','2035-06-03')");$holder->commit();
    $out='';while(($line=fgets($parent))!==false){$line=trim($line);if($line!=='locking'){$out=$line;break;}}fclose($parent);pcntl_waitpid($pid,$status);
    check($out==='conflict' && scalar($c,"SELECT status FROM seminars WHERE id=$late")==='draft','late committed customer booking detected after venue wait under default REPEATABLE-READ');
    $c->query('DELETE FROM bookings');
    seminar_finalize($c,$late);check(scalar($c,"SELECT status FROM seminars WHERE id=$late")==='finalized','draft can recover and finalize after conflict is resolved');
    // Existing finalized edits also acquire a fresh view after a room mutex wait.
    $before=rooms($c,$late);
    $holder=connectTest();$holder->begin_transaction();$holder->query('SELECT id FROM venues WHERE id=120 FOR UPDATE')->free();
    [$parent,$child]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);
    $pid=pcntl_fork();if($pid===0){
        fclose($parent);
        class EditProbeConnection extends mysqli {public mixed $signal;public function prepare(string $query): mysqli_stmt|false {if($query==='SELECT id FROM venues WHERE id=? FOR UPDATE'){fwrite($this->signal,"locking\n");fflush($this->signal);}return parent::prepare($query);}}
        try{$worker=new EditProbeConnection('localhost','root','',$dbName,0,$socket);$worker->signal=$child;seminar_mutate_reservation($worker,input(['room_ids'=>range(120,139),'reassign_attendees'=>true]),$late);$out='incorrect-success';}catch(SeminarInventoryConflict $e){$out='conflict';}catch(Throwable $e){$out='error:'.$e->getMessage();}fwrite($child,$out."\n");fflush($child);posix_kill(getmypid(),SIGKILL);
    }
    fclose($child);stream_set_timeout($parent,20);check(trim((string)fgets($parent))==='locking','existing edit reads payment floor then reaches venue locks');
    $holder->query("INSERT INTO bookings(venue_id,booking_status,source,start_date,end_date) VALUES(120,'Confirmed','Walkin','2035-06-01','2035-06-03')");$holder->commit();
    $out='';while(($line=fgets($parent))!==false){$line=trim($line);if($line!=='locking'){$out=$line;break;}}fclose($parent);pcntl_waitpid($pid,$status);
    check($out==='conflict' && rooms($c,$late)===$before,'late committed walk-in booking defeats finalized replacement without releasing old rooms');
    $c->query('DELETE FROM bookings');
    seminar_cancel($c,$late);
    // Pending Event Hall inquiries and provisional add-ons keep their existing nonexclusive semantics.
    $c->query("INSERT INTO bookings(id,venue_id,booking_status,source,start_date,end_date) VALUES(500,1,'Pending','Online','2035-06-01','2035-06-02')");
    $c->query("INSERT INTO booking_rooms(booking_id,venue_id,start_date,end_date) VALUES(500,100,'2035-06-01','2035-06-03')");
    $inquiry=seminar_mutate_reservation($c,input(),null,roster(),$allocation);seminar_finalize($c,$inquiry);
    check(scalar($c,"SELECT status FROM seminars WHERE id=$inquiry")==='finalized','pending Event Hall inquiry and provisional add-ons remain nonexclusive');
    seminar_cancel($c,$inquiry);$c->query('DELETE FROM booking_rooms');$c->query('DELETE FROM bookings');
    // Existing draft records are kept but immediately stop blocking after the status predicate change.
    check((int)scalar($c,"SELECT COUNT(*) FROM seminar_reservations WHERE seminar_id=$second")===21 && !seminar_has_resource_conflict($c,100,'2035-06-01','2035-06-03'),'existing draft reservation records preserved as nonblocking preferences without migration');
    echo "Passed $checks seminar finalization transaction checks.\n";
} finally {
    $cleanup=connectTest();$cleanup->query("DROP DATABASE `$dbName`");echo "Removed synthetic database $dbName.\n";
}
