<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');
require '../../config/db_connect.php';
require_once '../../includes/booking_rules.php';
require_once '../../includes/hotel_rooms.php';
require_once '../../includes/seminars.php';
require_once '../../includes/venue_recommendation_service.php';

try {
    $bookedDates = [];
    $hardBlockedDates = [];
    $current_session = session_id();
    $room_type = $_REQUEST['room_type'] ?? '';
    $room_name = $_REQUEST['room_name'] ?? ''; // This is venue name or building name
    $venue_id_raw = $_REQUEST['venue_id'] ?? '';
    $requestedStartDate = isset($_REQUEST['start_date']) && is_string($_REQUEST['start_date']) ? $_REQUEST['start_date'] : '';
    $requestedEndDate = isset($_REQUEST['end_date']) && is_string($_REQUEST['end_date']) ? $_REQUEST['end_date'] : '';
    $venue_id = $venue_id_raw === '' ? null : filter_var($venue_id_raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $room_group_raw = $_REQUEST['room_group_id'] ?? '';
    $room_group_id = $room_group_raw === '' ? null : filter_var($room_group_raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($room_group_id === false || $venue_id === false || ($venue_id !== null && !in_array($room_type, ['Event Hall', 'Resort Villa'], true))) {
        http_response_code(422);
        echo json_encode(['success' => false, 'booked_dates' => [], 'hard_blocked_dates' => [], 'message' => 'Invalid room selection.']);
        exit;
    }

    if (empty($room_type) || (empty($room_name) && $room_group_id === null && $venue_id === null)) {
        echo json_encode(['success' => true, 'booked_dates' => [], 'hard_blocked_dates' => []]);
        exit;
    }

    $selectedVenueAvailable = null;
    if ($room_type === 'Event Hall' || $room_type === 'Resort Villa') {
        // A selected showroom result carries its ID so duplicate venue names cannot cross-match.
        if ($venue_id !== null) {
            $stmt = $conn->prepare("SELECT id, status FROM venues WHERE id = ? AND category = ? LIMIT 1");
            $stmt->bind_param("is", $venue_id, $room_type);
        } else {
            $stmt = $conn->prepare("SELECT id, status FROM venues WHERE category = ? AND name = ? LIMIT 1");
            $stmt->bind_param("ss", $room_type, $room_name);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $selectedVenue = $result->fetch_assoc();
            $venue_id = (int)$selectedVenue['id'];
            if ($venue_id_raw !== '') {
                $selectedVenueAvailable = ($selectedVenue['status'] ?? '') === 'Available';
                if (!$selectedVenueAvailable) {
                    echo json_encode(['success' => true, 'booked_dates' => [], 'hard_blocked_dates' => [], 'venue_available' => false]);
                    exit;
                }
            }

            // Event Halls only block Confirmed. Villas block Pending & Confirmed.
            $status_filter = ($room_type === 'Event Hall') ? "IN ('Confirmed', 'Completed')" : "IN ('Pending', 'Confirmed', 'Completed')";

            $stmt_bookings = $conn->query("SELECT b.start_date, b.end_date, vd.stay_type FROM bookings b LEFT JOIN booking_villa_details vd ON vd.booking_id = b.id WHERE b.venue_id = $venue_id AND b.booking_status $status_filter AND b.source <> 'Maintenance'");
            while ($row = $stmt_bookings->fetch_assoc()) {
                $currentDate = new DateTime($row['start_date']);
                $endDate = new DateTime($row['end_date']);
                $date_is_occupied = ($room_type === 'Event Hall' || $room_type === 'Resort Villa') ? ($currentDate <= $endDate) : ($currentDate < $endDate);
                while ($date_is_occupied) {
                    $bookedDates[] = $currentDate->format('Y-m-d');
                    $currentDate->modify('+1 day');
                    $date_is_occupied = ($room_type === 'Event Hall' || $room_type === 'Resort Villa') ? ($currentDate <= $endDate) : ($currentDate < $endDate);
                }
            }

            $stmt_seminars = $conn->prepare("SELECT sr.start_date,sr.end_date,sr.resource_kind FROM seminar_reservations sr JOIN seminars s ON s.id=sr.seminar_id WHERE sr.venue_id=? AND s.status IN ('draft','finalized')");
            $stmt_seminars->bind_param('i', $venue_id); $stmt_seminars->execute();
            $seminarRows = $stmt_seminars->get_result();
            while ($row = $seminarRows->fetch_assoc()) {
                $currentDate = new DateTime($row['start_date']);
                $endDate = new DateTime($row['end_date']);
                $inclusive = $row['resource_kind'] === 'hall';
                while ($inclusive ? $currentDate <= $endDate : $currentDate < $endDate) {
                    $bookedDates[] = $currentDate->format('Y-m-d');
                    $currentDate->modify('+1 day');
                }
            }
            $stmt_seminars->close();

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
                    while ($currentDate <= $endDate) {
                        $bookedDates[] = $currentDate->format('Y-m-d');
                        $currentDate->modify('+1 day');
                    }
                }
                $stmt_locks->close();
            }
            // Keep the date picker calendar and natural-chat snapshot on one
            // overlap rule for the exact requested range.
            if ($venue_id_raw !== '' && $requestedStartDate !== '' && $requestedEndDate !== ''
                && preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $requestedStartDate)
                && preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $requestedEndDate)) {
                $selectedVenueAvailable = venue_recommendation_is_available(
                    $conn, (int)$venue_id, $room_type, $requestedStartDate, $requestedEndDate, $current_session
                );
            }
        } elseif ($venue_id_raw !== '') {
            http_response_code(404);
            echo json_encode(['success' => false, 'booked_dates' => [], 'hard_blocked_dates' => [], 'message' => 'Selected venue is no longer available.']);
            exit;
        }
    } else {
        // HOTEL ROOMS (Auto-assign logic)
        // room_type = "Stellar Room", room_name = "Building A"
        if ($room_group_id !== null && hotel_group_schema_ready($conn)) {
            $stmt_inv = $conn->prepare("SELECT v.id FROM venues v JOIN hotel_rooms h ON v.id = h.venue_id
                INNER JOIN hotel_room_groups g ON g.id = h.room_group_id
                INNER JOIN hotel_room_types t ON t.type_code = g.room_type_code AND t.active = 1
                WHERE h.room_group_id = ? AND v.category = 'Hotel Room' AND v.status = 'Available'");
            $stmt_inv->bind_param('i', $room_group_id);
        } else {
            $stmt_inv = $conn->prepare("SELECT v.id FROM venues v JOIN hotel_rooms h ON v.id = h.venue_id
                WHERE h.room_type = ? AND v.name = ? AND v.status = 'Available'");
            $stmt_inv->bind_param("ss", $room_type, $room_name);
        }
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
                while ($currentDate <= $endDate) {
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

            $stmt_seminars = $conn->prepare("SELECT sr.venue_id,sr.start_date,sr.end_date FROM seminar_reservations sr JOIN seminars s ON s.id=sr.seminar_id WHERE sr.venue_id IN ($v_ids_str) AND sr.resource_kind='room' AND s.status IN ('draft','finalized')");
            $stmt_seminars->execute();
            $res_seminars = $stmt_seminars->get_result();
            while ($row = $res_seminars->fetch_assoc()) {
                $currentDate = new DateTime($row['start_date']);
                $endDate = new DateTime($row['end_date']);
                while ($currentDate < $endDate) {
                    $d = $currentDate->format('Y-m-d');
                    $date_counts[$d] = ($date_counts[$d] ?? 0) + 1;
                    $currentDate->modify('+1 day');
                }
            }
            $stmt_seminars->close();

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

    $response = [
        'success' => true,
        'booked_dates' => array_values(array_unique($bookedDates)),
        'hard_blocked_dates' => array_values(array_unique($hardBlockedDates))
    ];
    if ($selectedVenueAvailable !== null) $response['venue_available'] = $selectedVenueAvailable;
    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'booked_dates' => [], 'hard_blocked_dates' => [], 'message' => 'Availability could not be loaded. Please try again.']);
}
?>
