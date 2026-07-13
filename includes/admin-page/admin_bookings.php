<?php
// --- 1. DATABASE CONNECTION ---
require_once 'config/db_connect.php';

// --- 2. FETCH DATA ---
// Join bookings with customers and venues to get all necessary details
$query = "
    SELECT 
        b.id AS booking_id,
        b.reference_no,
        b.start_date,
        b.end_date,
        b.total_amount,
        b.booking_status,
        b.payment_status,
        c.first_name,
        c.last_name,
        v.name AS venue_name,
        v.category AS venue_category
    FROM bookings b
    JOIN customers c ON b.customer_id = c.id
    JOIN venues v ON b.venue_id = v.id
    ORDER BY b.created_at DESC
";

// Execute query using MySQLi
$result = $conn->query($query);
$bookings = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $bookings[] = $row;
    }
}

// --- 3. HELPER FUNCTIONS ---

/**
 * Formats the booking dates to match the UI (e.g. "May 5-6, 2026" or "Jun 13, 2026")
 */
function formatBookingDate($start, $end) {
    $s = strtotime($start);
    $e = strtotime($end);
    
    if ($s === $e) {
        return date('M j, Y', $s);
    }
    
    // If same month and year
    if (date('m Y', $s) === date('m Y', $e)) {
        return date('M j', $s) . '-' . date('j, Y', $e);
    }
    
    // If different months or years
    return date('M j, Y', $s) . ' - ' . date('M j, Y', $e);
}

/**
 * Maps database payment/booking status to UI CSS classes and labels
 */
