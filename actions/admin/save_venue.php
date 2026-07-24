<?php
session_start();
header('Content-Type: application/json');
require_once '../../config/db_connect.php';

// Auth Guard
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    $venue_id = isset($_POST['venue_id']) ? $_POST['venue_id'] : '';
    $name = trim($_POST['name']);
    $category = $_POST['category'];
    $status = $_POST['status'];
    $desc = trim($_POST['description']);
    $amenities = trim($_POST['amenities']);
    
    $base_cap = intval($_POST['base_capacity']);
    $max_cap = intval($_POST['max_capacity']);

    try {
        $conn->begin_transaction();

        if (empty($venue_id)) {
            // ============================================
            // INSERT NEW VENUE
            // ============================================
            $stmt = $conn->prepare("INSERT INTO venues (category, name, status, description, amenities) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $category, $name, $status, $desc, $amenities);
            $stmt->execute();
            $new_venue_id = $conn->insert_id;

            // Insert into specific child tables
            if ($category === 'Event Hall') {
                $rate = floatval($_POST['base_rate']);
                $stmt_ch = $conn->prepare("INSERT INTO event_halls (venue_id, base_capacity, max_capacity, base_rate) VALUES (?, ?, ?, ?)");
                $stmt_ch->bind_param("iiid", $new_venue_id, $base_cap, $max_cap, $rate);
                $stmt_ch->execute();
            } 
            elseif ($category === 'Hotel Room') {
                $type = trim($_POST['room_type']);
                $rate = floatval($_POST['nightly_rate']);
                $ex = floatval($_POST['extra_pax_rate']);
                $stmt_ch = $conn->prepare("INSERT INTO hotel_rooms (venue_id, room_type, base_capacity, max_capacity, nightly_rate, extra_pax_rate) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt_ch->bind_param("isiidd", $new_venue_id, $type, $base_cap, $max_cap, $rate, $ex);
                $stmt_ch->execute();
            } 
            elseif ($category === 'Resort Villa') {
                $day = floatval($_POST['day_rate']);
                $night = floatval($_POST['overnight_rate']);
                $ex = floatval($_POST['extra_pax_rate']);
                $stmt_ch = $conn->prepare("INSERT INTO villas (venue_id, base_capacity, max_capacity, day_rate, overnight_rate, extra_pax_rate) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt_ch->bind_param("iiiddd", $new_venue_id, $base_cap, $max_cap, $day, $night, $ex);
                $stmt_ch->execute();
            }
            $msg = "New venue added successfully!";

        } else {
            // ============================================
            // UPDATE EXISTING VENUE
            // ============================================
            $stmt = $conn->prepare("UPDATE venues SET name=?, status=?, description=?, amenities=? WHERE id=?");
            $stmt->bind_param("ssssi", $name, $status, $desc, $amenities, $venue_id);
            $stmt->execute();

            if ($category === 'Event Hall') {
                $rate = floatval($_POST['base_rate']);
                $stmt_ch = $conn->prepare("UPDATE event_halls SET base_capacity=?, max_capacity=?, base_rate=? WHERE venue_id=?");
                $stmt_ch->bind_param("iidi", $base_cap, $max_cap, $rate, $venue_id);
                $stmt_ch->execute();
            } 
            elseif ($category === 'Hotel Room') {
                $type = trim($_POST['room_type']);
                $rate = floatval($_POST['nightly_rate']);
                $ex = floatval($_POST['extra_pax_rate']);
                $stmt_ch = $conn->prepare("UPDATE hotel_rooms SET room_type=?, base_capacity=?, max_capacity=?, nightly_rate=?, extra_pax_rate=? WHERE venue_id=?");
                $stmt_ch->bind_param("siiddi", $type, $base_cap, $max_cap, $rate, $ex, $venue_id);
                $stmt_ch->execute();
            } 
            elseif ($category === 'Resort Villa') {
                $day = floatval($_POST['day_rate']);
                $night = floatval($_POST['overnight_rate']);
                $ex = floatval($_POST['extra_pax_rate']);
                $stmt_ch = $conn->prepare("UPDATE villas SET base_capacity=?, max_capacity=?, day_rate=?, overnight_rate=?, extra_pax_rate=? WHERE venue_id=?");
                $stmt_ch->bind_param("iidddi", $base_cap, $max_cap, $day, $night, $ex, $venue_id);
                $stmt_ch->execute();
            }
            $msg = "Venue updated successfully!";
        }

        $conn->commit();
        echo json_encode(['success' => true, 'message' => $msg]);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}
?>