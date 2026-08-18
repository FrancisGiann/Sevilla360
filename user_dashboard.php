<?php
$required_role = 'customer';
require 'includes/auth_guard.php';
require_once 'config/db_connect.php';

// =========================================================================
// DATABASE HYGIENE: Auto-Cancel Expired Unpaid Bookings
// =========================================================================
$cleanup_stmt = $conn->prepare("
    UPDATE bookings b
    JOIN venues v ON b.venue_id = v.id
    SET b.booking_status = 'Cancelled', b.updated_at = NOW() 
    WHERE b.booking_status = 'Pending' 
    AND b.payment_status = 'Unpaid'
    AND v.category != 'Event Hall'
    AND b.created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
");
$cleanup_stmt->execute();
$cleanup_stmt->close();
// =========================================================================

// 1. Get the Customer ID associated with this User Account
$user_id = $_SESSION['user_id'];

$stmt_cust = $conn->prepare("SELECT id, first_name, last_name, email, phone, special_req, dob FROM customers WHERE user_id = ?");
$stmt_cust->bind_param("i", $user_id);
$stmt_cust->execute();
$customer_res = $stmt_cust->get_result();

if ($customer_res->num_rows === 0) {
    die("Customer profile not found. Please contact support.");
}
$customer = $customer_res->fetch_assoc();
$customer_id = $customer['id'];

// 2. Fetch all bookings
$stmt_bookings = $conn->prepare("
    SELECT 
        b.*, 
        v.name AS venue_name, 
        v.category AS venue_type,
        hr.room_type AS hotel_room_type,
        cx.status AS cancel_status,
        rr.status AS resched_status,
        p.transaction_id
    FROM bookings b
    JOIN venues v ON b.venue_id = v.id
    LEFT JOIN hotel_rooms hr ON v.id = hr.venue_id
    LEFT JOIN cancellations cx ON b.id = cx.booking_id
    LEFT JOIN reschedule_requests rr ON b.id = rr.booking_id AND rr.status = 'Pending'
    LEFT JOIN payments p ON b.id = p.booking_id
    WHERE b.customer_id = ?
    GROUP BY b.id
    ORDER BY b.id DESC
");
$stmt_bookings->bind_param("i", $customer_id);
$stmt_bookings->execute();
$bookings_result = $stmt_bookings->get_result();

$bookings = [];
$stat_total = 0;
$stat_pending = 0;
$stat_confirmed = 0;

while ($row = $bookings_result->fetch_assoc()) {
    $bookings[] = $row;
    $stat_total++;
    if ($row['booking_status'] === 'Pending') $stat_pending++;
    if ($row['booking_status'] === 'Confirmed') $stat_confirmed++;
}

// 3. Fetch Notifications
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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
</head>

<body class="dashboard-body">
    <div class="dashboard-layout">
        <!-- Sidebar Backdrop Overlay for Mobile -->
        <div id="sidebar-overlay" class="sidebar-overlay"></div>

        <!-- LEFT SIDEBAR -->
        <aside class="dashboard-sidebar">
            <div class="sidebar-header">
                <a href="index.php" class="brand-logo">SEVILLA360</a>
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
                    <li class="nav-item active" data-tab="bookings">
                        <a href="#" class="nav-link"><i class="fa-solid fa-bars"></i><span>My Bookings</span></a>
                    </li>
                    <li class="nav-item" data-tab="settings">
                        <a href="#" class="nav-link"><i class="fa-regular fa-user"></i><span>Settings</span></a>
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
                        <button id="btn-notifications">
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

                    <a href="index.php" class="btn-topbar"><i class="fa-solid fa-house"></i> Back to Home</a>
                </div>
            </header>

            <div class="dashboard-content">

                <!-- ================= TAB: MY BOOKINGS ================= -->
                <div id="tab-bookings" class="tab-pane active">
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
                            <div class="filter-pills" id="statusFilters">
                                <button class="filter-pill active" data-filter="All">All</button>
                                <button class="filter-pill" data-filter="Pending">Pending</button>
                                <button class="filter-pill" data-filter="Partially Paid">Partially Paid</button>
                                <button class="filter-pill" data-filter="Paid">Paid</button>
                                <button class="filter-pill" data-filter="Cancelled">Cancelled</button>
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

                                        $total_amt = floatval($b['total_amount']);
                                        $amount_paid = floatval($b['amount_paid']);
                                        $actual_room_type = ($b['venue_type'] === 'Hotel Room') ? $b['hotel_room_type'] : $b['venue_type'];
                                        $is_pending_inquiry = ($b['venue_type'] === 'Event Hall' && $b['booking_status'] === 'Pending');

                                        $display_amount = '₱' . number_format($total_amt, 2);
                                        if ($is_pending_inquiry) {
                                            $display_amount = '<span class="text-tba">To Be Arranged</span>';
                                        }

                                        // Status badge logic
                                        $badge_class = 'badge-pending'; 
                                        $status_text = 'Pending Payment';
                                        $filter_data = 'Pending';

                                        if ($is_pending_inquiry) {
                                            $status_text = 'Inquiry Sent';
                                        } elseif ($b['booking_status'] === 'Confirmed') {
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
                                        } elseif ($b['booking_status'] === 'Cancelled') {
                                            $badge_class = 'badge-cancelled';
                                            $status_text = 'Cancelled';
                                            $filter_data = 'Cancelled';
                                        }

                                        // OVERRIDE TEXT IF A REQUEST IS PENDING
                                        if ($b['cancel_status'] === 'Pending') {
                                            $status_text = 'Cancel Requested';
                                            $badge_class = 'badge-cancelled'; 
                                        } elseif ($b['resched_status'] === 'Pending') {
                                            $status_text = 'Resched Requested';
                                            $badge_class = 'badge-reschedule';  
                                        }

                                        $display_id = !empty($b['reference_no']) ? htmlspecialchars($b['reference_no']) : '#' . $b['id'];
                                    ?>
                                    <tr data-status="<?php echo $filter_data; ?>">

                                        <td class="booking-ref-id">
                                            <?php echo $display_id; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($b['venue_name']); ?></td>
                                        <td><?php echo $date_str; ?></td>
                                        <td
                                            class="<?php echo ($b['booking_status'] === 'Cancelled') ? 'text-muted' : ''; ?>">
                                            <?php echo $display_amount; ?>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $badge_class; ?>">
                                                <?php echo ($b['cancel_status'] === 'Pending') ? 'Cancel Requested' : $status_text; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-cell">
                                                <?php if ($b['cancel_status'] !== 'Pending' && ($b['booking_status'] === 'Pending' || ($b['booking_status'] === 'Confirmed' && in_array($b['payment_status'], ['Unpaid', 'Partial'])))): ?>
                                                <?php if (!$is_pending_inquiry): ?>
                                                <button class="btn-action btn-pay btn-pay-now"
                                                    data-id="<?php echo $b['id']; ?>">Pay Now</button>
                                                <?php endif; ?>
                                                <?php endif; ?>

                                                <?php if ($b['booking_status'] !== 'Cancelled' && $b['cancel_status'] !== 'Pending'): ?>
                                                <?php if ($b['booking_status'] === 'Confirmed'): ?>
                                                <button class="btn-action btn-outline-action btn-reschedule"
                                                    data-id="<?php echo $b['id']; ?>"
                                                    data-venue="<?php echo htmlspecialchars($b['venue_name']); ?>"
                                                    data-type="<?php echo htmlspecialchars($actual_room_type); ?>"
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
                    </div>
                    <p class="footer-note">Status Pending means payment has not been confirmed yet. Use 'Pay Now' to
                        complete.</p>
                </div>

                <!-- ================= TAB: SETTINGS ================= -->
                <div id="tab-settings" class="tab-pane">
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
                                    <div class="form-group full-width">
                                        <label>Date of Birth</label>
                                        <input type="date" id="set-dob" class="form-control dob-input-width"
                                            value="<?php echo isset($customer['dob']) ? $customer['dob'] : ''; ?>">
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
                                            placeholder="Enter new password">
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
    <div class="modal-overlay" id="modal-cancel">
        <div class="modal-box cancel-modal-box">
            <h2 class="cancel-modal-title">Cancel Reservation?</h2>

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
                            <div class="tooltip-text">As per our Terms and Conditions, the transaction fee processed by
                                our payment gateway (PayMongo) is non-refundable. This fee is deducted from the total
                                amount to cover the cost of the initial digital transaction.</div>
                        </div>
                        Non-refundable Service Fee:
                    </span>
                    <span class="cancel-value">₱461</span>
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
                        <span class="check-title">I understand that the service fee is non-refundable</span>
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
    <div class="modal-overlay" id="modal-reschedule">
        <div class="modal-box cancel-modal-box modal-box-scroll-90">
            <h2 class="cancel-modal-title">Reschedule Request</h2>

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
    <div class="modal-overlay" id="modal-details">
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
                <button class="btn-modal btn-confirm btn-modal-print-150" id="btn-print-receipt"><i class="fa-solid fa-print"></i> Print Receipt</button>
            </div>
        </div>
    </div>

    <!-- Alert Modal -->
    <div class="modal-overlay alert-modal-overlay" id="uniAlertModal">
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

    <?php if (!empty($latest_unread)): ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            setTimeout(() => {
                if (typeof playNotificationChime === 'function') {
                    playNotificationChime();
                }
                showAlert(
                    "<?php echo addslashes($latest_unread['title']); ?>",
                    "<?php echo addslashes($latest_unread['message']); ?>",
                    "info"
                );
                // Mark this auto-popped notification as read so it doesn't repeatedly auto-popup on future refreshes
                fetch("actions/user/mark_notifications_read.php?id=<?php echo (int)$latest_unread['id']; ?>");
            }, 500);
        });
    </script>
    <?php endif; ?>
</body>

</html>