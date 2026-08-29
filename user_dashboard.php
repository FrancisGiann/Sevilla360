<?php
$required_role = 'customer';
require 'includes/auth_guard.php';
require_once 'config/db_connect.php';
require_once 'includes/refund_helper.php';
require_once 'includes/realtime.php';
require_once 'includes/booking_lifecycle.php';
$refund_fee_percent = get_refund_fee_percent($conn);
$realtime_client_config = realtime_client_config();
$booking_completion_sql = booking_completion_sql('b');

// 1. Get the Customer ID associated with this User Account
$user_id = $_SESSION['user_id'];

$stmt_cust = $conn->prepare("SELECT id, first_name, last_name, email, phone, special_req FROM customers WHERE user_id = ?");
$stmt_cust->bind_param("i", $user_id);
$stmt_cust->execute();
$customer_res = $stmt_cust->get_result();

if ($customer_res->num_rows === 0) {
    die("Customer profile not found. Please contact support.");
}
$customer = $customer_res->fetch_assoc();
$customer_id = $customer['id'];

// 2. Calculate dashboard-wide booking totals independently of the visible page.
$stmt_stats = $conn->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN b.booking_status = 'Pending' AND NOT $booking_completion_sql THEN 1 ELSE 0 END) AS pending, SUM(CASE WHEN b.booking_status = 'Confirmed' AND NOT $booking_completion_sql THEN 1 ELSE 0 END) AS confirmed FROM bookings b WHERE b.customer_id = ?");
$stmt_stats->bind_param("i", $customer_id);
$stmt_stats->execute();
$booking_stats = $stmt_stats->get_result()->fetch_assoc() ?: [];
$stat_total = (int) ($booking_stats['total'] ?? 0);
$stat_pending = (int) ($booking_stats['pending'] ?? 0);
$stat_confirmed = (int) ($booking_stats['confirmed'] ?? 0);
$stmt_stats->close();

