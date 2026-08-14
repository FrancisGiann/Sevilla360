<?php
// actions/admin/get_dashboard_stats.php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['staff', 'admin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

try {
    $response = [];

    // 1. MAINTENANCE ALERTS
    $res = $conn->query("
        SELECT v.name, m.maintenance_type, m.notes 
        FROM maintenance m JOIN venues v ON m.venue_id = v.id 
        WHERE CURDATE() BETWEEN m.start_date AND m.end_date
    ");
    $response['maintenanceAlerts'] = $res->fetch_all(MYSQLI_ASSOC);

    // 2. ACTION REQUIRED
    $res = $conn->query("SELECT COUNT(*) as c FROM bookings WHERE booking_status = 'Pending'");
    $pendingBookings = (int)($res->fetch_assoc()['c'] ?? 0);
    $res = $conn->query("SELECT COUNT(*) as c FROM cancellations WHERE status = 'Pending'");
    $pendingCancels = (int)($res->fetch_assoc()['c'] ?? 0);
    $res = $conn->query("SELECT COUNT(*) as c FROM reschedule_requests WHERE status = 'Pending'");
    $pendingRescheds = (int)($res->fetch_assoc()['c'] ?? 0);
    $response['actionRequired'] = $pendingBookings + $pendingCancels + $pendingRescheds;

    // 3. TODAY'S OCCUPANCY RATE
    $res = $conn->query("SELECT COUNT(*) as c FROM venues WHERE status != 'Inactive'");
    $totalVenues = (int)($res->fetch_assoc()['c'] ?? 0);
    $res = $conn->query("SELECT COUNT(DISTINCT venue_id) as c FROM bookings WHERE booking_status IN ('Confirmed', 'Completed') AND CURDATE() BETWEEN start_date AND end_date");
    $activeVenues = (int)($res->fetch_assoc()['c'] ?? 0);
    $response['occupancyRate'] = $totalVenues > 0 ? round(($activeVenues / $totalVenues) * 100) : 0;

    // 4. TOP STATS (Revenue & Arrivals)
    $res = $conn->query("
        SELECT COALESCE(SUM(p.amount), 0) as total FROM payments p
        JOIN bookings b ON p.booking_id = b.id
        WHERE p.status = 'Success' AND b.booking_status != 'Cancelled'
        AND MONTH(p.payment_date) = MONTH(CURDATE()) AND YEAR(p.payment_date) = YEAR(CURDATE())
    ");
    $response['monthlyRevenue'] = (float)($res->fetch_assoc()['total'] ?? 0);

    $res = $conn->query("SELECT COUNT(*) as count FROM bookings WHERE start_date = CURDATE() AND booking_status IN ('Confirmed', 'Completed')");
    $response['arrivalsToday'] = $res->fetch_assoc()['count'] ?? 0;

    // 5. REVENUE TREND
    $sixMonthsAgo = date('Y-m-01', strtotime('-5 months'));
    $stmt = $conn->prepare("
        SELECT MONTH(p.payment_date) as m, YEAR(p.payment_date) as y, SUM(p.amount) as total 
        FROM payments p JOIN bookings b ON p.booking_id = b.id
        WHERE p.status = 'Success' AND b.booking_status != 'Cancelled' AND p.payment_date >= ? 
        GROUP BY y, m
    ");
    $stmt->bind_param("s", $sixMonthsAgo);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $dbRevenue = [];
    while ($row = $result->fetch_assoc()) {
        $key = $row['y'] . '-' . str_pad($row['m'], 2, '0', STR_PAD_LEFT);
        $dbRevenue[$key] = (float)$row['total'];
    }

    $revenueTrend = [];
    $monthLabels = [];
    for ($i = 5; $i >= 0; $i--) {
        $dateObj = strtotime("-$i months");
        $key = date('Y-m', $dateObj);
        $monthLabels[] = date('M', $dateObj);
        $revenueTrend[] = $dbRevenue[$key] ?? 0.00;
    }
    $response['charts']['revenue'] = ['labels' => $monthLabels, 'data' => $revenueTrend];

    // 6. BOOKING PIPELINE (Pie Chart)
    $res = $conn->query("SELECT booking_status, COUNT(*) as count FROM bookings GROUP BY booking_status");
    $statusData = ['Confirmed' => 0, 'Pending' => 0, 'Cancelled' => 0];
    while ($row = $res->fetch_assoc()) {
        if (isset($statusData[$row['booking_status']])) {
            $statusData[$row['booking_status']] = (int)$row['count'];
        }
    }
    $response['charts']['status'] = array_values($statusData);

    // 7. TODAY'S ITINERARY
    $res = $conn->query("
        SELECT c.first_name, c.last_name, v.name as venue_name, v.category,
               b.start_date, b.end_date, bed.stay_type
        FROM bookings b 
        JOIN customers c ON b.customer_id = c.id 
        JOIN venues v ON b.venue_id = v.id
        LEFT JOIN booking_villa_details bed ON b.id = bed.booking_id
        WHERE CURDATE() BETWEEN b.start_date AND b.end_date 
        AND b.booking_status IN ('Confirmed', 'Completed')
        ORDER BY b.start_date ASC
    ");
    $response['todaysOperations'] = $res->fetch_all(MYSQLI_ASSOC);

    // 8. UPCOMING MAJOR EVENTS (Next 30 Days)
    $res = $conn->query("
        SELECT b.id, b.start_date, b.end_date, v.name as venue_name, bed.event_type, bed.event_style, c.last_name
        FROM bookings b JOIN venues v ON b.venue_id = v.id JOIN customers c ON b.customer_id = c.id
        LEFT JOIN booking_event_details bed ON b.id = bed.booking_id
        WHERE v.category = 'Event Hall' AND b.booking_status = 'Confirmed'
        AND b.start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ORDER BY b.start_date ASC LIMIT 6
    ");
    $response['upcomingEvents'] = $res->fetch_all(MYSQLI_ASSOC);

    // 9. RECENT BOOKINGS
    $res = $conn->query("
        SELECT b.reference_no, v.name as venue_name, v.category as venue_category, 
               b.start_date, b.total_amount, b.booking_status, b.payment_status, 
               cx.status AS cancel_status, rr.status AS resched_status
        FROM bookings b JOIN venues v ON b.venue_id = v.id 
        LEFT JOIN cancellations cx ON b.id = cx.booking_id AND cx.status = 'Pending'
        LEFT JOIN reschedule_requests rr ON b.id = rr.booking_id AND rr.status = 'Pending'
        ORDER BY b.created_at DESC LIMIT 5
    ");
    $response['recentBookings'] = $res->fetch_all(MYSQLI_ASSOC);

    // 10. DEDICATED NOTIFICATIONS QUERY (Finds ALL pending actions, ignoring limits)
    $res = $conn->query("
        SELECT b.reference_no, b.start_date, v.name as venue_name, v.category as venue_category, 
               b.booking_status, cx.status as cancel_status, rr.status as resched_status
        FROM bookings b
        JOIN venues v ON b.venue_id = v.id
        LEFT JOIN cancellations cx ON b.id = cx.booking_id AND cx.status = 'Pending'
        LEFT JOIN reschedule_requests rr ON b.id = rr.booking_id AND rr.status = 'Pending'
        WHERE cx.status = 'Pending' 
           OR rr.status = 'Pending' 
           OR (v.category = 'Event Hall' AND b.booking_status = 'Pending')
        ORDER BY b.id DESC
    ");
    $response['notifications'] = $res->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
?>