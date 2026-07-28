<?php
// actions/admin/get_dashboard_stats.php
session_start();
header('Content-Type: application/json');

require_once '../../config/db_connect.php'; // Pulls in your $conn

// Security Check: Only Admin/Superadmin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

try {
    $response = [];

    // 1. TOP STATS
    // Bookings Today
    $res = $conn->query("SELECT COUNT(*) as count FROM bookings WHERE DATE(created_at) = CURDATE()");
    $response['bookingsToday'] = $res->fetch_assoc()['count'] ?? 0;

    // Monthly Revenue (Payments Table - Success only)
    $res = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE status = 'Success' AND MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE())");
    $response['monthlyRevenue'] = (float)($res->fetch_assoc()['total'] ?? 0);

    // Pending Items (Bookings)
    $res = $conn->query("SELECT COUNT(*) as count FROM bookings WHERE booking_status = 'Pending'");
    $response['pendingItems'] = $res->fetch_assoc()['count'] ?? 0;

    // Room Occupancy Today (Active bookings / Total active venues)
    $res = $conn->query("SELECT COUNT(*) as count FROM venues WHERE status != 'Inactive'");
    $totalVenues = (int)($res->fetch_assoc()['count'] ?? 0);

    $res = $conn->query("SELECT COUNT(DISTINCT venue_id) as count FROM bookings WHERE booking_status IN ('Confirmed', 'Completed') AND CURDATE() BETWEEN start_date AND end_date");
    $activeBookingsToday = (int)($res->fetch_assoc()['count'] ?? 0);

    $response['occupancyRate'] = $totalVenues > 0 ? round(($activeBookingsToday / $totalVenues) * 100) : 0;

    // 2. REVENUE TREND (Last 6 Months)
    $revenueTrend = [];
    $monthLabels = [];
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE status = 'Success' AND MONTH(payment_date) = ? AND YEAR(payment_date) = ?");
    
    for ($i = 5; $i >= 0; $i--) {
        $monthStr = date('M', strtotime("-$i months"));
        $monthNum = date('m', strtotime("-$i months"));
        $yearNum = date('Y', strtotime("-$i months"));
        
        $stmt->bind_param("ii", $monthNum, $yearNum);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $monthLabels[] = $monthStr;
        $revenueTrend[] = (float)($result->fetch_assoc()['total'] ?? 0);
    }
    $response['charts']['revenue'] = [
        'labels' => $monthLabels,
        'data' => $revenueTrend
    ];

    // 3. BOOKING STATUS (Pie Chart)
    $res = $conn->query("SELECT booking_status, COUNT(*) as count FROM bookings GROUP BY booking_status");
    $statusData = ['Confirmed' => 0, 'Pending' => 0, 'Cancelled' => 0];
    while ($row = $res->fetch_assoc()) {
        if (isset($statusData[$row['booking_status']])) {
            $statusData[$row['booking_status']] = (int)$row['count'];
        }
    }
    $response['charts']['status'] = array_values($statusData);

    // 4. OCCUPANCY BY AREA (Donut Chart)
    $res = $conn->query("
        SELECT v.category, COUNT(b.id) as count 
        FROM bookings b 
        JOIN venues v ON b.venue_id = v.id 
        WHERE b.booking_status IN ('Confirmed', 'Completed') 
        GROUP BY v.category
    ");
    $occupancyLabels = [];
    $occupancyData = [];
    while ($row = $res->fetch_assoc()) {
        $occupancyLabels[] = $row['category'];
        $occupancyData[] = (int)$row['count'];
    }
    $response['charts']['occupancy'] = [
        'labels' => $occupancyLabels,
        'data' => $occupancyData
    ];

    // 5. RECENT BOOKINGS TABLE (Updated to match granular statuses)
    $res = $conn->query("
        SELECT 
            b.reference_no, v.name as venue_name, b.start_date, b.total_amount, 
            b.booking_status, b.payment_status,
            cx.status AS cancel_status,
            rr.status AS resched_status
        FROM bookings b 
        JOIN venues v ON b.venue_id = v.id 
        LEFT JOIN cancellations cx ON b.id = cx.booking_id AND cx.status = 'Pending'
        LEFT JOIN reschedule_requests rr ON b.id = rr.booking_id AND rr.status = 'Pending'
        ORDER BY b.created_at DESC 
        LIMIT 5
    ");
    $response['recentBookings'] = $res->fetch_all(MYSQLI_ASSOC);

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>