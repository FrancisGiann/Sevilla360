<?php
$required_role = 'customer';
require 'includes/auth_guard.php';
require_once 'config/db_connect.php';

// 1. Get the Customer ID associated with this User Account
$user_id = $_SESSION['user_id'];
$stmt_cust = $conn->prepare("SELECT id, first_name, last_name, email, phone FROM customers WHERE user_id = ?");
$stmt_cust->bind_param("i", $user_id);
$stmt_cust->execute();
$customer_res = $stmt_cust->get_result();

if ($customer_res->num_rows === 0) {
    die("Customer profile not found. Please contact support.");
}
$customer = $customer_res->fetch_assoc();
$customer_id = $customer['id'];

// 2. Fetch all bookings for THIS customer PLUS their transaction ID
$stmt_bookings = $conn->prepare("
    SELECT 
        b.*, 
        v.name AS venue_name, 
        v.category AS venue_type,
        cx.status AS cancel_status,
        p.transaction_id
    FROM bookings b
    JOIN venues v ON b.venue_id = v.id
    LEFT JOIN cancellations cx ON b.id = cx.booking_id
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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | SEVILLA360</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Playfair+Display:ital,wght@0,400;0,500;0,600;1,400&family=Great+Vibes&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/user_dashboard.css">
</head>

<body class="dashboard-body">
    <div class="dashboard-layout">

        <!-- LEFT SIDEBAR -->
        <aside class="dashboard-sidebar">
            <div class="sidebar-header">
                <a href="index.php" class="brand-logo">SEVILLA360</a>
            </div>

            <div class="user-profile">
                <!-- Auto-generate Avatar Initials -->
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

            <!-- Topbar -->
            <header class="dashboard-topbar">
                <div class="topbar-right">
                    <a href="index.php" class="btn-topbar"><i class="fa-solid fa-house"></i> Back to Home</a>
                    <button class="icon-btn" aria-label="Notifications"><i class="fa-regular fa-bell"></i></button>
                    <button class="icon-btn" aria-label="Profile"><i class="fa-regular fa-circle-user"></i></button>
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
                            <a href="booking.php" style="text-decoration:none;"><button class="btn-primary-dash"><i
                                        class="fa-solid fa-plus"></i> New Booking</button></a>
                        </div>
                    </div>

                    <!-- Dynamic Stats Grid -->
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
                                        <td colspan="6" style="text-align:center; padding:30px;">You have no bookings
                                            yet. Time to plan a vacation!</td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($bookings as $b): 
                                        // Date Formatting
                                        $start = new DateTime($b['start_date']);
                                        $end = new DateTime($b['end_date']);
                                        $date_str = ($b['start_date'] === $b['end_date']) ? $start->format('M j, Y') : $start->format('M j') . ' - ' . $end->format('M j, Y');

                                        // Money
                                        $total_amt = floatval($b['total_amount']);
                                        $amount_paid = floatval($b['amount_paid']);

                                        // Badge & Filtering Logic
                                        $badge_class = 'badge-pending'; 
                                        $status_text = 'Pending Payment';
                                        $filter_data = 'Pending';

                                        if ($b['booking_status'] === 'Confirmed') {
                                            if ($b['payment_status'] === 'Partial') {
                                                $badge_class = 'badge-partial';
                                                $status_text = 'Partially Paid';
                                                $filter_data = 'Partially Paid';
                                            } else {
                                                $badge_class = 'badge-paid';
                                                $status_text = 'Fully Paid';
                                                $filter_data = 'Paid';
                                            }
                                        } elseif ($b['booking_status'] === 'Cancelled') {
                                            $badge_class = 'badge-cancelled';
                                            $status_text = 'Cancelled';
                                            $filter_data = 'Cancelled';
                                        }
                                    ?>
                                    <tr data-status="<?php echo $filter_data; ?>">
                                        <td class="fw-500">#<?php echo $b['id']; ?></td>
                                        <td><?php echo htmlspecialchars($b['venue_name']); ?></td>
                                        <td><?php echo $date_str; ?></td>
                                        <td
                                            class="<?php echo ($b['booking_status'] === 'Cancelled') ? 'text-muted' : ''; ?>">
                                            ₱<?php echo number_format($total_amt, 2); ?>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $badge_class; ?>">
                                                <?php echo ($b['cancel_status'] === 'Pending') ? 'Cancel Requested' : $status_text; ?>
                                            </span>
                                        </td>
                                        <td class="action-cell">

                                            <?php if ($b['booking_status'] === 'Pending' || ($b['booking_status'] === 'Confirmed' && $b['payment_status'] === 'Partial')): ?>
                                            <button class="btn-action btn-green">Pay Now</button>
                                            <?php endif; ?>

                                            <?php if ($b['booking_status'] !== 'Cancelled' && $b['cancel_status'] !== 'Pending'): ?>
                                            <!-- Cancel Button -->
                                            <button class="btn-action btn-red btn-cancel"
                                                data-id="<?php echo $b['id']; ?>"
                                                data-venue="<?php echo htmlspecialchars($b['venue_name']); ?>"
                                                data-date="<?php echo $date_str; ?>"
                                                data-paid="<?php echo $amount_paid; ?>">
                                                <?php echo ($amount_paid > 0) ? 'Refund' : 'Cancel'; ?>
                                            </button>
                                            <?php endif; ?>

                                            <button class="btn-action btn-outline btn-details"
                                                data-id="<?php echo $b['id']; ?>"
                                                data-venue="<?php echo htmlspecialchars($b['venue_name']); ?>"
                                                data-date="<?php echo $date_str; ?>"
                                                data-paid="<?php echo $amount_paid; ?>"
                                                data-status="<?php echo $status_text; ?>"
                                                data-tid="<?php echo !empty($b['transaction_id']) ? htmlspecialchars($b['transaction_id']) : 'N/A'; ?>">
                                                View Details
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <p class="footer-note">Status Pending means payment has not been confirmed yet. Use 'Pay Now' to
                    complete or 'Refresh Status' to sync with PayMongo.</p>
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
                                    <input type="text" class="form-control" value="Francis">
                                </div>
                                <div class="form-group">
                                    <label>Last Name</label>
                                    <input type="text" class="form-control" value="Empleo">
                                </div>
                                <div class="form-group">
                                    <label>Email Address</label>
                                    <input type="email" class="form-control" value="user@email.com" readonly>
                                </div>
                                <div class="form-group">
                                    <label>Phone Number</label>
                                    <input type="tel" class="form-control" value="+63 912 345 6789">
                                </div>
                            </div>
                            <button type="button" class="btn btn-save">Save Profile</button>
                        </form>
                    </div>

                    <!-- Preferences -->
                    <div class="settings-card">
                        <h3 class="settings-title">Guest Preferences</h3>
                        <form class="settings-form">
                            <div class="form-group full-width">
                                <label>Dietary Requirements / Allergies</label>
                                <textarea class="form-control" rows="2"
                                    placeholder="e.g., Vegetarian, Peanut Allergy..."></textarea>
                            </div>
                            <div class="form-group full-width">
                                <label>Special Requests for Future Stays</label>
                                <textarea class="form-control" rows="2"
                                    placeholder="e.g., Extra pillows, Ground floor preferred..."></textarea>
                            </div>
                            <button type="button" class="btn btn-save">Save Preferences</button>
                        </form>
                    </div>

                    <!-- Security -->
                    <div class="settings-card">
                        <h3 class="settings-title">Security</h3>
                        <form class="settings-form">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Current Password</label>
                                    <input type="password" class="form-control" placeholder="••••••••">
                                </div>
                                <div class="form-group">
                                    <label>New Password</label>
                                    <input type="password" class="form-control" placeholder="Enter new password">
                                </div>
                            </div>
                            <button type="button" class="btn btn-outline-dark">Update Password</button>
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
        <div class="modal-box">
            <h2 class="modal-title">Cancel Reservation?</h2>

            <div class="modal-summary">
                <p><span>Customer Name:</span> Francis Empleo</p>
                <p><span>Venue Type:</span> <span id="cancel-venue">Event Hall</span></p>
                <p><span>Date:</span> <span id="cancel-date">March 30, 2026</span></p>

                <!-- Shown only if partially/fully paid -->
                <div id="cancel-refund-info" style="display: none;">
                    <p><span>Total Paid by Guest:</span> <span id="cancel-paid">₱20,000</span></p>
                    <p class="fee-note"><i class="fa-solid fa-circle-info"></i> Non-refundable Service Fee: ₱461</p>
                    <p class="refund-amount"><span>Refund Amount:</span> <span id="cancel-refund-total">₱19,539</span>
                    </p>
                </div>
            </div>

            <div class="form-group">
                <label>Reason:</label>
                <textarea class="form-control" rows="3"
                    placeholder="Please tell us why you are cancelling..."></textarea>
            </div>

            <div class="checkbox-group" id="cancel-checkbox-group" style="display: none;">
                <input type="checkbox" id="confirm-fee">
                <label for="confirm-fee">
                    I understand that the service fee is non-refundable.<br>
                    <small>Note: Refunds may take 5-10 business days to reflect in your account.</small>
                </label>
            </div>

            <div class="modal-actions">
                <button class="btn-modal btn-go-back close-modal">Go back</button>
                <button class="btn-modal btn-confirm-red">Confirm Cancellation</button>
            </div>
        </div>
    </div>

    <!-- Reschedule Modal -->
    <div class="modal-overlay" id="modal-reschedule">
        <div class="modal-box">
            <h2 class="modal-title">Reschedule Request</h2>

            <div class="modal-summary">
                <p><span>Customer Name:</span> Francis Empleo</p>
                <p><span>Venue Type:</span> <span id="reschedule-venue">Event Hall</span></p>
                <p><span>Original Date:</span> <span id="reschedule-date">March 30, 2026</span></p>
                <p class="new-date-row">
                    <span>New Date:</span>
                    <input type="date" class="form-control date-picker">
                </p>
            </div>

            <div class="form-group">
                <label>Reason:</label>
                <textarea class="form-control" rows="3"></textarea>
            </div>

            <div class="checkbox-group">
                <input type="checkbox" id="confirm-reschedule">
                <label for="confirm-reschedule">
                    I understand that my reschedule request is subject to availability and requires staff approval.<br>
                    <small>Submitting this request does not guarantee an automatic change.</small>
                </label>
            </div>

            <div class="modal-actions">
                <button class="btn-modal btn-go-back close-modal">Go back</button>
                <button class="btn-modal btn-confirm-red">Confirm Reschedule</button>
            </div>
        </div>
    </div>

    <!-- Booking Details Modal -->
    <div class="modal-overlay" id="modal-details">
        <div class="modal-box">
            <h2 class="modal-title">Booking Details</h2>
            <p class="details-status">Status: <span id="details-status-badge" class="text-green">Paid</span></p>

            <div class="modal-summary details-summary">
                <p><span>Customer Name:</span> Francis Empleo</p>
                <p><span>Venue Type:</span> <span id="details-venue">Resort Villa</span></p>
                <p><span>Date:</span> <span id="details-date">Apr 4-5, 2026</span></p>
                <p><span>Total Amount Paid:</span> <span id="details-paid">₱20,000</span></p>
                <p><span>Transaction ID:</span> <span id="details-tid">#1923129183</span></p>
            </div>

            <div class="modal-actions center-actions">
                <button class="btn-modal btn-go-back close-modal" style="width: 100%; max-width: 200px;">Close</button>
            </div>
        </div>
    </div>

    <script src="assets/js/user_dashboard.js?v=1.0"></script>
</body>

</html>