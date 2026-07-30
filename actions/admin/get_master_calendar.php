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
        $cat = $row['category'];
        
        // Define Background and distinct Border colors
        if ($row['booking_status'] === 'Pending') {
            $color = '#c27c7c'; $border = '#991b1b'; // Red
            $textColor = '#ffffff';
        } else {
            $textColor = '#ffffff';
            if (stripos($cat, 'Event') !== false) {
                $color = '#4a4440'; $border = '#1a1614'; // Charcoal
            } elseif (stripos($cat, 'Villa') !== false) {
                $color = '#88a096'; $border = '#4b6158'; // Green
            } else {
                $color = '#d6a870'; $border = '#a37a46'; // Gold (Default for Rooms)
            }
        }

        $endDateObj = new DateTime($row['end_date']);
        $endDateObj->modify('+1 day'); // FullCalendar requirement for end dates

        $events[] = [
            'id' => $row['id'],
            'title' => $row['last_name'] . ' - ' . $row['venue_name'],
            'start' => $row['start_date'],
            'end' => $endDateObj->format('Y-m-d'),
            'backgroundColor' => $color,
            'borderColor' => $border, // Passes the dark border to CSS
            'textColor' => $textColor,
            'display' => 'block',
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