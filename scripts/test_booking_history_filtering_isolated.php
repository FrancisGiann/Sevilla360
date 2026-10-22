<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc !== 3) exit(2);
$root = realpath($argv[2]);
$socket = realpath($argv[1]);
if (!$root || !$socket || !str_starts_with($root, sys_get_temp_dir() . '/sevilla360-booking-concurrency.') || !str_starts_with($socket, $root . '/')) exit(2);

require_once dirname(__DIR__) . '/includes/booking_lifecycle.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli('localhost', 'root', '', null, 0, $socket);
$conn->set_charset('utf8mb4');
$database = 's360_history_' . bin2hex(random_bytes(6));
try {
    $conn->query("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $conn->select_db($database);
    foreach ([
        "CREATE TABLE customers(id INT PRIMARY KEY,first_name VARCHAR(100),last_name VARCHAR(100))",
        "CREATE TABLE venues(id INT PRIMARY KEY,name VARCHAR(150),category VARCHAR(40))",
        "CREATE TABLE bookings(id INT PRIMARY KEY,reference_no VARCHAR(20),customer_id INT,venue_id INT,start_date DATE,end_date DATE,guests_count INT,total_amount DECIMAL(10,2),amount_paid DECIMAL(10,2),booking_status VARCHAR(30),payment_status VARCHAR(30),source VARCHAR(30))",
        "CREATE TABLE seminars(id INT PRIMARY KEY,name VARCHAR(180),hall_venue_id INT,hall_start_date DATE,hall_end_date DATE,agreed_price DECIMAL(10,2),status VARCHAR(30))",
        "CREATE TABLE seminar_payments(id INT PRIMARY KEY,seminar_id INT,amount DECIMAL(10,2),status VARCHAR(30))",
        "CREATE TABLE manual_payment_submissions(id INT PRIMARY KEY,booking_id INT,status VARCHAR(30))",
        "INSERT INTO customers VALUES(1,'Synthetic','Customer')",
        "INSERT INTO venues VALUES(1,'Synthetic Hotel','Hotel Room'),(2,'Synthetic Hall','Event Hall')",
        "INSERT INTO bookings VALUES(1,'QA-BOOKING',1,1,'2099-01-01','2099-01-01',2,5000,0,'Pending','Unpaid','Online')",
        "INSERT INTO seminars VALUES(700,'Draft seminar',2,'2099-01-01','2099-01-01',NULL,'draft'),(701,'Final seminar',2,'2099-01-02','2099-01-02',10000,'finalized'),(702,'Cancelled seminar',2,'2099-01-03','2099-01-03',10000,'cancelled')",
        "INSERT INTO seminar_payments VALUES(1,701,10000,'posted')",
    ] as $sql) $conn->query($sql);

    $source = (string)file_get_contents(dirname(__DIR__) . '/actions/admin/get_bookings_page.php');
    $start = strpos($source, '$pageValue =');
    $end = strpos($source, '$dataSql =', $start === false ? 0 : $start);
    if ($start === false || $end === false || $end <= $start) throw new RuntimeException('History filtering query generator unavailable.');
    $generator = substr($source, $start, $end - $start);
    $generator .= "\n} catch (Throwable \$error) { throw \$error; }\n";
    $cases = [
        ['default booking', [], 1],
        ['seminar all', ['record_type' => 'seminar'], 3],
        ['seminar draft', ['record_type' => 'seminar', 'status' => 'draft'], 1],
        ['seminar finalized', ['record_type' => 'seminar', 'status' => 'finalized'], 1],
        ['seminar cancelled', ['record_type' => 'seminar', 'status' => 'cancelled'], 1],
        ['seminar balances due', ['record_type' => 'seminar', 'status' => 'partial'], 0],
        ['booking pending', ['record_type' => 'booking', 'status' => 'pending'], 1],
    ];
    foreach ($cases as [$label, $data, $expected]) {
        eval($generator);
        if ($totalRows !== $expected) throw new RuntimeException("$label count was $totalRows; expected $expected.");
        echo "PASS $label is isolated before server count: $totalRows\n";
    }
} finally {
    $conn->query("DROP DATABASE IF EXISTS `$database`");
    $conn->close();
}
