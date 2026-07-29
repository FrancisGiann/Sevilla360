<?php
// actions/admin/get_master_calendar.php
session_start();
header('Content-Type: application/json');
require_once '../../config/db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $query = "
        SELECT b.id, b.start_date, b.end_date, b.booking_status, 
               v.name as venue_name, v.category, c.last_name
        FROM bookings b
        JOIN venues v ON b.venue_id = v.id
        JOIN customers c ON b.customer_id = c.id
        WHERE b.booking_status IN ('Confirmed', 'Pending', 'Completed')
    ";
    $result = $conn->query($query);
    $events = [];

    while ($row = $result->fetch_assoc()) {
        // Sevilla360 Premium Color Palette
        if ($row['booking_status'] === 'Pending') {
            $color = '#c27c7c'; // Muted Red for action required
            $textColor = '#ffffff';
        } else {
            $textColor = '#ffffff';
            if ($row['category'] === 'Event Hall') $color = '#4a4440'; // Dark Charcoal
            if ($row['category'] === 'Resort Villa') $color = '#88a096'; // Muted Green
            if ($row['category'] === 'Hotel Room') $color = '#d6a870'; // Gold
        }

        $endDateObj = new DateTime($row['end_date']);
        $endDateObj->modify('+1 day');

        $events[] = [
            'id' => $row['id'],
            'title' => $row['last_name'] . ' (' . $row['venue_name'] . ')',
            'start' => $row['start_date'],
            'end' => $endDateObj->format('Y-m-d'),
            'backgroundColor' => $color,
            'borderColor' => $color,
            'textColor' => $textColor,
            'display' => 'block', // Forces the nice solid pill look
            'extendedProps' => [
                'status' => $row['booking_status'],
                'category' => $row['category']
            ]
        ];
    }
    echo json_encode($events);
} catch (Exception $e) {
    echo json_encode(['error' => 'Database error']);
}
?>