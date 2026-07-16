<?php
session_start(); // 1. Start the session!

// Set response type to JSON
header('Content-Type: application/json');

// 2. AUTH GUARD: Kick out anyone who isn't an admin or superadmin
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'superadmin')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

// Include your database connection
require_once '../../config/db_connect.php'; 

// Get the raw POST data (since we are sending JSON from JavaScript)
$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true);

// Validate inputs
if (!isset($data['booking_id']) || !isset($data['action'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request. Missing data.']);
    exit;
}

$booking_id = intval($data['booking_id']);
$action = $data['action'];
$new_status = '';

// Determine what the new status should be based on the button clicked
if ($action === 'confirm') {
    $new_status = 'Confirmed';
    $query = "UPDATE bookings SET booking_status = ? WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("si", $new_status, $booking_id);

} elseif ($action === 'cancel') {
    $new_status = 'Cancelled';
    $query = "UPDATE bookings SET booking_status = ? WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("si", $new_status, $booking_id);

} elseif ($action === 'add_payment') {
    // Add Payment Logic
    $amount_to_add = floatval($data['amount']);
    $method = $data['method'];
    $trans_id = isset($data['transaction_id']) && !empty($data['transaction_id']) ? $data['transaction_id'] : 'CASH-'.time();

    // 1. Get current booking totals
    $stmt_check = $conn->prepare("SELECT total_amount, amount_paid FROM bookings WHERE id = ?");
    $stmt_check->bind_param("i", $booking_id);
    $stmt_check->execute();
    $res = $stmt_check->get_result();
    if ($res->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        exit;
    }
    $booking = $res->fetch_assoc();
    
    // Calculate new total paid
    $new_amount_paid = floatval($booking['amount_paid']) + $amount_to_add;
    
    // Check if fully paid
    $new_payment_status = ($new_amount_paid >= floatval($booking['total_amount'])) ? 'Paid' : 'Partial';

    // 2. Insert into payments table
    $stmt_pay = $conn->prepare("INSERT INTO payments (booking_id, transaction_id, payment_method, amount, status) VALUES (?, ?, ?, ?, 'Success')");
    $stmt_pay->bind_param("issd", $booking_id, $trans_id, $method, $amount_to_add);
    $stmt_pay->execute();

    // 3. Update bookings table
    $query = "UPDATE bookings SET payment_status = ?, amount_paid = ?, booking_status = 'Confirmed' WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("sdi", $new_payment_status, $new_amount_paid, $booking_id);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action provided.']);
    exit;
}

// Prepare the SQL statement using MySQLi
$query = "UPDATE bookings SET booking_status = ? WHERE id = ?";
$stmt = $conn->prepare($query);

if ($stmt) {
    // Bind parameters: "s" for string (status), "i" for integer (id)
    $stmt->bind_param("si", $new_status, $booking_id);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true, 
            'message' => "Booking #" . $booking_id . " has been successfully " . strtolower($new_status) . "!"
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update database.']);
    }
    $stmt->close();
} else {
    echo json_encode(['success' => false, 'message' => 'Database query preparation failed.']);
}

$conn->close();
?>