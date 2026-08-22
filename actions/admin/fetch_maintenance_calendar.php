<?php
session_start();
header('Content-Type: application/json');
require '../../config/db_connect.php';

if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'staff' && $_SESSION['role'] !== 'admin')) {
    echo json_encode(['booked_dates' => [], 'maintenance_dates' => []]);
    exit;
}

try {
    $venue_id = isset($_REQUEST['venue_id']) ? (int)$_REQUEST['venue_id'] : 0;
    if ($venue_id <= 0) {
        echo json_encode(['booked_dates' => [], 'maintenance_dates' => []]);
        exit;
    }

    // Check if venue is a hotel room (requires exclusive checkout intervals)
    $stmt_venue = $conn->prepare("SELECT category FROM venues WHERE id = ?");
    $stmt_venue->bind_param("i", $venue_id);
    $stmt_venue->execute();
    $venue_cat = $stmt_venue->get_result()->fetch_assoc()['category'] ?? 'Event Hall';

    $booked_dates = [];
    $maintenance_dates = [];

    // 1. Fetch direct bookings (excluding Maintenance source and Cancelled)
    $stmt_bookings = $conn->prepare("
        SELECT reference_no, start_date, end_date, booking_status 
        FROM bookings 
        WHERE venue_id = ? AND booking_status != 'Cancelled' AND source != 'Maintenance'
    ");
    $stmt_bookings->bind_param("i", $venue_id);
    $stmt_bookings->execute();
    $res_bookings = $stmt_bookings->get_result();

    while ($row = $res_bookings->fetch_assoc()) {
        $currentDate = new DateTime($row['start_date']);
        $endDate = new DateTime($row['end_date']);
        
        // Hotel Rooms use exclusive checkouts (so checkout date isn't rendered as booked)
        $is_hotel = ($venue_cat === 'Hotel Room');
        
        while ($is_hotel ? $currentDate < $endDate : $currentDate <= $endDate) {
            $booked_dates[] = [
                'date' => $currentDate->format('Y-m-d'),
                'ref_no' => $row['reference_no'],
                'status' => $row['booking_status']
            ];
            $currentDate->modify('+1 day');
        }
    }

    // 2. Fetch add-on bookings (hotel rooms)
    if ($venue_cat === 'Hotel Room') {
        $stmt_addons = $conn->prepare("
            SELECT b.reference_no, br.start_date, br.end_date, b.booking_status 
            FROM booking_rooms br
            JOIN bookings b ON br.booking_id = b.id
            WHERE br.venue_id = ? AND b.booking_status != 'Cancelled' AND b.source != 'Maintenance'
        ");
        $stmt_addons->bind_param("i", $venue_id);
        $stmt_addons->execute();
        $res_addons = $stmt_addons->get_result();

        while ($row = $res_addons->fetch_assoc()) {
            $currentDate = new DateTime($row['start_date']);
            $endDate = new DateTime($row['end_date']);
            while ($currentDate < $endDate) {
                $booked_dates[] = [
                    'date' => $currentDate->format('Y-m-d'),
                    'ref_no' => $row['reference_no'],
                    'status' => $row['booking_status']
                ];
                $currentDate->modify('+1 day');
            }
        }
    }

    // 3. Fetch Maintenance blocks
    $stmt_maint = $conn->prepare("
        SELECT start_date, end_date, maintenance_type, is_blocking 
        FROM maintenance 
        WHERE venue_id = ? AND status = 'Scheduled'
    ");
    $stmt_maint->bind_param("i", $venue_id);
    $stmt_maint->execute();
    $res_maint = $stmt_maint->get_result();

    while ($row = $res_maint->fetch_assoc()) {
        $currentDate = new DateTime($row['start_date']);
        $endDate = new DateTime($row['end_date']);
        
        // Maintenance ALWAYS blocks inclusive dates (even for hotel rooms)
        while ($currentDate <= $endDate) {
            $maintenance_dates[] = [
                'date' => $currentDate->format('Y-m-d'),
                'type' => $row['maintenance_type'],
                'is_blocking' => (bool)$row['is_blocking']
            ];
            $currentDate->modify('+1 day');
        }
    }

    echo json_encode([
        'booked_dates' => $booked_dates,
        'maintenance_dates' => $maintenance_dates
    ]);

} catch (Exception $e) {
    echo json_encode(['booked_dates' => [], 'maintenance_dates' => []]);
}
?>