$stmt_upcoming = $conn->prepare("
    SELECT b.id, b.reference_no, b.start_date, b.end_date, b.total_amount, b.amount_paid,
        b.booking_status, CASE WHEN $booking_completion_sql THEN 'Completed' ELSE b.booking_status END AS display_booking_status,
        b.payment_status, v.name AS venue_name,
        EXISTS (SELECT 1 FROM cancellations cx WHERE cx.booking_id = b.id AND cx.status = 'Pending') AS cancel_pending,
        EXISTS (SELECT 1 FROM reschedule_requests rr WHERE rr.booking_id = b.id AND rr.status = 'Pending') AS resched_pending,
        EXISTS (SELECT 1 FROM booking_checkout_sessions bcs WHERE bcs.booking_id = b.id AND bcs.status IN ('creating', 'created', 'paid') AND bcs.provider_session_id IS NOT NULL) AS checkout_pending
    FROM bookings b
    INNER JOIN venues v ON v.id = b.venue_id
    WHERE b.customer_id = ? AND b.booking_status = 'Confirmed' AND b.start_date >= CURDATE() AND b.end_date >= CURDATE()
    ORDER BY b.start_date ASC LIMIT 1
");
$stmt_upcoming->bind_param('i', $customer_id);
$stmt_upcoming->execute();
$upcoming_booking = $stmt_upcoming->get_result()->fetch_assoc() ?: null;
$stmt_upcoming->close();

$stmt_upcoming_count = $conn->prepare("SELECT COUNT(*) AS upcoming_count FROM bookings b WHERE b.customer_id = ? AND b.booking_status = 'Confirmed' AND b.start_date >= CURDATE() AND b.end_date >= CURDATE()");
$stmt_upcoming_count->bind_param('i', $customer_id);
$stmt_upcoming_count->execute();
$upcoming_count = (int)($stmt_upcoming_count->get_result()->fetch_assoc()['upcoming_count'] ?? 0);
$stmt_upcoming_count->close();

$stmt_balance = $conn->prepare("SELECT COALESCE(SUM(GREATEST(b.total_amount - b.amount_paid, 0)), 0) AS balance_due FROM bookings b WHERE b.customer_id = ? AND b.booking_status <> 'Cancelled' AND NOT $booking_completion_sql");
$stmt_balance->bind_param('i', $customer_id);
$stmt_balance->execute();
$balance_due = (float)($stmt_balance->get_result()->fetch_assoc()['balance_due'] ?? 0);
$stmt_balance->close();

$booking_limit = 10;
$booking_page = filter_input(INPUT_GET, 'booking_page', FILTER_VALIDATE_INT);
$booking_page = ($booking_page && $booking_page > 0) ? $booking_page : 1;
$booking_pages = max(1, (int) ceil($stat_total / $booking_limit));
$booking_page = min($booking_page, $booking_pages);
$booking_offset = ($booking_page - 1) * $booking_limit;

// 3. Fetch only the current page of bookings.
$stmt_bookings = $conn->prepare("
    SELECT 
        b.*, CASE WHEN $booking_completion_sql THEN 'Completed' ELSE b.booking_status END AS display_booking_status,
        v.name AS venue_name, 
        v.category AS venue_type,
        hr.room_type AS hotel_room_type,
        cx.status AS cancel_status,
        rr.status AS resched_status,
        EXISTS (SELECT 1 FROM reschedule_requests rr_done WHERE rr_done.booking_id = b.id AND rr_done.status = 'Approved') AS has_rescheduled,
        EXISTS (SELECT 1 FROM booking_checkout_sessions bcs WHERE bcs.booking_id = b.id AND bcs.status IN ('creating','created','paid') AND bcs.provider_session_id IS NOT NULL) AS has_checkout_session,
        (SELECT p2.transaction_id FROM payments p2 WHERE p2.booking_id = b.id ORDER BY p2.id DESC LIMIT 1) AS transaction_id
    FROM bookings b
    JOIN venues v ON b.venue_id = v.id
    LEFT JOIN hotel_rooms hr ON v.id = hr.venue_id
    LEFT JOIN cancellations cx ON b.id = cx.booking_id
    LEFT JOIN reschedule_requests rr ON b.id = rr.booking_id AND rr.status = 'Pending'
    WHERE b.customer_id = ?
    ORDER BY b.id DESC
    LIMIT ? OFFSET ?
");
$stmt_bookings->bind_param("iii", $customer_id, $booking_limit, $booking_offset);
$stmt_bookings->execute();
$bookings_result = $stmt_bookings->get_result();

$bookings = [];

while ($row = $bookings_result->fetch_assoc()) {
    $bookings[] = $row;
}
$payment_sync_booking_id = null;
foreach ($bookings as $booking_row) {
    if (!empty($booking_row['has_checkout_session']) && !booking_is_completed($booking_row) && $booking_row['booking_status'] !== 'Cancelled' && $booking_row['payment_status'] !== 'Paid') {
        $payment_sync_booking_id = (int)$booking_row['id'];
        break;
    }
}

// 4. Fetch Notifications
$stmt_notifs = $conn->prepare("SELECT id, title, message, is_read, created_at FROM user_notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$stmt_notifs->bind_param("i", $user_id);
$stmt_notifs->execute();
$notifs_result = $stmt_notifs->get_result();

$notifications = [];
$unread_count = 0;
$latest_unread = null;
while ($row = $notifs_result->fetch_assoc()) {
    $notifications[] = $row;
    if ($row['is_read'] == 0) {
        $unread_count++;
        if ($latest_unread === null) {
            $latest_unread = $row;
        }
    }
}

// Overview-only previews stay customer-scoped and deliberately remain
// separate from the paginated booking-management query below.
$stmt_overview_recent = $conn->prepare("
    SELECT b.id, b.reference_no, b.start_date, b.end_date, b.total_amount, b.amount_paid,
        b.booking_status, CASE WHEN $booking_completion_sql THEN 'Completed' ELSE b.booking_status END AS display_booking_status,
        b.payment_status, v.name AS venue_name,
        EXISTS (SELECT 1 FROM cancellations cx WHERE cx.booking_id = b.id AND cx.status = 'Pending') AS cancel_pending,
        EXISTS (SELECT 1 FROM reschedule_requests rr WHERE rr.booking_id = b.id AND rr.status = 'Pending') AS resched_pending,
        EXISTS (SELECT 1 FROM booking_checkout_sessions bcs WHERE bcs.booking_id = b.id AND bcs.status IN ('creating', 'created', 'paid') AND bcs.provider_session_id IS NOT NULL) AS checkout_pending
    FROM bookings b
    INNER JOIN venues v ON v.id = b.venue_id
    WHERE b.customer_id = ?
    ORDER BY b.id DESC
    LIMIT 4
");
$stmt_overview_recent->bind_param('i', $customer_id);
$stmt_overview_recent->execute();
$overview_recent = $stmt_overview_recent->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_overview_recent->close();

$stmt_attention = $conn->prepare("
    SELECT b.id, b.reference_no, b.start_date, b.end_date, b.total_amount, b.amount_paid,
        b.booking_status, CASE WHEN $booking_completion_sql THEN 'Completed' ELSE b.booking_status END AS display_booking_status,
        b.payment_status, v.name AS venue_name,
        EXISTS (SELECT 1 FROM cancellations cx WHERE cx.booking_id = b.id AND cx.status = 'Pending') AS cancel_pending,
        EXISTS (SELECT 1 FROM reschedule_requests rr WHERE rr.booking_id = b.id AND rr.status = 'Pending') AS resched_pending,
        EXISTS (SELECT 1 FROM booking_checkout_sessions bcs WHERE bcs.booking_id = b.id AND bcs.status IN ('creating', 'created', 'paid') AND bcs.provider_session_id IS NOT NULL) AS checkout_pending
    FROM bookings b
    INNER JOIN venues v ON v.id = b.venue_id
    WHERE b.customer_id = ? AND b.booking_status <> 'Cancelled' AND NOT $booking_completion_sql
      AND (
        b.booking_status = 'Pending'
        OR (b.booking_status = 'Confirmed' AND b.payment_status IN ('Unpaid', 'Partial'))
        OR (b.payment_status IN ('Unpaid', 'Partial') AND EXISTS (
            SELECT 1 FROM booking_checkout_sessions bcs_pending
            WHERE bcs_pending.booking_id = b.id
              AND bcs_pending.status IN ('creating', 'created', 'paid')
              AND bcs_pending.provider_session_id IS NOT NULL
        ))
        OR EXISTS (SELECT 1 FROM cancellations cx2 WHERE cx2.booking_id = b.id AND cx2.status = 'Pending')
        OR EXISTS (SELECT 1 FROM reschedule_requests rr2 WHERE rr2.booking_id = b.id AND rr2.status = 'Pending')
      )
    ORDER BY b.start_date ASC, b.id DESC
    LIMIT 5
");
$stmt_attention->bind_param('i', $customer_id);
$stmt_attention->execute();
$attention_items = $stmt_attention->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_attention->close();

$initial_section = $_GET['section'] ?? 'overview';
if (!in_array($initial_section, ['overview', 'bookings', 'settings'], true)) {
    $initial_section = 'overview';
}
$format_dashboard_date = static function ($start, $end = null): string {
    if (empty($start)) return 'Date to be arranged';
    $start_date = new DateTime($start);
    if (empty($end) || $start === $end) return $start_date->format('M j, Y');
    return $start_date->format('M j') . ' – ' . (new DateTime($end))->format('M j, Y');
};
$dashboard_status = static function (array $booking): array {
    if (booking_is_completed($booking)) return ['Completed', 'badge-completed'];
    if (!empty($booking['cancel_pending'])) return ['Pending refund', 'badge-cancelled'];
    if (!empty($booking['resched_pending'])) return ['Reschedule requested', 'badge-reschedule'];
    if ($booking['booking_status'] === 'Cancelled') return ['Cancelled', 'badge-cancelled'];
    if (!empty($booking['checkout_pending']) && in_array($booking['payment_status'], ['Unpaid', 'Partial'], true)) return ['Payment in progress', 'badge-pending'];
    if ($booking['booking_status'] === 'Pending') return ['Pending review', 'badge-pending'];
    if ($booking['payment_status'] === 'Paid') return ['Fully paid', 'badge-paid'];
    if ($booking['payment_status'] === 'Partial') return ['Partially paid', 'badge-partial'];
    return ['Payment due', 'badge-pending'];
};
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        (function () {
            try {
                if (window.matchMedia('(min-width: 993px)').matches && window.localStorage.getItem('sevilla360-customer-sidebar-collapsed') === '1') {
                    document.documentElement.classList.add('customer-sidebar-precollapsed');
                }
            } catch (error) { /* Storage may be disabled; keep the expanded baseline. */ }
        }());
    </script>
    <link rel="icon" type="image/png" href="assets/img/Logo.png">
    <meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?? ''; ?>">
    <title>Dashboard | SEVILLA360</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Playfair+Display:ital,wght@0,400;0,500;0,600;1,400&family=Great+Vibes&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="assets/css/user_dashboard.css?v=<?= time() ?>">
    <link rel="stylesheet" href="assets/css/ui-refinement.css?v=<?= filemtime(__DIR__ . '/assets/css/ui-refinement.css'); ?>">
</head>

<body class="dashboard-body">
    <script>window.sevillaRealtimeConfig = <?php echo json_encode($realtime_client_config, JSON_UNESCAPED_SLASHES); ?>;</script>
    <script src="assets/js/realtime_notifications.js?v=<?= time() ?>"></script>
    <script>window.refundFeePercent = <?php echo json_encode($refund_fee_percent); ?>;</script>
    <div class="dashboard-layout">
        <!-- Sidebar Backdrop Overlay for Mobile -->
        <div id="sidebar-overlay" class="sidebar-overlay"></div>

        <!-- LEFT SIDEBAR -->
        <aside class="dashboard-sidebar">
            <div class="sidebar-header">
                <a href="index.php" class="brand-logo">SEVILLA360</a>
                <button type="button" id="btn-sidebar-collapse" class="sidebar-collapse-toggle"
                    aria-label="Minimize sidebar" aria-pressed="false" title="Minimize sidebar">
                    <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                </button>
                <button id="btn-close-sidebar" class="sidebar-close-btn" aria-label="Close sidebar">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="user-profile">
                <div class="avatar">
                    <?php echo strtoupper(substr($customer['first_name'], 0, 1) . substr($customer['last_name'], 0, 1)); ?>
                </div>
                <div class="user-info">
                    <h3 class="user-name">
                        <?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?></h3>
                    <p class="user-email"><?php echo htmlspecialchars($customer['email']); ?></p>
                </div>
            </div>

            <nav class="sidebar-nav">
                <p class="nav-heading">MENU</p>
                <ul class="nav-list">
                    <li class="nav-item <?php echo $initial_section === 'overview' ? 'active' : ''; ?>" data-tab="overview">
                        <a href="user_dashboard.php?section=overview" class="nav-link" <?php echo $initial_section === 'overview' ? 'aria-current="page"' : ''; ?>><i class="fa-solid fa-chart-line"></i><span>Overview</span></a>
                    </li>
                    <li class="nav-item <?php echo $initial_section === 'bookings' ? 'active' : ''; ?>" data-tab="bookings">
                        <a href="user_dashboard.php?section=bookings" class="nav-link" <?php echo $initial_section === 'bookings' ? 'aria-current="page"' : ''; ?>><i class="fa-solid fa-bars"></i><span>My Bookings</span></a>
                    </li>
                    <li class="nav-item <?php echo $initial_section === 'settings' ? 'active' : ''; ?>" data-tab="settings">
                        <a href="user_dashboard.php?section=settings" class="nav-link" <?php echo $initial_section === 'settings' ? 'aria-current="page"' : ''; ?>><i class="fa-regular fa-user"></i><span>Settings</span></a>
                    </li>
                </ul>
            </nav>

            <div class="sidebar-footer">
                <a href="actions/auth/logout.php" class="nav-link sign-out">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i><span>Sign out</span>
                </a>
            </div>
        </aside>

        <!-- MAIN CONTENT -->
        <main class="dashboard-main">

            <header class="dashboard-topbar">
                <div class="topbar-left">
                    <button id="btn-mobile-sidebar-toggle" class="mobile-menu-btn" aria-label="Open menu">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                    <a href="index.php" class="mobile-brand-logo">SEVILLA360</a>
                </div>

                <div class="topbar-right">
                    <!-- Notification Bell -->
                    <div class="notification-container">
                        <button id="btn-notifications" aria-label="Notifications">
                            <i class="fa-regular fa-bell"></i>
                            <?php if($unread_count > 0): ?>
                                <span id="notif-badge">
                                    <?php echo $unread_count; ?>
                                </span>
                            <?php endif; ?>
                        </button>
                        
                        <!-- Dropdown -->
                        <div id="notif-dropdown">
                            <div class="notif-dropdown-header">
                                <h4>Notifications</h4>
                                <?php if($unread_count > 0): ?>
                                    <button id="btn-mark-read">Mark all as read</button>
                                <?php endif; ?>
                            </div>
                            <div class="notif-list-body">
                                <?php if(empty($notifications)): ?>
                                    <div class="notif-empty-state">No notifications yet.</div>
                                <?php else: ?>
                                    <?php foreach($notifications as $n): 
                                        $icon = 'fa-bell';
                                        $color = '#d6a870';
                                        $t = strtolower($n['title']);
                                        if (strpos($t, 'payment') !== false) { $icon = 'fa-money-bill-wave'; $color = '#28a745'; }
                                        elseif (strpos($t, 'cancel') !== false || strpos($t, 'reject') !== false) { $icon = 'fa-circle-xmark'; $color = '#dc3545'; }
                                        elseif (strpos($t, 'reschedule') !== false) { $icon = 'fa-calendar-day'; $color = '#17a2b8'; }
                                        elseif (strpos($t, 'booking') !== false || strpos($t, 'quotation') !== false) { $icon = 'fa-calendar-check'; $color = '#d6a870'; }
                                    ?>
                                        <div class="notif-item <?php echo $n['is_read'] ? '' : 'unread'; ?>" data-id="<?php echo $n['id']; ?>" data-title="<?php echo htmlspecialchars($n['title']); ?>" data-message="<?php echo htmlspecialchars($n['message']); ?>">
                                            <div class="notif-item-icon" style="color: <?php echo $color; ?>;">
                                                <i class="fa-solid <?php echo $icon; ?>"></i>
                                            </div>
                                            <div>
                                                <h5 class="notif-item-title"><?php echo htmlspecialchars($n['title']); ?></h5>
                                                <p class="notif-item-msg"><?php echo htmlspecialchars($n['message']); ?></p>
                                                <span class="notif-item-time"><?php echo date('M j, Y h:i A', strtotime($n['created_at'])); ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <a href="index.php" class="btn-topbar"><i class="fa-solid fa-house"></i> <span>Back to Home</span></a>
                </div>
            </header>

            <div class="dashboard-content">

                <!-- ================= TAB: OVERVIEW ================= -->
                <section id="tab-overview" class="tab-pane dashboard-overview <?php echo $initial_section === 'overview' ? 'active' : ''; ?>" aria-labelledby="overview-title">
                    <div class="overview-welcome">
                        <div>
                            <p class="overview-eyebrow">YOUR SEVILLA360 DASHBOARD</p>
                            <h1 id="overview-title">Welcome back, <?php echo htmlspecialchars($customer['first_name']); ?>.</h1>
                            <p class="overview-intro">Keep your reservations, payments and preferences in one considered place.</p>
                        </div>
                        <a href="booking.php" class="btn-primary-dash overview-primary-cta"><i class="fa-solid fa-plus"></i> Book a Venue</a>
                    </div>

                    <section class="dashboard-summary-grid overview-kpis" aria-label="Booking overview">
                        <div class="dashboard-summary-card">
                            <span class="summary-card-label">Upcoming</span>
                            <strong><?php echo $upcoming_count; ?></strong>
                            <small><?php echo $upcoming_count ? 'Confirmed booking' . ($upcoming_count === 1 ? '' : 's') . ' ahead' : 'No upcoming bookings'; ?></small>
                        </div>
                        <div class="dashboard-summary-card">
                            <span class="summary-card-label">Pending</span>
                            <strong><?php echo $stat_pending; ?></strong>
                            <small>Awaiting confirmation or payment</small>
                        </div>
                        <div class="dashboard-summary-card">
                            <span class="summary-card-label">Confirmed</span>
                            <strong><?php echo $stat_confirmed; ?></strong>
                            <small><?php echo $stat_total; ?> total booking<?php echo $stat_total === 1 ? '' : 's'; ?></small>
                        </div>
                        <div class="dashboard-summary-card">
                            <span class="summary-card-label">Outstanding balance</span>
                            <strong>₱<?php echo number_format($balance_due, 2); ?></strong>
                            <small><?php echo $balance_due > 0 ? 'Payment action may be needed' : 'You are all caught up'; ?></small>
                        </div>
                    </section>

                    <div class="overview-main-grid">
                        <section class="overview-card next-booking-card" aria-labelledby="next-booking-title">
                            <div class="overview-card-heading">
                                <div>
                                    <p class="overview-eyebrow">NEXT BOOKING</p>
                                    <h2 id="next-booking-title">Your upcoming stay</h2>
                                </div>
                                <?php if ($upcoming_booking) { [$next_status_text, $next_status_class] = $dashboard_status($upcoming_booking); } ?>
                                <span class="badge <?php echo $upcoming_booking ? $next_status_class : 'badge-pending'; ?>"><?php echo htmlspecialchars($upcoming_booking ? $next_status_text : 'None yet'); ?></span>
                            </div>
                            <?php if ($upcoming_booking): ?>
                            <div class="next-booking-details">
                                <strong><?php echo htmlspecialchars($upcoming_booking['venue_name']); ?></strong>
                                <span><i class="fa-regular fa-calendar"></i> <?php echo htmlspecialchars($format_dashboard_date($upcoming_booking['start_date'], $upcoming_booking['end_date'])); ?></span>
                                <span><i class="fa-solid fa-receipt"></i> <?php echo $upcoming_booking['total_amount'] > 0 ? '₱' . number_format((float)$upcoming_booking['total_amount'], 2) : 'Amount to be arranged'; ?> · <?php echo htmlspecialchars($upcoming_booking['payment_status']); ?></span>
                            </div>
                            <div class="overview-card-actions">
                                <button type="button" class="btn-outline-dash btn-details" data-id="<?php echo (int)$upcoming_booking['id']; ?>"><i class="fa-solid fa-file-invoice"></i> View details</button>
                                <?php if ($upcoming_booking['payment_status'] !== 'Paid' && $upcoming_booking['booking_status'] !== 'Cancelled'): ?>
                                <button type="button" class="btn-primary-dash btn-pay-now" data-id="<?php echo (int)$upcoming_booking['id']; ?>">Pay now</button>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="overview-empty-state"><i class="fa-regular fa-calendar"></i><p>No upcoming booking yet.</p><a href="booking.php" class="btn-primary-dash">Find your venue</a></div>
                            <?php endif; ?>
                        </section>

                        <section class="overview-card attention-card" aria-labelledby="attention-title">
                            <div class="overview-card-heading">
                                <div>
                                    <p class="overview-eyebrow">ACTION NEEDED</p>
                                    <h2 id="attention-title">A little attention</h2>
                                </div>
                            </div>
                            <?php if (empty($attention_items)): ?>
                            <div class="overview-empty-state compact"><i class="fa-solid fa-check"></i><p>Nothing needs your attention right now.</p></div>
                            <?php else: ?>
                            <ul class="attention-list">
                                <?php foreach ($attention_items as $attention): [$attention_text, $attention_class] = $dashboard_status($attention); ?>
                                <li>
                                    <div><span class="badge <?php echo $attention_class; ?>"><?php echo htmlspecialchars($attention_text); ?></span><strong><?php echo htmlspecialchars($attention['venue_name']); ?></strong><small><?php echo htmlspecialchars($format_dashboard_date($attention['start_date'], $attention['end_date'])); ?></small></div>
                                    <a href="user_dashboard.php?section=bookings#booking-<?php echo (int)$attention['id']; ?>" data-dashboard-section="bookings" aria-label="Open booking <?php echo htmlspecialchars($attention['reference_no'] ?: (string)$attention['id']); ?>">View</a>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>
                        </section>
                    </div>

                    <section class="overview-card quick-actions-card" aria-labelledby="quick-actions-title">
                        <div class="overview-card-heading"><div><p class="overview-eyebrow">SHORTCUTS</p><h2 id="quick-actions-title">Quick actions</h2></div></div>
                        <div class="quick-actions-grid">
                            <a href="booking.php"><i class="fa-solid fa-plus"></i><span>Book a venue</span><small>Start a new reservation</small></a>
                            <a href="user_dashboard.php?section=bookings" data-dashboard-section="bookings"><i class="fa-solid fa-clock-rotate-left"></i><span>View booking history</span><small>See all reservations</small></a>
                            <a href="user_dashboard.php?section=settings" data-dashboard-section="settings"><i class="fa-regular fa-user"></i><span>Update profile</span><small>Preferences and security</small></a>
                            <a href="support.php#contact"><i class="fa-regular fa-comment-dots"></i><span>Contact support</span><small>We are happy to help</small></a>
                        </div>
                    </section>

                    <div class="overview-lower-grid">
                        <section class="overview-card recent-bookings-card" aria-labelledby="recent-bookings-title">
                            <div class="overview-card-heading"><div><p class="overview-eyebrow">RECENT</p><h2 id="recent-bookings-title">Recent bookings</h2></div><a href="user_dashboard.php?section=bookings" data-dashboard-section="bookings">View all</a></div>
                            <?php if (empty($overview_recent)): ?>
                            <div class="overview-empty-state compact"><i class="fa-regular fa-calendar-xmark"></i><p>No bookings yet.</p><a href="booking.php" class="text-link">Book your first venue</a></div>
                            <?php else: ?>
                            <div class="recent-bookings-list">
                                <?php foreach ($overview_recent as $recent): [$recent_status_text, $recent_status_class] = $dashboard_status($recent); ?>
                                <article class="recent-booking-row" id="overview-booking-<?php echo (int)$recent['id']; ?>">
                                    <div><strong><?php echo htmlspecialchars($recent['venue_name']); ?></strong><small><?php echo htmlspecialchars($format_dashboard_date($recent['start_date'], $recent['end_date'])); ?></small></div>
                                    <span class="badge <?php echo $recent_status_class; ?>"><?php echo htmlspecialchars($recent_status_text); ?></span>
                                    <button type="button" class="btn-icon-link btn-details" data-id="<?php echo (int)$recent['id']; ?>" aria-label="View details for <?php echo htmlspecialchars($recent['venue_name']); ?>"><i class="fa-solid fa-arrow-up-right-from-square"></i></button>
                                </article>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </section>

                        <section class="overview-card recent-notifications-card" aria-labelledby="recent-notifications-title">
                            <div class="overview-card-heading"><div><p class="overview-eyebrow">UPDATES</p><h2 id="recent-notifications-title">Recent activity</h2></div><button type="button" class="btn-icon-link" id="overview-open-notifications" aria-label="Open notifications"><i class="fa-regular fa-bell"></i></button></div>
                            <?php if (empty($notifications)): ?>
                            <div class="overview-empty-state compact"><i class="fa-regular fa-bell-slash"></i><p>No notifications yet.</p></div>
                            <?php else: ?>
                            <ul class="recent-notifications-list">
                                <?php foreach (array_slice($notifications, 0, 4) as $activity): ?>
                                <li class="<?php echo $activity['is_read'] ? '' : 'is-unread'; ?>"><span class="activity-dot"></span><div><strong><?php echo htmlspecialchars($activity['title']); ?></strong><small><?php echo htmlspecialchars($activity['message']); ?></small><time datetime="<?php echo htmlspecialchars($activity['created_at']); ?>"><?php echo htmlspecialchars(date('M j, Y', strtotime($activity['created_at']))); ?></time></div></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>
                        </section>
                    </div>
                </section>

                <!-- ================= TAB: MY BOOKINGS ================= -->
                <div id="tab-bookings" class="tab-pane <?php echo $initial_section === 'bookings' ? 'active' : ''; ?>">
                    <div class="content-header">
                        <div class="header-titles">
                            <h1 class="page-title">MY BOOKINGS</h1>
                            <p class="page-subtitle">TRACK AND MANAGE ALL YOUR RESERVATIONS</p>
                        </div>
                        <div class="header-actions">
                            <button class="btn-outline-dash" onclick="window.location.reload();"><i
                                    class="fa-solid fa-rotate-right"></i> Refresh</button>
                            <a href="booking.php" class="no-underline"><button class="btn-primary-dash"><i
                                        class="fa-solid fa-plus"></i> New Booking</button></a>
                        </div>
                    </div>

                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-value text-gold"><?php echo $stat_total; ?></div>
                            <div class="stat-label">TOTAL BOOKINGS</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $stat_pending; ?></div>
                            <div class="stat-label">PENDING PAYMENT</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value text-green"><?php echo $stat_confirmed; ?></div>
                            <div class="stat-label">CONFIRMED</div>
                        </div>
                    </div>

                    <div class="history-container">
                        <div class="history-header">
                            <h2>Booking History</h2>
                            <div class="filter-pills" id="statusFiltersDesktop">
                                <button class="filter-pill active" data-filter="All">All</button>
                                <button class="filter-pill" data-filter="Pending">Pending</button>
                                <button class="filter-pill" data-filter="Partially Paid">Partially Paid</button>
                                <button class="filter-pill" data-filter="Paid">Paid</button>
                                <button class="filter-pill" data-filter="Completed">Completed</button>
                                <button class="filter-pill" data-filter="Cancelled">Cancelled</button>
                            </div>

                            <div class="status-filter status-filter-mobile" id="statusFiltersMobile">
                                <label for="statusFilter">Status</label>
                                <select id="statusFilter" class="status-filter-select" aria-label="Filter bookings by status">
                                    <option value="All">All</option>
                                    <option value="Pending">Pending</option>
                                    <option value="Partially Paid">Partially Paid</option>
                                    <option value="Paid">Paid</option>
                                    <option value="Completed">Completed</option>
                                    <option value="Cancelled">Cancelled</option>
                                </select>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="history-table" id="bookingsTable">
                                <thead>
                                    <tr>
                                        <th>Booking ID</th>
                                        <th>VENUE</th>
                                        <th>DATE</th>
                                        <th>AMOUNT</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($bookings)): ?>
                                    <tr>
                                        <td colspan="6" class="empty-table-cell">You have no bookings yet. Time to plan a vacation!</td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($bookings as $b): 
                                        $start = new DateTime($b['start_date']);
                                        $end = new DateTime($b['end_date']);
                                        $date_str = ($b['start_date'] === $b['end_date']) ? $start->format('M j, Y') : $start->format('M j') . ' - ' . $end->format('M j, Y');
                                        $display_status = $b['display_booking_status'] ?? $b['booking_status'];
                                        $is_completed = ($display_status === 'Completed');

                                        $total_amt = floatval($b['total_amount']);
                                        $amount_paid = floatval($b['amount_paid']);
                                        $actual_room_type = ($b['venue_type'] === 'Hotel Room') ? $b['hotel_room_type'] : $b['venue_type'];
                                        $is_pending_inquiry = ($b['venue_type'] === 'Event Hall' && $display_status === 'Pending');

                                        $display_amount = '₱' . number_format($total_amt, 2);
                                        if ($is_pending_inquiry) {
                                            $display_amount = '<span class="text-tba">To Be Arranged</span>';
                                        }

                                        // Status badge logic
                                        $badge_class = 'badge-pending'; 
                                        $status_text = 'Pending Payment';
                                        $filter_data = 'Pending';

                                        if ($is_completed) {
                                            $badge_class = 'badge-completed';
                                            $status_text = 'Completed';
                                            $filter_data = 'Completed';
                                        } elseif ($is_pending_inquiry) {
                                            $status_text = 'Inquiry Sent';
                                        } elseif ($display_status === 'Confirmed') {
                                            if ($b['payment_status'] === 'Paid') {
                                                $badge_class = 'badge-paid';
                                                $status_text = 'Fully Paid';
                                                $filter_data = 'Paid';
                                            } elseif ($b['payment_status'] === 'Partial') {
                                                $badge_class = 'badge-partial';
                                                $status_text = 'Partially Paid';
                                                $filter_data = 'Partially Paid';
                                            } else {
                                                $badge_class = 'badge-pending'; 
                                                $status_text = 'Unpaid';
                                                $filter_data = 'Pending';
                                            }
                                        } elseif ($display_status === 'Cancelled') {
                                            $badge_class = 'badge-cancelled';
                                            $status_text = 'Cancelled';
                                            $filter_data = 'Cancelled';
                                        }

                                        // OVERRIDE TEXT IF A REQUEST IS PENDING
                                        if (!$is_completed && $b['cancel_status'] === 'Pending') {
                                            $status_text = 'Pending Refund';
                                            $badge_class = 'badge-cancelled'; 
                                        } elseif (!$is_completed && $b['resched_status'] === 'Pending') {
                                            $status_text = 'Resched Requested';
                                            $badge_class = 'badge-reschedule';  
                                        }

                                        $display_id = !empty($b['reference_no']) ? htmlspecialchars($b['reference_no']) : '#' . $b['id'];
                                    ?>
                                    <tr id="booking-<?php echo (int)$b['id']; ?>" data-status="<?php echo htmlspecialchars($filter_data, ENT_QUOTES, 'UTF-8'); ?>">

                                        <td class="booking-ref-id" data-label="Booking ID">
                                            <?php echo $display_id; ?>
                                        </td>
                                        <td data-label="Venue"><?php echo htmlspecialchars($b['venue_name']); ?></td>
                                        <td data-label="Date"><?php echo $date_str; ?></td>
                                        <td
                                            data-label="Amount"
                                            class="<?php echo ($display_status === 'Cancelled') ? 'text-muted' : ''; ?>">
                                            <?php echo $display_amount; ?>
                                        </td>
                                        <td data-label="Status">
                                            <span class="badge <?php echo $badge_class; ?>">
                                                <?php echo (!$is_completed && $b['cancel_status'] === 'Pending') ? 'Pending Refund' : $status_text; ?>
                                            </span>
                                            <?php if (!$is_completed && !empty($b['has_rescheduled']) && $display_status === 'Confirmed' && $b['cancel_status'] !== 'Pending'): ?>
                                            <span class="badge badge-reschedule">Rescheduled &amp; Confirmed</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Actions">
                                            <div class="action-cell">
                                                <?php if (!$is_completed && $b['cancel_status'] !== 'Pending' && ($display_status === 'Pending' || ($display_status === 'Confirmed' && in_array($b['payment_status'], ['Unpaid', 'Partial'])))): ?>
                                                <?php if (!$is_pending_inquiry): ?>
                                                <button class="btn-action btn-pay btn-pay-now"
                                                    data-id="<?php echo $b['id']; ?>">Pay Now</button>
                                                <?php endif; ?>
                                                <?php if (!$is_completed && !empty($b['has_checkout_session']) && $b['payment_status'] !== 'Paid' && $display_status !== 'Cancelled'): ?>
                                                <button class="btn-action btn-outline-action btn-sync-payment" data-id="<?php echo (int)$b['id']; ?>">Sync Payment</button>
                                                <?php endif; ?>
                                                <?php endif; ?>

                                                <?php if (!$is_completed && $display_status !== 'Cancelled' && $b['cancel_status'] !== 'Pending'): ?>
                                                <?php if ($display_status === 'Confirmed'): ?>
                                                <button class="btn-action btn-outline-action btn-reschedule"
                                                    data-id="<?php echo $b['id']; ?>"
                                                    data-venue="<?php echo htmlspecialchars($b['venue_name']); ?>"
                                                    data-type="<?php echo htmlspecialchars($actual_room_type); ?>"
                                                    data-start="<?php echo $b['start_date']; ?>"
                                                    data-end="<?php echo $b['end_date']; ?>"
                                                    data-date="<?php echo $date_str; ?>">Reschedule</button>
                                                <?php endif; ?>

                                                <button class="btn-action btn-danger-outline btn-cancel"
                                                    data-id="<?php echo $b['id']; ?>"
                                                    data-venue="<?php echo htmlspecialchars($b['venue_name']); ?>"
                                                    data-date="<?php echo $date_str; ?>"
                                                    data-paid="<?php echo $amount_paid; ?>">
                                                    <?php echo ($amount_paid > 0) ? 'Refund' : 'Cancel'; ?>
                                                </button>
                                                <?php endif; ?>

                                                <!-- Dynamic View Details Button -->
                                                <button class="btn-action btn-outline-action btn-details"
                                                    data-id="<?php echo $b['id']; ?>"
                                                    data-venue="<?php echo htmlspecialchars($b['venue_name']); ?>"
                                                    data-date="<?php echo $date_str; ?>"
                                                    data-paid="<?php echo $amount_paid; ?>"
                                                    data-status="<?php echo $status_text; ?>"
                                                    data-tid="<?php echo !empty($b['transaction_id']) ? htmlspecialchars($b['transaction_id']) : 'N/A'; ?>"
                                                    title="View Booking Invoice">
                                                    <i class="fa-solid fa-file-invoice"></i>
                                                    <span class="vd-text">View Details</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($booking_pages > 1): ?>
                        <nav class="booking-pagination" aria-label="Booking history pages">
                            <span class="pagination-summary">
                                Showing <?php echo (($booking_page - 1) * $booking_limit) + 1; ?>-<?php echo min($booking_page * $booking_limit, $stat_total); ?> of <?php echo $stat_total; ?> bookings
                            </span>
                            <div class="pagination-controls">
                                <?php if ($booking_page > 1): ?>
                                <a class="pagination-link" href="user_dashboard.php?section=bookings&amp;booking_page=<?php echo $booking_page - 1; ?>" aria-label="Previous page">&larr; Previous</a>
                                <?php endif; ?>
                                <?php for ($page_number = 1; $page_number <= $booking_pages; $page_number++): ?>
                                <a class="pagination-link <?php echo $page_number === $booking_page ? 'active' : ''; ?>" href="user_dashboard.php?section=bookings&amp;booking_page=<?php echo $page_number; ?>" aria-current="<?php echo $page_number === $booking_page ? 'page' : 'false'; ?>"><?php echo $page_number; ?></a>
                                <?php endfor; ?>
                                <?php if ($booking_page < $booking_pages): ?>
                                <a class="pagination-link" href="user_dashboard.php?section=bookings&amp;booking_page=<?php echo $booking_page + 1; ?>" aria-label="Next page">Next &rarr;</a>
                                <?php endif; ?>
                            </div>
                        </nav>
                        <?php endif; ?>
                    </div>
                    <p class="footer-note">Status Pending means payment has not been confirmed yet. Use 'Pay Now' to complete.</p>
                </div>

                <!-- ================= TAB: SETTINGS ================= -->
                <div id="tab-settings" class="tab-pane <?php echo $initial_section === 'settings' ? 'active' : ''; ?>">
                    <div class="content-header">
                        <div class="header-titles">
                            <h1 class="page-title">ACCOUNT SETTINGS</h1>
                            <p class="page-subtitle">MANAGE YOUR PROFILE AND PREFERENCES</p>
                        </div>
                    </div>

                    <div class="settings-container">
                        <!-- Profile Form -->
                        <div class="settings-card">
                            <h3 class="settings-title">Personal Information</h3>
                            <form class="settings-form">
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label>First Name</label>
                                        <input type="text" id="set-fname" class="form-control"
                                            value="<?php echo htmlspecialchars($customer['first_name']); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Last Name</label>
                                        <input type="text" id="set-lname" class="form-control"
                                            value="<?php echo htmlspecialchars($customer['last_name']); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Email Address</label>
                                        <input type="email" class="form-control"
                                            value="<?php echo htmlspecialchars($customer['email']); ?>" readonly
                                            disabled>
                                    </div>
                                    <div class="form-group">
                                        <label>Phone Number</label>
                                        <input type="tel" id="set-phone" class="form-control"
                                            value="<?php echo htmlspecialchars($customer['phone']); ?>">
                                    </div>
                                </div>
                                <button type="button" id="btn-save-profile" class="btn btn-save">Save Profile</button>
                            </form>
                        </div>

                        <!-- Preferences -->
                        <div class="settings-card">
                            <h3 class="settings-title">Guest Preferences</h3>
                            <form class="settings-form">
                                <div class="form-group full-width">
                                    <label>Dietary Requirements / Special Requests</label>
                                    <textarea id="set-prefs" class="form-control"
                                        rows="3"><?php echo isset($customer['special_req']) ? htmlspecialchars($customer['special_req']) : ''; ?></textarea>
                                </div>
                                <button type="button" id="btn-save-prefs" class="btn btn-save">Save Preferences</button>
                            </form>
                        </div>

                        <!-- Security -->
                        <div class="settings-card">
                            <h3 class="settings-title">Security</h3>
                            <form class="settings-form">
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label>Current Password</label>
                                        <input type="password" id="set-old-pass" class="form-control"
                                            placeholder="••••••••">
                                    </div>
                                    <div class="form-group">
                                        <label>New Password</label>
                                        <input type="password" id="set-new-pass" class="form-control"
                                            placeholder="Enter new password" minlength="8">
                                    </div>
                                    <div class="form-group">
                                        <label>Confirm New Password</label>
                                        <input type="password" id="set-confirm-pass" class="form-control"
                                            placeholder="Re-enter new password" minlength="8">
                                    </div>
                                </div>
                                <button type="button" id="btn-update-password" class="btn btn-outline-dark">Update
                                    Password</button>
                            </form>
                        </div>
                    </div>
                </div>

            </div>
        </main>
    </div>

    <!-- ================= MODALS ================= -->

    <!-- Cancel Modal -->
    <div class="modal-overlay" id="modal-cancel" role="dialog" aria-modal="true" aria-labelledby="cancel-modal-title">
        <div class="modal-box cancel-modal-box">
            <h2 class="cancel-modal-title" id="cancel-modal-title">Cancel Reservation?</h2>

            <h3 class="cancel-modal-subtitle">Booking Summary</h3>

            <div class="cancel-summary-grid">
                <span class="cancel-label">Customer Name:</span>
                <span
                    class="cancel-value"><?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?></span>

                <span class="cancel-label">Venue Type:</span>
                <span class="cancel-value" id="cancel-venue">--</span>

                <span class="cancel-label">Date:</span>
                <span class="cancel-value" id="cancel-date">--</span>
            </div>

            <!-- Refund Info (Shows only if paid) -->
            <div id="cancel-refund-info-wrapper" class="hidden-element">
                <div class="cancel-summary-grid">
                    <span class="cancel-label">Total Paid by Guest:</span>
                    <span class="cancel-value" id="cancel-paid">₱0</span>

                    <span class="cancel-label tooltip-wrapper">
                        <div class="tooltip-container">
                            <i class="fa-regular fa-circle-question"></i>
                            <div class="tooltip-text">A payment-processing fee is deducted from the total amount paid before calculating your refund. The percentage and refund amount shown here are snapshotted for this request.</div>
                        </div>
                        Payment-processing Fee:
                    </span>
                    <span class="cancel-value" id="cancel-fee-label">0%</span>
                </div>
            </div>

            <!-- Unpaid Note (Shows only if unpaid) -->
            <div id="cancel-unpaid-info" class="unpaid-banner">
                <p class="unpaid-banner-text">
                    <i class="fa-solid fa-check-circle"></i> No payments have been made. You will not be charged.
                </p>
            </div>

            <!-- FIXED Reason Input -->
            <div class="cancel-reason-block">
                <span class="cancel-label font-weight-600">Reason:</span>
                <textarea class="cancel-textarea" rows="3"
                    placeholder="Please tell us why you are cancelling..."></textarea>
            </div>

            <!-- Bottom Refund Section (Shows only if paid) -->
            <div id="cancel-refund-bottom" class="hidden-element">
                <div class="cancel-summary-grid refund-total-grid">
                    <span class="cancel-label text-refund-label">Refund
                        Amount:</span>
                    <span class="cancel-value text-refund-val" id="cancel-refund-total">₱0</span>
                </div>

                <div class="cancel-checkbox-group-ui">
                    <input type="checkbox" id="confirm-fee">
                    <label for="confirm-fee">
                        <span class="check-title">I understand the payment-processing fee and refund amount shown above.</span>
                        <span class="check-desc">Note: Refunds may take 5-10 business days to reflect in your account
                            depending on your provider.</span>
                    </label>
                </div>
            </div>

            <div class="cancel-modal-actions">
                <button class="btn-cancel-back close-modal">Go back</button>
                <button class="btn-cancel-confirm btn-confirm-red">Confirm Cancellation</button>
            </div>
        </div>
    </div>

    <!-- Reschedule Modal (Upgraded to Luxury Style) -->
    <div class="modal-overlay" id="modal-reschedule" role="dialog" aria-modal="true" aria-labelledby="reschedule-modal-title">
        <div class="modal-box cancel-modal-box modal-box-scroll-90">
            <h2 class="cancel-modal-title" id="reschedule-modal-title">Reschedule Request</h2>

            <div class="cancel-summary-grid">
                <span class="cancel-label">Venue:</span> <span class="cancel-value" id="reschedule-venue">--</span>
                <span class="cancel-label">Original Date:</span> <span class="cancel-value"
                    id="reschedule-date">--</span>
            </div>

            <div class="margin-top-15">
                <label
                    class="resched-label">Select
                    New Dates:</label>
                <?php $calendarId = 'cal-ui-user-resched'; include 'includes/partials/booking_calendar.php'; ?>
            </div>

            <!-- FIXED Reason Input -->
            <div class="cancel-reason-block">
                <span class="cancel-label font-weight-600">Reason:</span>
                <textarea id="reschedule-reason" class="cancel-textarea" rows="2"
                    placeholder="Why do you need to change dates?"></textarea>
            </div>

            <!-- RESTORED CHECKBOX -->
            <div class="cancel-checkbox-group-ui margin-bottom-25">
                <input type="checkbox" id="confirm-reschedule">
                <label for="confirm-reschedule">
                    <span class="check-title">I understand that my reschedule request is subject to availability and
                        requires staff approval.</span>
                    <span class="check-desc">Submitting this request does not guarantee an automatic change.</span>
                </label>
            </div>

            <div class="cancel-modal-actions">
                <button class="btn-cancel-back close-modal">Go back</button>
                <button class="btn-cancel-confirm btn-confirm-red" id="btn-submit-resched">Submit Request</button>
            </div>
        </div>
    </div>

    <!-- Booking Details Modal -->
    <div class="modal-overlay" id="modal-details" role="dialog" aria-modal="true" aria-labelledby="ud-title">
        <div class="modal-box modal-details-scroll">
            <h2 class="modal-title" id="ud-title">Booking Details</h2>
            <p class="details-status">Status: <span id="ud-status-badge" class="badge">--</span></p>

            <div class="modal-summary details-summary">
                <p><span>Customer:</span> <span id="ud-customer-name">--</span></p>
                <p><span>Venue:</span> <span id="ud-venue">--</span></p>
                <p><span>Dates:</span> <span id="ud-dates">--</span></p>
                <p><span>Guests:</span> <span id="ud-guests">--</span></p>

                <div id="ud-specific-row" class="hidden-element">
                    <span id="ud-specific-label">Event Details:</span>
                    <span id="ud-specific-value">--</span>
                </div>

                <div id="ud-cancel-row" class="cancel-reason-box">
                    <span class="cancel-reason-title">Cancellation Reason:</span>
                    <span id="ud-cancel-reason">--</span>
                </div>

                <!-- Itemized Cost Breakdown -->
                <div class="details-breakdown-section">
                    <strong class="details-section-title">Cost Breakdown</strong>
                    
                    <p><span>Venue Base Rate:</span> <span id="ud-base-amt">₱0.00</span></p>
                    
                    <div id="ud-extrapax-container" class="hidden-element">
                        <p><span>Extra Pax Charge:</span> <span id="ud-extrapax-amt">₱0.00</span></p>
                    </div>

                    <div id="ud-addons-container" class="hidden-element">
                        <strong class="details-subhead">Add-ons & Options:</strong>
                        <div id="ud-addons-list"></div>
                    </div>

                    <div id="ud-line-items-container" class="hidden-element">
                        <strong class="details-subhead">Custom Line Items:</strong>
                        <div id="ud-line-items-list"></div>
                    </div>

                    <div class="details-subtotal-box">
                        <p><span>Subtotal:</span> <span id="ud-subtotal-amt">₱0.00</span></p>
                        <p class="details-total-row">
                            <span>Total Amount:</span> <span id="ud-total-amt">₱0.00</span>
                        </p>
                    </div>
                </div>

                <!-- Payment Details -->
                <div class="details-breakdown-section">
                    <strong class="details-section-title">Payment Details</strong>
                    <p><span>Payment Scheme:</span> <span id="ud-scheme">--</span></p>
                    <p><span>Amount Paid:</span> <span id="ud-paid-amt" class="text-paid-green">₱0.00</span></p>
                    <p><span>Remaining Balance:</span> <span id="ud-balance-amt" class="text-balance-red">₱0.00</span></p>
                    <p><span>Transaction ID(s):</span> <span id="ud-tid" class="text-mono-tid">--</span></p>
                </div>
            </div>

            <div class="modal-actions center-actions details-modal-actions">
                <button class="btn-modal btn-go-back close-modal btn-modal-150">Close</button>
                <button class="btn-modal btn-confirm btn-modal-print-150" id="btn-print-receipt" aria-label="Open PDF receipt"><i class="fa-solid fa-file-pdf"></i> Open PDF Receipt</button>
            </div>
        </div>
    </div>

    <!-- Alert Modal -->
    <div class="modal-overlay alert-modal-overlay" id="uniAlertModal" role="dialog" aria-modal="true" aria-labelledby="ua-title" aria-describedby="ua-message">
        <div class="modal-box alert-modal-box">
            <i id="ua-icon" class="fa-solid fa-circle-info modal-icon-warning alert-icon-gold"></i>
            <h3 class="modal-title" id="ua-title">Notice</h3>
            <p id="ua-message" class="alert-msg-text">Message
                goes here.</p>
            <div class="alert-actions-flex">
                <button class="btn-modal btn-confirm-red btn-alert-ok" id="ua-btn-ok">OK</button>
            </div>
        </div>
    </div>

    <script src="assets/js/calendar.js?v=<?= time() ?>"></script>
    
    <!-- Flatpickr Core -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://npmcdn.com/flatpickr/dist/l10n/vn.js"></script>
    
    <!-- Global Custom Modals -->
    <script src="assets/js/global_modals.js?v=<?= time() ?>"></script>

    <!-- Specific User Dashboard JS -->
    <script src="assets/js/user_dashboard.js?v=<?= time() ?>"></script>

    <?php if (isset($_GET['payment']) && in_array($_GET['payment'], ['success', 'failed'], true)): ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const paymentResult = <?php echo json_encode($_GET['payment']); ?>;
            if (paymentResult === 'success') {
                const syncButton = document.querySelector('.btn-sync-payment[data-id="<?php echo (int)($payment_sync_booking_id ?? 0); ?>"]');
                if (syncButton) syncButton.click();
                showAlert('Payment Submitted', 'Refreshing payment status from the payment provider.', 'success');
            } else {
                showAlert('Payment Not Completed', 'No payment was completed. Your booking remains available for payment from the dashboard.', 'error');
            }
            if (window.history?.replaceState) window.history.replaceState({}, document.title, window.location.pathname);
        });
    </script>
    <?php endif; ?>

</body>

</html>
