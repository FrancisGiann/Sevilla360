<?php
declare(strict_types=1);
// CLI-only query regression. Never load the app's database configuration.
if (PHP_SAPI !== 'cli' || $argc !== 3) exit(2);
$root = realpath($argv[2]); $socket = realpath($argv[1]);
if (!$root || !$socket || !str_starts_with($root, sys_get_temp_dir().'/sevilla360-booking-concurrency.') || !str_starts_with($socket, $root.'/')) exit(2);
require_once dirname(__DIR__).'/includes/booking_lifecycle.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli('localhost','root','',null,0,$socket); $conn->set_charset('utf8mb4');
$name = 's360_export_'.bin2hex(random_bytes(6)); $checks = 0;
try {
 $conn->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $conn->select_db($name);
 foreach ([
  "CREATE TABLE customers(id INT PRIMARY KEY,first_name VARCHAR(100),last_name VARCHAR(100)) COLLATE utf8mb4_general_ci",
  "CREATE TABLE venues(id INT PRIMARY KEY,name VARCHAR(150),category VARCHAR(40)) COLLATE utf8mb4_unicode_ci",
  "CREATE TABLE bookings(id INT PRIMARY KEY,reference_no VARCHAR(20),customer_id INT,venue_id INT,start_date DATE,end_date DATE,guests_count INT,total_amount DECIMAL(10,2),amount_paid DECIMAL(10,2),booking_status VARCHAR(30),payment_status VARCHAR(30),source VARCHAR(30)) COLLATE utf8mb4_general_ci",
  "CREATE TABLE seminars(id INT PRIMARY KEY,name VARCHAR(180),hall_venue_id INT,hall_start_date DATE,hall_end_date DATE,agreed_price DECIMAL(10,2),status VARCHAR(30)) COLLATE utf8mb4_unicode_ci",
  "CREATE TABLE seminar_attendees(id INT PRIMARY KEY,seminar_id INT)",
  "CREATE TABLE seminar_payments(id INT PRIMARY KEY,seminar_id INT,amount DECIMAL(10,2),status VARCHAR(30))",
  "INSERT INTO customers VALUES(1,'Doña','=Synthetic')",
  "INSERT INTO venues VALUES(1,'Synthetic Hotel','Hotel Room'),(2,'Synthetic Hall','Event Hall')",
  "INSERT INTO bookings VALUES(1,'QA-EXPORT',1,1,'2000-01-01','2000-01-02',2,5000,5000,'Completed','Paid','Online')",
  "INSERT INTO seminars VALUES(700,'Doña Seminar',2,'2099-01-01','2099-01-01',10000,'draft'),(701,'Finalized seminar',2,'2099-01-02','2099-01-02',5000,'finalized')",
 ] as $sql) $conn->query($sql);
 $source=(string)file_get_contents(dirname(__DIR__).'/actions/admin/export_bookings.php');
 $helperStart=strpos($source,'$historyText =');
 if ($helperStart !== false) eval(substr($source,$helperStart,strpos($source,"\n",$helperStart)-$helperStart));
 $start=strpos($source,'$searchTerm ='); $end=strpos($source,'$allParams =');
 if ($start === false || $end === false || $end <= $start) throw new RuntimeException('Export query generator unavailable.');
 $generator=substr($source,$start,$end-$start); $booking_completion_sql=booking_completion_sql('b');
 foreach ([
  ['', 'All', 'all', 'booking', ['Booking']],
  ['', 'All', 'all', 'seminar', ['Seminar']],
  ['SEM-700', 'All', 'all', 'seminar', ['Seminar']],
  ['', 'Hotel Room', 'all', 'booking', ['Booking']],
  ['', 'All', 'completed', 'booking', ['Booking']],
  ['', 'All', 'draft', 'seminar', ['Seminar']],
  ['', 'All', 'finalized', 'seminar', ['Seminar']],
 ] as [$searchInput,$venueInput,$statusInput,$recordTypeInput,$expected]) {
  $_GET=['search'=>$searchInput,'venue'=>$venueInput,'status'=>$statusInput,'record_type'=>$recordTypeInput];
  $recordType=$recordTypeInput; $statusFilter=$statusInput;
  eval($generator);
  $allParams=array_merge($params,$seminarParams); $allTypes=$types.$seminarTypes;
  $stmt=$conn->prepare($sql); $stmt->bind_param($allTypes,...$allParams); $stmt->execute(); $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
  $typesFound=array_values(array_unique(array_column($rows,'record_type'))); sort($typesFound); sort($expected);
  if ($typesFound!==$expected) throw new RuntimeException('Export filters changed while normalizing collations.');
  $checks++;
 }
 echo "$checks type-scoped mixed-collation export queries (booking default, seminar all/search/status, booking venue/completed): PASS\n";
} finally { $conn->query("DROP DATABASE IF EXISTS `$name`"); $conn->close(); }
