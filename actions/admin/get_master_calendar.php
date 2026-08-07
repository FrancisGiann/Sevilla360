<?php
session_start();
header('Content-Type: application/json');
require_once '../../config/db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $events = [];

    // 1. Fetch CUSTOMER Bookings (Ignore dummy SYSTEM MAINTENANCE)
    $query_bookings = "
        SELECT b.id, b.start_date, b.end_date, b.booking_status, 
               v.name as venue_name, v.category, c.last_name
        FROM bookings b
        JOIN venues v ON b.venue_id = v.id
        JOIN customers c ON b.customer_id = c.id
        WHERE b.booking_status IN ('Confirmed', 'Pending', 'Completed')
        AND c.last_name != 'MAINTENANCE'
    ";
    $res_bookings = $conn->query($query_bookings);

    while ($row = $res_bookings->fetch_assoc()) {
        $cat = $row['category'];
        
        if ($row['booking_status'] === 'Pending') {
            $color = '#c27c7c'; $border = '#991b1b'; // Red
            $textColor = '#ffffff';
        } else {
            $textColor = '#ffffff';
            if (stripos($cat, 'Event') !== false) {
                $color = '#4a4440'; $border = '#1a1614'; 
            } elseif (stripos($cat, 'Villa') !== false) {
                $color = '#88a096'; $border = '#4b6158'; 
            } else {
                $color = '#d6a870'; $border = '#a37a46'; 
            }
        }

        $endDateObj = new DateTime($row['end_date']);
        $endDateObj->modify('+1 day'); 

        $events[] = [
            'id' => 'booking_' . $row['id'],
            'title' => $row['last_name'] . ' - ' . $row['venue_name'],
            'start' => $row['start_date'],
            'end' => $endDateObj->format('Y-m-d'),
            'backgroundColor' => $color,
            'borderColor' => $border, 
            'textColor' => $textColor,
            'display' => 'block',
            'extendedProps' => [
                'type' => 'booking',
                'status' => $row['booking_status'],
                'category' => $row['category']
            ]
        ];
    }

    // 2. Fetch MAINTENANCE Records
    $query_maint = "
        SELECT m.id, m.start_date, m.end_date, m.maintenance_type, m.is_blocking, 
               v.name as venue_name, v.category
        FROM maintenance m
        JOIN venues v ON m.venue_id = v.id
    ";
    $res_maint = $conn->query($query_maint);

    while ($row = $res_maint->fetch_assoc()) {
        $endDateObj = new DateTime($row['end_date']);
        $endDateObj->modify('+1 day'); 

        // Distinct styling for Maintenance (Slate Blue if blocking, faded gray if just a note)
        $color = $row['is_blocking'] ? '#64748b' : '#a3a3a3';
        $border = $row['is_blocking'] ? '#475569' : '#7a7a7a';

        $title_prefix = $row['is_blocking'] ? '🔧 BLOCKED: ' : '🧹 NOTE: ';

        $events[] = [
            'id' => 'maint_' . $row['id'],
            'title' => $title_prefix . $row['venue_name'],
            'start' => $row['start_date'],
            'end' => $endDateObj->format('Y-m-d'),
            'backgroundColor' => $color,
            'borderColor' => $border,
            'textColor' => '#ffffff',
            'display' => 'block',
            'extendedProps' => [
                'type' => 'maintenance',
                'task' => $row['maintenance_type'],
                'category' => $row['category']
            ]
        ];
    }

    echo json_encode($events);
} catch (Exception $e) {
    echo json_encode(['error' => 'Database error']);
}
?>