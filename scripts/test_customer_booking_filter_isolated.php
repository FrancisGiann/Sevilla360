<?php
// Dedicated CLI fixture: no app configuration or existing database is loaded.
if (PHP_SAPI !== 'cli' || $argc !== 3) exit(2);
$socket = realpath($argv[1]); $root = realpath($argv[2]);
if (!$socket || !$root || !str_starts_with($root, sys_get_temp_dir().'/sevilla360-booking-concurrency.') || !str_starts_with($socket, $root.'/')) exit(2);
require_once dirname(__DIR__).'/includes/customer_booking_status.php';
$db = new mysqli('localhost','root','',null,0,$socket);
$db->set_charset('utf8mb4');$db->query("SET time_zone='+08:00'");
$name='s360_filter_'.bin2hex(random_bytes(5));$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{$checks++;if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";};
try{
 $db->query("CREATE DATABASE `$name`");$db->select_db($name);
 $db->query('CREATE TABLE bookings(id INT PRIMARY KEY,customer_id INT,booking_status VARCHAR(30),payment_status VARCHAR(30),end_date DATE) ENGINE=InnoDB');
 $today=$db->query('SELECT CURDATE()')->fetch_row()[0];
 $rows=[[1,1,'Pending','Unpaid','2099-01-01'],[2,1,'Confirmed','Partial','2099-01-01'],[3,1,'Confirmed','Paid','2099-01-01'],[4,1,'Confirmed','Unpaid','2099-01-01'],[5,1,'Confirmed','Paid','2000-01-01'],[6,1,'Completed','Paid','2099-01-01'],[7,1,'Cancelled','Refunded','2000-01-01'],[8,1,'Pending','Unpaid','2000-01-01'],[9,1,'Confirmed','Partial',$today],[10,1,'Pending','Partial','2099-01-01'],[100,2,'Pending','Unpaid','2099-01-01']];
 for($id=11;$id<=35;$id++)$rows[]=[$id,1,'Cancelled','Unpaid','2099-01-01'];
 $insert=$db->prepare('INSERT INTO bookings VALUES (?,?,?,?,?)');foreach($rows as $row){[$id,$customer,$status,$paid,$end]=$row;$insert->bind_param('iisss',$id,$customer,$status,$paid,$end);$insert->execute();}
 $expr=customer_booking_filter_sql('b');$owner=1;
 $query=static function(string $filter,int $limit=100,int $offset=0)use($db,$expr,$owner):array{$q=$db->prepare("SELECT id FROM bookings b WHERE b.customer_id=? AND (?='All' OR ($expr)=?) ORDER BY b.id DESC LIMIT ? OFFSET ?");$q->bind_param('issii',$owner,$filter,$filter,$limit,$offset);$q->execute();return array_map('intval',array_column($q->get_result()->fetch_all(MYSQLI_ASSOC),'id'));};
 foreach(['All'=>35,'Pending'=>4,'Partially Paid'=>2,'Paid'=>1,'Completed'=>2,'Cancelled'=>26] as $filter=>$total){$ids=$query($filter);$check(count($ids)===$total,"$filter bucket has $total owner-only matches");$count=$db->prepare("SELECT COUNT(*) FROM bookings b WHERE b.customer_id=? AND (?='All' OR ($expr)=?)");$count->bind_param('iss',$owner,$filter,$filter);$count->execute();$check((int)$count->get_result()->fetch_row()[0]===$total,"$filter count matches the pageable query");}
 $check($query('Pending',10)===[10,8,4,1],'Needs Attention finds older matches beyond the unfiltered first page');
 $check(count($query('Cancelled',10,20))===6,'last filtered page contains only the remaining six matches');
 $check($query('Completed')===[6,5],'explicit and database-date completed bookings share the terminal bucket');
 $check($query('Partially Paid')===[9,2],'today is not completed early and stays partially paid');
 foreach([null,[],"Paid' OR 1=1 --",'bogus'] as $bad)$check(customer_booking_filter_value($bad)==='All','invalid filter falls back without entering SQL');
 $selectSource=(string)file_get_contents(dirname(__DIR__).'/user_dashboard.php');preg_match('/<select id="statusFilter".*?<\/select>/s',$selectSource,$select);
 foreach(['All','Pending','Partially Paid','Paid','Completed','Cancelled'] as $booking_filter){ob_start();eval('?>'.$select[0]);$html=ob_get_clean();$check(substr_count($html,' selected')===1&&str_contains($html,'value="'.htmlspecialchars($booking_filter,ENT_QUOTES,'UTF-8').'" selected'),'server renders exactly the requested selected option');}
 $bookings=[];$booking_filter='Pending';ob_start();require dirname(__DIR__).'/includes/customer-dashboard/booking_rows.php';$empty=ob_get_clean();$check(str_contains($empty,'No bookings match this status.'),'filtered empty state explains recovery');
 $booking_filtered_total=0;$booking_page=1;$booking_pages=1;$booking_limit=10;ob_start();require dirname(__DIR__).'/includes/customer-dashboard/booking_pagination.php';$pagination=ob_get_clean();$check(str_contains($pagination,'Showing 0-0 of 0 matching bookings')&&!str_contains($pagination,'Next page'),'empty count is truthful and has no next page');
 $booking_filtered_total=26;$booking_pages=3;$booking_page=2;ob_start();require dirname(__DIR__).'/includes/customer-dashboard/booking_pagination.php';$pagination=ob_get_clean();$check(str_contains($pagination,'Showing 11-20 of 26 matching bookings')&&str_contains($pagination,'booking_status=Pending&amp;booking_page=3'),'pagination keeps the filter and matching total');
 file_put_contents($root.'/empty-filter.html','<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><main><h1>Booking History</h1><p>Fictional empty-filter fixture</p><table id="bookingsTable" class="history-table"><tbody>'.$empty.'</tbody></table></main></body></html>');
 echo "$checks checks: PASS\n";
}finally{$db->query("DROP DATABASE IF EXISTS `$name`");$db->close();}
