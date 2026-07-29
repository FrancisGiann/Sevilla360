<?php
// actions/admin/get_dashboard_stats.php
session_start();
header('Content-Type: application/json');

require_once '../../config/db_connect.php';

// Security Check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

try {
    $response = [];

    // 1. TOP STATS
    // Monthly Revenue (Excluding Cancelled)
    $res = $conn->query("
        SELECT COALESCE(SUM(p.amount), 0) as total FROM payments p
        JOIN bookings b ON p.booking_id = b.id
        WHERE p.status = 'Success' AND b.booking_status != 'Cancelled'
        AND MONTH(p.payment_date) = MONTH(CURDATE()) AND YEAR(p.payment_date) = YEAR(CURDATE())
    ");
    $response['monthlyRevenue'] = (float)($res->fetch_assoc()['total'] ?? 0);

    // Pending Requests
    $res = $conn->query("SELECT COUNT(*) as count FROM bookings WHERE booking_status = 'Pending'");
    $response['pendingItems'] = $res->fetch_assoc()['count'] ?? 0;

    // Arrivals Today (Start Date is Today & Confirmed)
    $res = $conn->query("SELECT COUNT(*) as count FROM bookings WHERE start_date = CURDATE() AND booking_status IN ('Confirmed', 'Completed')");
    $response['arrivalsToday'] = $res->fetch_assoc()['count'] ?? 0;

    // Upcoming Events (Event Halls in next 30 days)
    $res = $conn->query("
        SELECT COUNT(*) as count 
        FROM bookings b JOIN venues v ON b.venue_id = v.id 
        WHERE v.category = 'Event Hall' AND b.booking_status = 'Confirmed'
        AND b.start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ");
    $response['upcomingEventsCount'] = $res->fetch_assoc()['count'] ?? 0;

    // 2. REVENUE TREND (Last 6 Months)
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

    // 3. BOOKING PIPELINE (Pie Chart)
    $res = $conn->query("SELECT booking_status, COUNT(*) as count FROM bookings GROUP BY booking_status");
    $statusData = ['Confirmed' => 0, 'Pending' => 0, 'Cancelled' => 0];
    while ($row = $res->fetch_assoc()) {
        if (isset($statusData[$row['booking_status']])) {
            $statusData[$row['booking_status']] = (int)$row['count'];
        }
    }
    $response['charts']['status'] = array_values($statusData);

    // 4. TODAY'S ITINERARY
    // Bookings that are active today (Arrivals + Currently staying)
    $res = $conn->query("
        SELECT c.first_name, c.last_name, v.name as venue_name, b.start_date, b.end_date
        FROM bookings b 
        JOIN customers c ON b.customer_id = c.id
        JOIN venues v ON b.venue_id = v.id
        WHERE CURDATE() BETWEEN b.start_date AND b.end_date
        AND b.booking_status IN ('Confirmed', 'Completed')
        ORDER BY b.start_date ASC
    ");
    $response['todaysOperations'] = $res->fetch_all(MYSQLI_ASSOC);

    // 5. UPCOMING MAJOR EVENTS (Next 30 Days)
    $res = $conn->query("
        SELECT b.start_date, b.end_date, v.name as venue_name, 
               bed.event_type, bed.event_style, c.last_name
        FROM bookings b 
        JOIN venues v ON b.venue_id = v.id
        JOIN customers c ON b.customer_id = c.id
        LEFT JOIN booking_event_details bed ON b.id = bed.booking_id
        WHERE v.category = 'Event Hall' 
        AND b.booking_status = 'Confirmed'
        AND b.start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ORDER BY b.start_date ASC
        LIMIT 6
    ");
    $response['upcomingEvents'] = $res->fetch_all(MYSQLI_ASSOC);


    // 6. RECENT BOOKINGS (Restored)
    $res = $conn->query("
        SELECT b.reference_no, v.name as venue_name, b.start_date, b.total_amount, 
               b.booking_status, b.payment_status,
               cx.status AS cancel_status, rr.status AS resched_status
        FROM bookings b 
        JOIN venues v ON b.venue_id = v.id 
        LEFT JOIN cancellations cx ON b.id = cx.booking_id AND cx.status = 'Pending'
        LEFT JOIN reschedule_requests rr ON b.id = rr.booking_id AND rr.status = 'Pending'
        ORDER BY b.created_at DESC LIMIT 5
    ");
    $response['recentBookings'] = $res->fetch_all(MYSQLI_ASSOC);

    echo json_encode($response);

} catch (Exception $e) {
    error_log("Dashboard API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
?>