function getStatusBadge($booking_status, $payment_status) {
    // Check for cancellations and refunds first
    if ($booking_status === 'Cancelled') {
        if ($payment_status === 'Refunded') {
            return ['class' => 'status-refunded', 'label' => 'Refunded'];
        } elseif ($payment_status === 'Paid' || $payment_status === 'Partial') {
            return ['class' => 'status-pending-refund', 'label' => 'Pending Refund'];
        }
        return ['class' => 'status-refunded', 'label' => 'Cancelled'];
    }

    // Default payment status logic
    switch ($payment_status) {
        case 'Unpaid':
            return ['class' => 'status-pending', 'label' => 'Pending Payment'];
        case 'Partial':
            return ['class' => 'status-partial', 'label' => 'Partial'];
        case 'Paid':
            return ['class' => 'status-paid', 'label' => 'Paid'];
        default:
            return ['class' => 'status-pending', 'label' => $payment_status];
    }
}
?>
<div class="admin-bookings-container">
    <p class="bookings-subtitle">MANAGE CUSTOMER RESERVATIONS</p>
    <!-- NEW CONSISTENT HEADER -->
    <div class="bookings-page-header">
        <!-- Search & Dropdowns -->
        <div class="top-controls">
            <div class="search-bar">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input type="text" placeholder="Search by name, id, or venue">
            </div>
            <select class="control-select">
                <option>All Venues</option>
                <option>Event Hall</option>
                <option>Standard Room</option>
                <option>Resort Villa</option>
            </select>
            <select class="control-select">
                <option>This Month</option>
                <option>Last Month</option>
                <option>This Year</option>
            </select>
        </div>
    </div>

    <!-- Table Card -->
    <div class="table-card">
        <h3 class="card-title">Booking History</h3>

        <!-- CONSISTENT GOLD TABS -->
        <div class="booking-tabs" id="bookingFilters">
            <button class="tab-btn active" data-filter="all">All</button>
            <button class="tab-btn" data-filter="pending">Pending</button>
            <button class="tab-btn" data-filter="paid">Paid</button>
            <button class="tab-btn" data-filter="cancelled">Cancelled</button>
        </div>

        <div class="table-responsive">
            <table class="bookings-table">
                <thead>
                    <tr>
                        <th>BOOKING ID</th>
                        <th>VENUE</th>
                        <th>CUSTOMER</th>
                        <th>DATE</th>
                        <th>AMOUNT</th>
                        <th>STATUS</th>
                        <th>ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($bookings) > 0): ?>
                    <?php foreach ($bookings as $row): ?>
                    <?php 
                                // Process Status Badge
                                $badge = getStatusBadge($row['booking_status'], $row['payment_status']);
                                
                                // Determine if row should be faded (Refunded / Cancelled)
                                $rowClass = ($badge['class'] === 'status-refunded') ? 'faded-row' : '';
                                $textClass = ($badge['class'] === 'status-refunded') ? 'faded-text' : '';
                                
                                // Format Customer Name
                                $customerName = htmlspecialchars($row['first_name'] . ' ' . $row['last_name']);
                                
                                // Format Venue Name (or Category)
                                $venueDisplay = htmlspecialchars($row['venue_category']); // Or use $row['venue_name']
                                
                                // Format Date
                                $formattedDate = formatBookingDate($row['start_date'], $row['end_date']);
                                
                                // Format Amount (Strips trailing .00 if whole number for clean UI, else leaves 2 decimals)
                                $amount = number_format($row['total_amount'], 2);
                                $amount = str_replace('.00', '', $amount); 
                            ?>
                    <tr class="<?= $rowClass ?>">
                        <td>#<?= htmlspecialchars($row['reference_no']) ?></td>
                        <td><?= $venueDisplay ?></td>
                        <td><?= $customerName ?></td>
                        <td><?= $formattedDate ?></td>
                        <td class="<?= $textClass ?>">P <?= $amount ?></td>
                        <td><span class="status-badge <?= $badge['class'] ?>"><?= $badge['label'] ?></span></td>
                        <td class="action-cells">
                            <!-- 
                                      Added data-id to buttons so they are ready for Phase 2 (AJAX) 
                                      We selectively show buttons based on status for better UI logic
                                    -->
                            <?php if ($row['booking_status'] === 'Pending'): ?>
                            <button class="btn-action btn-confirm" data-id="<?= $row['booking_id'] ?>">Confirm</button>
                            <button class="btn-action btn-cancel" data-id="<?= $row['booking_id'] ?>">Cancel</button>
                            <?php elseif ($row['payment_status'] === 'Paid' && $row['booking_status'] !== 'Cancelled'): ?>
                            <button class="btn-action btn-reschedule open-reschedule"
                                data-id="<?= $row['booking_id'] ?>">Reschedule</button>
                            <button class="btn-action btn-refund open-refund"
                                data-id="<?= $row['booking_id'] ?>">Refund</button>
                            <?php elseif ($row['booking_status'] === 'Confirmed' && $row['payment_status'] === 'Partial'): ?>
                            <button class="btn-action btn-confirm" data-id="<?= $row['booking_id'] ?>">Confirm</button>
                            <?php elseif ($badge['class'] === 'status-pending-refund'): ?>
                            <button class="btn-action btn-refund open-refund"
                                data-id="<?= $row['booking_id'] ?>">Refund</button>
                            <button class="btn-action btn-view" data-id="<?= $row['booking_id'] ?>">View
                                Details</button>
                            <?php else: ?>
                            <button class="btn-action btn-view" data-id="<?= $row['booking_id'] ?>">View
                                Details</button>
                            <?php endif; ?>

                            <button class="btn-icon" title="More Info" data-id="<?= $row['booking_id'] ?>">
                                <i class="fa-solid fa-circle-info"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 30px;">No bookings found.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modals Overlay -->
    <div class="modal-overlay" id="modalOverlay">

        <!-- Refund Modal (Kept Static for now) -->
        <div class="admin-modal" id="refundModal">
            <!-- ... Keep your existing Refund Modal HTML here ... -->
            <h3 class="modal-main-title">Process Refund</h3>
            <div class="modal-actions">
                <button class="btn-modal btn-modal-cancel close-modal">Cancel</button>
                <button class="btn-modal btn-modal-refund">Refund</button>
            </div>
        </div>

        <!-- Reschedule Modal (Kept Static for now) -->
        <div class="admin-modal" id="rescheduleModal">
            <!-- ... Keep your existing Reschedule Modal HTML here ... -->
            <h3 class="modal-main-title text-center">Reschedule Booking</h3>
            <div class="modal-actions">
                <button class="btn-modal btn-modal-cancel close-modal">Cancel</button>
                <button class="btn-modal btn-modal-refund">Reschedule</button>
            </div>
        </div>

    </div>
</div>