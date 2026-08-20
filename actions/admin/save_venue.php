<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db_connect.php';

// Auth Guard
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

// ==========================================
// CSRF PROTECTION GUARD (JSON)
// ==========================================
$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $client_csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed. Unauthorized request.']);
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
            // INSERT NEW VENUE (SINGLE OR BULK)
            // ============================================
            $is_bulk = isset($_POST['is_bulk']) && $_POST['is_bulk'] === "1";
            
            if ($category === 'Hotel Room' && $is_bulk) {
                $qty = max(1, min(100, intval($_POST['bulk_quantity'])));
                $start_str = trim($_POST['bulk_start_number']);
                
                // Parse prefix and number
                if (preg_match('/^([A-Za-z]+-)?(\d+)$/', $start_str, $matches)) {
                    $prefix = $matches[1] ?? '';
                    $num_str = $matches[2];
                    $num_len = strlen($num_str);
                    $start_num = intval($num_str);
                    
                    $type = trim($_POST['room_type']);
                    $rate = floatval($_POST['nightly_rate']);
                    $ex = floatval($_POST['extra_pax_rate']);
                    
                    for ($i = 0; $i < $qty; $i++) {
                        $current_num = $start_num + $i;
                        $formatted_num = $prefix . sprintf("%0{$num_len}d", $current_num);
                        
                        $stmt = $conn->prepare("INSERT INTO venues (category, name, status, description, amenities) VALUES (?, ?, ?, ?, ?)");
                        $stmt->bind_param("sssss", $category, $name, $status, $desc, $amenities);
                        $stmt->execute();
                        $new_venue_id = $conn->insert_id;
                        
                        $stmt_ch = $conn->prepare("INSERT INTO hotel_rooms (venue_id, room_type, room_number, base_capacity, max_capacity, nightly_rate, extra_pax_rate) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt_ch->bind_param("issiddd", $new_venue_id, $type, $formatted_num, $base_cap, $max_cap, $rate, $ex);
                        $stmt_ch->execute();
                    }
                    $msg = "$qty hotel rooms created successfully!";
                } else {
                    throw new Exception("Invalid bulk starting number format.");
                }
            } else {
                // SINGLE INSERT
                $stmt = $conn->prepare("INSERT INTO venues (category, name, status, description, amenities) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("sssss", $category, $name, $status, $desc, $amenities);
                $stmt->execute();
                $new_venue_id = $conn->insert_id;

                // Insert into specific child tables
                if ($category === 'Event Hall') {
                    $rate = floatval($_POST['base_rate']);
                    $c_t = intval($_POST['capacity_theater']);
                    $c_c = intval($_POST['capacity_classroom']);
                    $c_b = intval($_POST['capacity_banquet']);
                    
                    $stmt_ch = $conn->prepare("INSERT INTO event_halls (venue_id, base_capacity, max_capacity, base_rate, capacity_theater, capacity_classroom, capacity_banquet) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt_ch->bind_param("iiidiii", $new_venue_id, $base_cap, $max_cap, $rate, $c_t, $c_c, $c_b);
                    $stmt_ch->execute();
                }
                elseif ($category === 'Hotel Room') {
                    $type = trim($_POST['room_type']);
                    $rate = floatval($_POST['nightly_rate']);
                    $ex = floatval($_POST['extra_pax_rate']);
                    $room_number = trim($_POST['room_number']);
                    $stmt_ch = $conn->prepare("INSERT INTO hotel_rooms (venue_id, room_type, room_number, base_capacity, max_capacity, nightly_rate, extra_pax_rate) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt_ch->bind_param("issiddd", $new_venue_id, $type, $room_number, $base_cap, $max_cap, $rate, $ex);
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
            }

        } else {
            // ============================================
            // UPDATE EXISTING VENUE
            // ============================================
            $stmt = $conn->prepare("UPDATE venues SET name=?, status=?, description=?, amenities=? WHERE id=?");
            $stmt->bind_param("ssssi", $name, $status, $desc, $amenities, $venue_id);
            $stmt->execute();

            if ($category === 'Event Hall') {
                $rate = floatval($_POST['base_rate']);
                $c_t = intval($_POST['capacity_theater']);
                $c_c = intval($_POST['capacity_classroom']);
                $c_b = intval($_POST['capacity_banquet']);
                
                $stmt_ch = $conn->prepare("UPDATE event_halls SET base_capacity=?, max_capacity=?, base_rate=?, capacity_theater=?, capacity_classroom=?, capacity_banquet=? WHERE venue_id=?");
                $stmt_ch->bind_param("iidiiii", $base_cap, $max_cap, $rate, $c_t, $c_c, $c_b, $venue_id);
                $stmt_ch->execute();
            }
            elseif ($category === 'Hotel Room') {
                $type = trim($_POST['room_type']);
                $rate = floatval($_POST['nightly_rate']);
                $ex = floatval($_POST['extra_pax_rate']);
                $room_number = trim($_POST['room_number']);
                $stmt_ch = $conn->prepare("UPDATE hotel_rooms SET room_type=?, room_number=?, base_capacity=?, max_capacity=?, nightly_rate=?, extra_pax_rate=? WHERE venue_id=?");
                $stmt_ch->bind_param("ssiiddi", $type, $room_number, $base_cap, $max_cap, $rate, $ex, $venue_id);
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