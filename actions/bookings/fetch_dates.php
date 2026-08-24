<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');
require '../../config/db_connect.php';
require_once '../../includes/booking_rules.php';

try {
    $bookedDates = [];
    $hardBlockedDates = [];
    $current_session = session_id();
    $room_type = $_REQUEST['room_type'] ?? '';
    $room_name = $_REQUEST['room_name'] ?? ''; // This is venue name or building name

    if (empty($room_type) || empty($room_name)) {
        echo json_encode(['success' => true, 'booked_dates' => [], 'hard_blocked_dates' => []]);
        exit;
    }

    if ($room_type === 'Event Hall' || $room_type === 'Resort Villa') {
        // Find single venue ID
        $stmt = $conn->prepare("SELECT id FROM venues WHERE category = ? AND name = ? LIMIT 1");
        $stmt->bind_param("ss", $room_type, $room_name);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $venue_id = (int)$result->fetch_assoc()['id'];

            // Event Halls only block Confirmed. Villas block Pending & Confirmed.
            $status_filter = ($room_type === 'Event Hall') ? "IN ('Confirmed', 'Completed')" : "IN ('Pending', 'Confirmed', 'Completed')";

            $stmt_bookings = $conn->query("SELECT start_date, end_date FROM bookings WHERE venue_id = $venue_id AND booking_status $status_filter AND source <> 'Maintenance'");
            while ($row = $stmt_bookings->fetch_assoc()) {
                $currentDate = new DateTime($row['start_date']);
                $endDate = new DateTime($row['end_date']);
                $date_is_occupied = ($room_type === 'Event Hall') ? ($currentDate <= $endDate) : ($currentDate < $endDate);
                while ($date_is_occupied) {
                    $bookedDates[] = $currentDate->format('Y-m-d');
                    $currentDate->modify('+1 day');
                    $date_is_occupied = ($room_type === 'Event Hall') ? ($currentDate <= $endDate) : ($currentDate < $endDate);
                }
            }

            // Maintenance blocks (completed/cancelled records are no longer availability blockers).
            $stmt_maint = $conn->query("SELECT start_date, end_date FROM maintenance WHERE venue_id = $venue_id AND is_blocking = 1 AND (status = 'Scheduled' OR status IS NULL)");
            while ($row = $stmt_maint->fetch_assoc()) {
                $currentDate = new DateTime($row['start_date']);
                $endDate = new DateTime($row['end_date']);
                while ($currentDate <= $endDate) {
                    $date = $currentDate->format('Y-m-d');
                    $bookedDates[] = $date;
                    $hardBlockedDates[] = $date;
                    $currentDate->modify('+1 day');
                }
            }

            // Villas are single units, so another session's active lock is unavailable.
            if ($room_type === 'Resort Villa') {
                $stmt_locks = $conn->prepare("SELECT start_date, end_date FROM booking_locks WHERE venue_id = ? AND session_id != ? AND expires_at > NOW()");
                $stmt_locks->bind_param('is', $venue_id, $current_session);
                $stmt_locks->execute();
                $res_locks = $stmt_locks->get_result();
                while ($row = $res_locks->fetch_assoc()) {
                    $currentDate = new DateTime($row['start_date']);
                    $endDate = new DateTime($row['end_date']);
                    while ($currentDate < $endDate) {
                        $bookedDates[] = $currentDate->format('Y-m-d');
                        $currentDate->modify('+1 day');
                    }
                }
                $stmt_locks->close();
            }
        }
    } else {
        // HOTEL ROOMS (Auto-assign logic)
        // room_type = "Stellar Room", room_name = "Building A"
        $stmt_inv = $conn->prepare("
            SELECT v.id
            FROM venues v
            JOIN hotel_rooms h ON v.id = h.venue_id
            WHERE h.room_type = ? AND v.name = ? AND v.status = 'Available'
        ");
        $stmt_inv->bind_param("ss", $room_type, $room_name);
        $stmt_inv->execute();
        $res_inv = $stmt_inv->get_result();

        if ($res_inv->num_rows > 0) {
            $total_inventory = $res_inv->num_rows;
            $venue_ids = [];
            while($row = $res_inv->fetch_assoc()) {
                $venue_ids[] = $row['id'];
            }
            $v_ids_str = implode(',', $venue_ids);

            // Fetch all booked dates for all units in this group
            // For hotels, check both direct bookings and booking_rooms (add-ons)
            $date_counts = [];
            $maintenance_counts = [];

            // 1. Direct Bookings
            $stmt_direct = $conn->query("
                SELECT start_date, end_date
                FROM bookings
                WHERE venue_id IN ($v_ids_str) AND booking_status IN ('Pending', 'Confirmed', 'Completed') AND source <> 'Maintenance'
            ");
            while ($row = $stmt_direct->fetch_assoc()) {
                $currentDate = new DateTime($row['start_date']);
                $endDate = new DateTime($row['end_date']);
                while ($currentDate < $endDate) {
                    $d = $currentDate->format('Y-m-d');
                    $date_counts[$d] = ($date_counts[$d] ?? 0) + 1;
                    $currentDate->modify('+1 day');
                }
            }

            // 2. Add-on Bookings (booking_rooms)
            $stmt_addon = $conn->query("
                SELECT br.start_date, br.end_date
                FROM booking_rooms br
                JOIN bookings b ON br.booking_id = b.id
                JOIN venues parent_v ON parent_v.id = b.venue_id
                WHERE br.venue_id IN ($v_ids_str) AND b.booking_status IN ('Pending', 'Confirmed', 'Completed')
                  AND NOT (b.booking_status = 'Pending' AND parent_v.category = 'Event Hall')
                  AND b.source <> 'Maintenance'
            ");
            while ($row = $stmt_addon->fetch_assoc()) {
                $currentDate = new DateTime($row['start_date']);
                $endDate = new DateTime($row['end_date']);
                while ($currentDate < $endDate) {
                    $d = $currentDate->format('Y-m-d');
                    $date_counts[$d] = ($date_counts[$d] ?? 0) + 1;
                    $currentDate->modify('+1 day');
                }
            }

            // 3. Maintenance Blocks
            $stmt_maint = $conn->query("
                SELECT start_date, end_date
                FROM maintenance
                WHERE venue_id IN ($v_ids_str) AND is_blocking = 1 AND (status = 'Scheduled' OR status IS NULL)
            ");
            while ($row = $stmt_maint->fetch_assoc()) {
                $currentDate = new DateTime($row['start_date']);
                $endDate = new DateTime($row['end_date']);
                while ($currentDate < $endDate) {
                    $d = $currentDate->format('Y-m-d');
                    $date_counts[$d] = ($date_counts[$d] ?? 0) + 1;
                    $maintenance_counts[$d] = ($maintenance_counts[$d] ?? 0) + 1;
                    $currentDate->modify('+1 day');
                }
            }

            // Count active locks held by other sessions against the same room inventory.
            $stmt_locks = $conn->prepare("SELECT venue_id, start_date, end_date FROM booking_locks WHERE venue_id IN ($v_ids_str) AND session_id != ? AND expires_at > NOW()");
            $stmt_locks->bind_param('s', $current_session);
            $stmt_locks->execute();
            $res_locks = $stmt_locks->get_result();
            while ($row = $res_locks->fetch_assoc()) {
                $currentDate = new DateTime($row['start_date']);
                $endDate = new DateTime($row['end_date']);
                while ($currentDate < $endDate) {
                    $d = $currentDate->format('Y-m-d');
                    $date_counts[$d] = ($date_counts[$d] ?? 0) + 1;
                    $currentDate->modify('+1 day');
                }
            }
            $stmt_locks->close();

            // If count >= total_inventory, that date is fully booked
            foreach ($date_counts as $date_str => $count) {
                if ($count >= $total_inventory) {
                    $bookedDates[] = $date_str;
                }
            }
            foreach ($maintenance_counts as $date_str => $count) {
                if ($count >= $total_inventory) {
                    $hardBlockedDates[] = $date_str;
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'booked_dates' => array_values(array_unique($bookedDates)),
        'hard_blocked_dates' => array_values(array_unique($hardBlockedDates))
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'booked_dates' => [], 'hard_blocked_dates' => [], 'message' => 'Availability could not be loaded. Please try again.']);
}
?>
