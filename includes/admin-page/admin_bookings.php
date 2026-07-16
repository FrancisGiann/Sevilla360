<?php
require_once 'config/db_connect.php';

// 1. Fetch Bookings + Cancel Status + Hotel Room Types
$query = "
    SELECT 
        b.id, 
        b.start_date, 
        b.end_date, 
        b.total_amount, 
        b.amount_paid,
        b.booking_status, 
        b.payment_status,
        c.first_name, 
        c.last_name, 
        v.name AS venue_name, 
        v.category AS venue_category,
        hr.room_type AS hotel_room_type,
        cx.status AS cancel_status,
        cx.reason AS cancel_reason
    FROM bookings b
    JOIN customers c ON b.customer_id = c.id
    JOIN venues v ON b.venue_id = v.id
    LEFT JOIN cancellations cx ON b.id = cx.booking_id
    LEFT JOIN hotel_rooms hr ON v.id = hr.venue_id
    ORDER BY b.id DESC
";
$result = $conn->query($query);
$bookings = [];
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $bookings[] = $row;
    }
}
?>

<div class="admin-bookings-container">
    <p class="bookings-subtitle">MANAGE CUSTOMER RESERVATIONS</p>

    <!-- Header -->
    <div class="bookings-page-header">
        <div class="top-controls">
            <div class="search-bar">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input type="text" placeholder="Search by name, id, or venue">
            </div>
            <select class="control-select">
                <option>All Venues</option>
                <option>Event Hall</option>
                <option>Hotel Room</option>
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

        <div class="booking-tabs" id="bookingFilters">
            <button class="tab-btn active" data-filter="all">All</button>
            <button class="tab-btn" data-filter="pending">Pending</button>
            <button class="tab-btn" data-filter="confirmed">Confirmed</button>
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
                    <?php if (empty($bookings)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 30px;">No bookings found.</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($bookings as $b): 
            
            // Date Formatting
            $start = new DateTime($b['start_date']);
            $end = new DateTime($b['end_date']);
            $date_str = ($b['start_date'] === $b['end_date']) ? $start->format('M j, Y') : $start->format('M j') . ' - ' . $end->format('M j, Y');

            $customer_name = htmlspecialchars($b['first_name'] . ' ' . $b['last_name']);
            $venue_name = htmlspecialchars($b['venue_name']);
            
            // SMART FIX: Get exact room type (Deluxe Room) instead of generic "Hotel Room"
            $actual_room_type = ($b['venue_category'] === 'Hotel Room') ? $b['hotel_room_type'] : $b['venue_category'];
            
            $total_amt = floatval($b['total_amount']);
            $amount_paid = isset($b['amount_paid']) ? floatval($b['amount_paid']) : 0;
            $balance_due = $total_amt - $amount_paid;

            // Strict Status Logic
            $badge_class = 'status-pending'; 
            $status_text = 'Pending';

            if ($b['booking_status'] === 'Confirmed') {
                if ($b['payment_status'] === 'Partial') {
                    $badge_class = 'status-partial';
                    $status_text = 'Partially Paid';
                } else {
                    $badge_class = 'status-paid';
                    $status_text = 'Fully Paid';
                }
            } elseif ($b['booking_status'] === 'Cancelled') {
                $badge_class = 'status-refunded';
                $status_text = 'Cancelled';
            }
        ?>
                    <tr class="<?php echo ($b['booking_status'] === 'Cancelled') ? 'faded-row' : ''; ?>">
                        <td>#<?php echo $b['id']; ?></td>
                        <td><?php echo $venue_name; ?></td>
                        <td><?php echo $customer_name; ?></td>
                        <td><?php echo $date_str; ?></td>
                        <td class="<?php echo ($b['booking_status'] === 'Cancelled') ? 'faded-text' : ''; ?>">
                            ₱<?php echo number_format($total_amt, 2); ?>
                        </td>
                        <td><span class="status-badge <?php echo $badge_class; ?>"><?php echo $status_text; ?></span>
                        </td>

                        <td class="action-cells">

                            <!-- 1. PENDING BOOKINGS -->
                            <?php if ($b['booking_status'] === 'Pending'): ?>
                            <button class="btn-action btn-confirm open-approve"
                                data-id="<?php echo $b['id']; ?>">Approve</button>
                            <button class="btn-action btn-confirm open-payment" data-id="<?php echo $b['id']; ?>"
                                data-due="<?php echo $balance_due; ?>">
                                Collect Pay
                            </button>
                            <button class="btn-action btn-cancel open-decline"
                                data-id="<?php echo $b['id']; ?>">Decline</button>

                            <!-- 2. CONFIRMED BOOKINGS -->
                            <?php elseif ($b['booking_status'] === 'Confirmed'): ?>

                            <?php if ($b['cancel_status'] === 'Pending'): ?>
                            <button class="btn-action btn-refund open-refund" data-id="<?php echo $b['id']; ?>"
                                data-customer="<?php echo $customer_name; ?>" data-venue="<?php echo $venue_name; ?>"
                                data-date="<?php echo $date_str; ?>" data-paid="<?php echo $amount_paid; ?>"
                                data-reason="<?php echo htmlspecialchars($b['cancel_reason']); ?>">
                                Refund Req
                            </button>
                            <?php else: ?>
                            <?php if ($b['payment_status'] === 'Partial' && $balance_due > 0): ?>
                            <button class="btn-action btn-confirm open-payment" data-id="<?php echo $b['id']; ?>"
                                data-due="<?php echo $balance_due; ?>">
                                Collect Pay
                            </button>
                            <?php endif; ?>

                            <button class="btn-action btn-reschedule open-reschedule" data-id="<?php echo $b['id']; ?>"
                                data-customer="<?php echo $customer_name; ?>" data-venue="<?php echo $venue_name; ?>"
                                data-type="<?php echo htmlspecialchars($actual_room_type); ?>"
                                data-date="<?php echo $date_str; ?>">
                                Reschedule
                            </button>
                            <?php endif; ?>

                            <?php endif; ?>

                            <!-- View Details is ALWAYS available -->
                            <button class="btn-action btn-view" data-id="<?php echo $b['id']; ?>">View Details</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modals Overlay -->
    <div class="modal-overlay" id="modalOverlay">

        <!-- Refund Modal -->
        <div class="admin-modal" id="refundModal">
            <h3 class="modal-main-title" id="modal-refund-title">Process Refund</h3>
            <h4 class="modal-subtitle">Transaction Summary</h4>
            <div class="summary-grid">
                <span class="label">Customer Name:</span> <span class="value">--</span>
                <span class="label">Venue Type:</span> <span class="value">--</span>
                <span class="label">Date:</span> <span class="value">--</span>
                <span class="label">Total Paid by Guest:</span> <span class="value">₱0.00</span>
                <span class="label">PayMongo Fee:</span> <span class="value">₱0.00</span>
                <span class="label">Reason:</span>
                <span class="value" style="font-size: 0.9rem; color: #666;">Guest requested cancellation. System will
                    free up these dates.</span>
            </div>
            <div class="refund-total">
                <span class="label">Refund Amount:</span>
                <span class="value amount">₱0.00</span>
            </div>
            <div class="modal-actions">
                <button class="btn-modal btn-modal-cancel close-modal">Cancel</button>
                <button class="btn-modal btn-modal-danger btn-modal-refund">Execute Refund</button>
            </div>
        </div>

        <!-- Reschedule Modal -->
        <div class="admin-modal" id="rescheduleModal">
            <h3 class="modal-main-title text-center">Reschedule Booking</h3>
            <h4 class="modal-subtitle">Booking Summary</h4>

            <div class="summary-grid reschedule-grid">
                <span class="label">Customer Name:</span> <span class="value">--</span>
                <span class="label">Venue Name:</span> <span class="value">--</span>
                <span class="label">Original Date:</span> <span class="value">--</span>
            </div>

            <!-- DYNAMIC CALENDAR INJECTION -->
            <div class="date-picker-wrapper" style="margin-top: 20px; border-top: 1px solid #eee; padding-top: 20px;">
                <label
                    style="display: block; margin-bottom: 10px; font-weight: 600; font-size: 0.9rem; color: var(--color-dark);">Select
                    New Dates:</label>
                <?php
                    // We reuse the global calendar component!
                    $calendarId = 'cal-ui-reschedule';
                    include 'includes/partials/booking_calendar.php';
                ?>
            </div>

            <div class="modal-actions" style="margin-top: 25px;">
                <button class="btn-modal btn-modal-cancel close-modal">Cancel</button>
                <button class="btn-modal btn-modal-primary btn-modal-refund">Confirm Reschedule</button>
            </div>
        </div>

        <!-- View Details Modal -->
        <div class="admin-modal" id="viewDetailsModal" style="max-width: 600px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 class="modal-main-title" id="vd-title" style="margin-bottom: 0;">Booking Details</h3>
                <span class="status-badge" id="vd-status-badge">--</span>
            </div>

            <!-- Customer Info -->
            <h4 class="modal-subtitle" style="font-size: 1.1rem; border-bottom: 1px solid #eee; padding-bottom: 5px;">
                Customer Information</h4>
            <div class="summary-grid" style="grid-template-columns: 120px 1fr; margin-bottom: 20px;">
                <span class="label">Name:</span> <span class="value" id="vd-customer-name">--</span>
                <span class="label">Email:</span> <span class="value" id="vd-customer-email">--</span>
                <span class="label">Phone:</span> <span class="value" id="vd-customer-phone">--</span>
            </div>

            <!-- Booking Info -->
            <h4 class="modal-subtitle" style="font-size: 1.1rem; border-bottom: 1px solid #eee; padding-bottom: 5px;">
                Reservation Details</h4>
            <div class="summary-grid" style="grid-template-columns: 120px 1fr; margin-bottom: 20px;">
                <span class="label">Venue:</span> <span class="value" id="vd-venue">--</span>
                <span class="label">Dates:</span> <span class="value" id="vd-dates">--</span>
                <span class="label">Guests:</span> <span class="value" id="vd-guests">--</span>
                <span class="label" id="vd-specific-label" style="display:none;">Specifics:</span>
                <span class="value" id="vd-specific-value" style="display:none;">--</span>
            </div>

            <!-- Add-ons Container (Injected via JS) -->
            <div id="vd-addons-container" style="display: none; margin-bottom: 20px;">
                <h4 class="modal-subtitle"
                    style="font-size: 1.1rem; border-bottom: 1px solid #eee; padding-bottom: 5px;">Add-ons</h4>
                <div class="summary-grid" id="vd-addons-list" style="grid-template-columns: 1fr auto;">
                    <!-- JS injects addons here -->
                </div>
            </div>

            <!-- Financials -->
            <h4 class="modal-subtitle" style="font-size: 1.1rem; border-bottom: 1px solid #eee; padding-bottom: 5px;">
                Financial Breakdown</h4>
            <div class="summary-grid" style="grid-template-columns: 1fr auto; margin-bottom: 10px;">
                <span class="label">Base Amount:</span> <span class="value" id="vd-base-amt">₱0.00</span>
                <span class="label">Add-ons Amount:</span> <span class="value" id="vd-addons-amt">₱0.00</span>
                <span class="label">Extra Pax Amount:</span> <span class="value" id="vd-extrapax-amt">₱0.00</span>
            </div>

            <div class="refund-total" style="margin-top: 10px; padding-top: 10px; justify-content: space-between;">
                <span class="label">Total Amount:</span>
                <span class="value amount" id="vd-total-amt" style="color: var(--color-gold);">₱0.00</span>
            </div>

            <div class="summary-grid" style="grid-template-columns: 1fr auto; margin-bottom: 0; margin-top: 10px;">
                <span class="label">Payment Scheme:</span> <span class="value" id="vd-scheme">--</span>
                <span class="label">Amount Paid:</span> <span class="value" id="vd-paid-amt"
                    style="color: #4ade80;">₱0.00</span>
            </div>

            <div class="modal-actions" style="margin-top: 30px;">
                <button class="btn-modal btn-modal-cancel close-modal" style="width: 100%;">Close</button>
            </div>
        </div>

        <!-- Collect Payment Modal -->
        <div class="admin-modal modal-sm" id="paymentModal">
            <h3 class="modal-title">Collect Payment</h3>
            <div class="modal-body">
                <p style="margin-bottom: 20px;">Remaining Balance: <strong id="pmt-balance"
                        style="color: #e06666; font-size: 1.2rem;">₱0.00</strong></p>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label
                        style="display: block; margin-bottom: 5px; font-weight: 500; color: var(--color-dark);">Amount
                        to Collect (₱)</label>
                    <input type="number" id="pmt-amount-input" step="0.01"
                        style="width: 100%; padding: 12px; border: 1px solid rgba(42, 37, 34, 0.15); border-radius: 4px;">
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label
                        style="display: block; margin-bottom: 5px; font-weight: 500; color: var(--color-dark);">Payment
                        Method</label>
                    <select id="pmt-method"
                        style="width: 100%; padding: 12px; border: 1px solid rgba(42, 37, 34, 0.15); border-radius: 4px;">
                        <option value="Cash" selected>Cash (Front Desk)</option>
                        <option value="GCash">GCash</option>
                        <option value="Maya">Maya</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                    </select>
                </div>

                <!-- Hidden by default because "Cash" is selected -->
                <div class="form-group hidden" id="pmt-trans-wrapper" style="margin-bottom: 15px;">
                    <label
                        style="display: block; margin-bottom: 5px; font-weight: 500; color: var(--color-dark);">Transaction
                        / Ref ID</label>
                    <input type="text" id="pmt-trans-id" placeholder="Enter reference number"
                        style="width: 100%; padding: 12px; border: 1px solid rgba(42, 37, 34, 0.15); border-radius: 4px;">
                </div>
            </div>

            <div class="modal-actions-center">
                <button class="btn btn-outline btn-modal-cancel close-modal">Cancel</button>
                <!-- Used btn-primary so it gets the brand Gold color -->
                <button class="btn btn-primary" id="btn-execute-payment">Confirm</button>
            </div>
        </div>
        <!-- Approve Booking Modal -->
        <div class="admin-modal modal-sm" id="approveModal">
            <i class="fa-solid fa-circle-check modal-icon-warning" style="color: #4ade80;"></i>
            <h3 class="modal-title">Approve Booking?</h3>
            <div class="modal-body modal-text-center">
                <!-- FIX: Removed display: block class so text stays on one line -->
                <p>Are you sure you want to manually confirm Booking <strong style="color: var(--color-gold);">#<span
                            id="approve-booking-id"></span></strong>?</p>
            </div>
            <div class="modal-actions-center">
                <!-- FIX: Forced transparent background and dark text -->
                <button class="btn btn-modal-cancel close-modal"
                    style="background: transparent; color: var(--color-dark); border: 1px solid rgba(42, 37, 34, 0.2);">Cancel</button>
                <button class="btn btn-primary" id="btn-execute-approve"
                    style="background-color: #4ade80; border-color: #4ade80; color: var(--color-dark);">Yes,
                    Approve</button>
            </div>
        </div>

        <!-- Decline/Cancel Booking Modal -->
        <div class="admin-modal modal-sm" id="declineModal">
            <i class="fa-solid fa-triangle-exclamation modal-icon-warning"></i>
            <h3 class="modal-title">Decline Booking?</h3>
            <div class="modal-body modal-text-center">
                <!-- FIX: Removed display: block class so text stays on one line -->
                <p>Are you sure you want to cancel Booking <strong style="color: #e06666;">#<span
                            id="decline-booking-id"></span></strong>?</p>
                <p class="modal-subtext">This action cannot be undone and will free up the dates.</p>
            </div>
            <div class="modal-actions-center">
                <button class="btn btn-modal-cancel close-modal"
                    style="background: transparent; color: var(--color-dark); border: 1px solid rgba(42, 37, 34, 0.2);">Go
                    Back</button>
                <button class="btn btn-primary btn-modal-danger" id="btn-execute-decline">Yes, Cancel It</button>
            </div>
        </div>
        <!--  Confirm Modal -->
        <div class="admin-modal modal-sm" id="uniConfirmModal">
            <i class="fa-solid fa-circle-question modal-icon-warning" style="color: var(--color-gold);"></i>
            <h3 class="modal-title">Confirm Action</h3>
            <div class="modal-body modal-text-center">
                <p id="uc-message">Are you sure you want to proceed?</p>
            </div>
            <div class="modal-actions-center">
                <button class="btn btn-outline btn-modal-cancel" id="uc-btn-no">No, Go Back</button>
                <button class="btn btn-primary" id="uc-btn-yes">Yes, Proceed</button>
            </div>
        </div>

        <!--  Alert Modal  -->
        <div class="admin-modal modal-sm" id="uniAlertModal">
            <i class="fa-solid fa-circle-info modal-icon-warning" id="ua-icon"></i>
            <h3 class="modal-title" id="ua-title">Notice</h3>
            <div class="modal-body modal-text-center">
                <p id="ua-message">Message text goes here.</p>
            </div>
            <div class="modal-actions-center">
                <button class="btn btn-primary" id="ua-btn-ok" style="width: 100%;">OK</button>
            </div>
        </div>
    </div>
</div>