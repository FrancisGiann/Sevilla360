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
        "CREATE TABLE customers(id INT PRIMARY KEY,first_name VARCHAR(100),last_name VARCHAR(100),user_id INT NULL)",
        "CREATE TABLE venues(id INT PRIMARY KEY,name VARCHAR(150),category VARCHAR(40))",
        "CREATE TABLE bookings(id INT PRIMARY KEY,reference_no VARCHAR(20),customer_id INT,venue_id INT,start_date DATE,end_date DATE,guests_count INT,total_amount DECIMAL(10,2),amount_paid DECIMAL(10,2),created_at DATETIME,booking_status VARCHAR(30),payment_status VARCHAR(30),source VARCHAR(30))",
        "CREATE TABLE hotel_rooms(venue_id INT PRIMARY KEY,room_type VARCHAR(80))",
        "CREATE TABLE cancellations(id INT PRIMARY KEY,booking_id INT,status VARCHAR(30),reason VARCHAR(200),fee_percent DECIMAL(5,2),fee_deducted DECIMAL(10,2),refund_amount DECIMAL(10,2))",
        "CREATE TABLE reschedule_requests(id INT PRIMARY KEY,booking_id INT,status VARCHAR(30),new_start_date DATE,new_end_date DATE,reason VARCHAR(200))",
        "CREATE TABLE seminars(id INT PRIMARY KEY,name VARCHAR(180),hall_venue_id INT,hall_start_date DATE,hall_end_date DATE,agreed_price DECIMAL(10,2),created_at DATETIME,status VARCHAR(30))",
        "CREATE TABLE seminar_payments(id INT PRIMARY KEY,seminar_id INT,amount DECIMAL(10,2),status VARCHAR(30))",
        "CREATE TABLE seminar_attendees(id INT PRIMARY KEY,seminar_id INT)",
        "CREATE TABLE manual_payment_submissions(id INT PRIMARY KEY,booking_id INT,status VARCHAR(30),payment_method VARCHAR(30),expected_amount DECIMAL(10,2),transaction_reference VARCHAR(100))",
        "INSERT INTO customers VALUES(1,'Synthetic','Customer',NULL)",
        "INSERT INTO venues VALUES(1,'Synthetic Hotel','Hotel Room'),(2,'Synthetic Hall','Event Hall')",
        "INSERT INTO hotel_rooms VALUES(1,'Standard Room')",
        "INSERT INTO bookings VALUES
            (1,'QA-OLD',1,1,'2099-03-01','2099-03-02',2,5000,0,'2026-01-01 10:00:00','Pending','Unpaid','Online'),
            (99,'QA-NEW',1,1,'2099-01-01','2099-01-02',2,5000,0,'2026-02-01 10:00:00','Confirmed','Unpaid','Online'),
            (100,'QA-PROOF',1,1,'2099-01-05','2099-01-06',2,5000,5000,'2026-01-20 10:00:00','Confirmed','Paid','Online'),
            (101,'QA-CANCEL',1,1,'2099-01-07','2099-01-08',2,5000,5000,'2026-01-15 10:00:00','Confirmed','Paid','Online'),
            (102,'QA-RESCHED',1,1,'2099-01-09','2099-01-10',2,5000,5000,'2026-01-10 10:00:00','Confirmed','Paid','Online')",
        "INSERT INTO manual_payment_submissions VALUES(1,100,'pending','GCash',5000,'QA-REF')",
        "INSERT INTO cancellations VALUES(1,101,'Pending','Synthetic cancellation',0,0,5000)",
        "INSERT INTO reschedule_requests VALUES(1,102,'Pending','2099-02-01','2099-02-02','Synthetic reschedule')",
        "INSERT INTO seminars VALUES
            (700,'Draft seminar',2,'2099-01-01','2099-01-01',NULL,'2026-03-03 10:00:00','draft'),
            (701,'Final seminar',2,'2099-01-02','2099-01-02',10000,'2026-03-02 10:00:00','finalized'),
            (702,'Cancelled seminar',2,'2099-01-03','2099-01-03',10000,'2026-03-01 10:00:00','cancelled')",
        "INSERT INTO seminar_payments VALUES(1,701,10000,'posted')",
    ] as $sql) $conn->query($sql);

    $source = (string)file_get_contents(dirname(__DIR__) . '/actions/admin/get_bookings_page.php');
    $start = strpos($source, '$pageValue =');
    $end = strpos($source, "\n?>", $start === false ? 0 : $start);
    if ($start === false || $end === false || $end <= $start) throw new RuntimeException('History query generator unavailable.');
    $generator = substr($source, $start, $end - $start);
    $runHistory = static function (array $request) use ($generator, $conn): array {
        $data = $request;
        ob_start();
        eval($generator);
        $output = (string)ob_get_clean();
        $response = json_decode($output, true);
        if (!is_array($response) || ($response['success'] ?? false) !== true) throw new RuntimeException('History query failed: ' . $output);
        return $response;
    };

    $cases = [
        ['default booking', [], 5],
        ['seminar all', ['record_type' => 'seminar'], 3],
        ['seminar draft', ['record_type' => 'seminar', 'status' => 'draft'], 1],
        ['seminar finalized', ['record_type' => 'seminar', 'status' => 'finalized'], 1],
        ['seminar cancelled', ['record_type' => 'seminar', 'status' => 'cancelled'], 1],
        ['seminar balances due', ['record_type' => 'seminar', 'status' => 'partial'], 0],
        ['booking pending', ['record_type' => 'booking', 'status' => 'pending'], 1],
        ['booking action required', ['record_type' => 'booking', 'status' => 'action_req'], 4],
    ];
    foreach ($cases as [$label, $request, $expected]) {
        $response = $runHistory($request);
        $actual = (int)$response['pagination']['total_rows'];
        if ($actual !== $expected) throw new RuntimeException("$label count was $actual; expected $expected.");
        echo "PASS $label count is $actual\n";
    }

    $allBeforeApproval = $runHistory(['record_type' => 'booking'])['data'];
    $allBeforeIds = array_map('intval', array_column($allBeforeApproval, 'id'));
    if ($allBeforeIds !== [99, 100, 101, 102, 1]) throw new RuntimeException('All history did not sort by creation time with the new earlier-stay booking first.');
    if ($allBeforeApproval[0]['start_date'] >= $allBeforeApproval[4]['start_date']) throw new RuntimeException('The ordering fixture does not have the new booking staying earlier than the old booking.');
    echo "PASS booking All order uses creation time before stay date\n";

    $seminarRows = $runHistory(['record_type' => 'seminar'])['data'];
    if ((int)$seminarRows[0]['id'] !== 700 || $seminarRows[0]['seminar_name'] !== 'Draft seminar'
        || $seminarRows[0]['created_at'] !== '2026-03-03 10:00:00' || $seminarRows[0]['total_amount'] !== null) {
        throw new RuntimeException('Seminar UNION fields or created_at ordering are misaligned.');
    }
    echo "PASS seminar history preserves UNION field positions and creation order\n";

    $conn->query("UPDATE bookings SET booking_status='Confirmed' WHERE id=1");
    $actionRequiredAfterApproval = $runHistory(['record_type' => 'booking', 'status' => 'action_req']);
    if ((int)$actionRequiredAfterApproval['pagination']['total_rows'] !== 3) throw new RuntimeException('Approved booking remained in Action Required without another pending item.');
    $allAfterApproval = array_map('intval', array_column($runHistory(['record_type' => 'booking'])['data'], 'id'));
    if ($allAfterApproval !== $allBeforeIds) throw new RuntimeException('Approval changed the booking All order.');
    echo "PASS approval removes the booking from Action Required without changing All order\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `$database`");
    $conn->close();
}
