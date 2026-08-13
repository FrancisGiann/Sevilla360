<div class="admin-bookings-container">
    <p class="bookings-subtitle">MANAGE CUSTOMER RESERVATIONS</p>

    <!-- Header -->
    <div class="bookings-page-header">
        <div class="top-controls">
            <div class="search-bar">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input type="text" id="table-search" placeholder="Search by name, ID, or venue...">
            </div>
            <select class="control-select" id="table-venue-filter">
                <option value="All">All Venues</option>
                <option value="Event Hall">Event Hall</option>
                <option value="Hotel Room">Hotel Room</option>
                <option value="Resort Villa">Resort Villa</option>
            </select>
        </div>
    </div>

    <!-- Table Card -->
    <div class="table-card">
        <h3 class="card-title">Booking History</h3>

        <div class="booking-tabs" id="bookingFilters">
            <button class="tab-btn active" data-filter="all">All</button>
            <button class="tab-btn" data-filter="action_req" style="color: #e06666; font-weight: 600;">Action
                Required</button>
            <button class="tab-btn" data-filter="partial">Balances Due</button>
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
                <tbody id="admin-bookings-tbody">
                    <!-- Default loading state. JS will overwrite this! -->
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px; color: #888;">
                            <i class="fa-solid fa-circle-notch fa-spin"
                                style="font-size: 1.5rem; margin-bottom: 10px; color: var(--color-gold);"></i><br>
                            Loading Bookings...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- NEW: Server-Side Pagination Controls -->
        <div class="pagination-controls"
            style="display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; border-top: 1px solid #eee; background: #faf9f7; border-radius: 0 0 8px 8px;">
            <div style="font-size: 0.9rem; color: #666;">
                Showing <span id="pag-total-rows" style="font-weight: 600; color: var(--color-dark);">0</span> total
                bookings
            </div>
            <div style="display: flex; gap: 8px; align-items: center;">
                <span style="font-size: 0.9rem; font-weight: 600; color: var(--color-dark); margin-right: 10px;">Page <span
                        id="pag-current-page" style="color: var(--color-gold);">1</span> of <span
                        id="pag-total-pages">1</span></span>
                <button id="btn-prev-page" class="btn-outline" style="padding: 6px 14px; font-size: 0.9rem; border-radius: 4px; border: 1px solid #ccc; cursor: pointer;" disabled>
                    &laquo; Prev</button>
                <button id="btn-next-page" class="btn-outline" style="padding: 6px 14px; font-size: 0.9rem; border-radius: 4px; border: 1px solid #ccc; cursor: pointer;" disabled>Next
                    &raquo;</button>
            </div>
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
                <span class="value" id="modal-ref-reason" style="font-size: 0.9rem; color: #666;">--</span>
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
                <span class="label" id="vd-transaction-label" style="display:none;">Transaction Ref:</span>
                <span class="value" id="vd-transaction-value" style="display:none; font-family: monospace;">--</span>
            </div>

            <!-- Add-ons & Line Items Container -->
            <div id="vd-addons-container" style="display: none; margin-bottom: 20px;">
                <h4 class="modal-subtitle"
                    style="font-size: 1.1rem; border-bottom: 1px solid #eee; padding-bottom: 5px;">Add-ons & Line Items
                </h4>
                <div class="summary-grid" id="vd-addons-list" style="grid-template-columns: 1fr auto; row-gap: 8px;">
                    <!-- JS injects addons/line items here -->
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

            <div class="modal-actions" style="margin-top: 30px; display: flex; gap: 10px;">
                <button class="btn-modal btn-modal-cancel close-modal"
                    style="flex: 1; background: transparent; color: var(--color-dark); border: 1px solid #ccc;">Close</button>
                <button class="btn-modal" id="btn-admin-print" style="flex: 1; background: var(--color-gold);"><i class="fa-solid fa-print"></i> Print</button>
                <button class="btn-modal" id="btn-admin-resend" style="flex: 1; background: var(--color-dark);"><i class="fa-solid fa-envelope"></i> Resend Email</button>
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
                <button class="btn btn-primary" id="btn-execute-payment">Confirm</button>
            </div>
        </div>

        <!-- Approve Booking Modal -->
        <div class="admin-modal modal-sm" id="approveModal">
            <i class="fa-solid fa-circle-check modal-icon-warning" style="color: #4ade80;"></i>
            <h3 class="modal-title">Approve Booking?</h3>
            <div class="modal-body modal-text-center">
                <p>Are you sure you want to manually confirm Booking <strong style="color: var(--color-gold);">#<span
                            id="approve-booking-id"></span></strong>?</p>
            </div>
            <div class="modal-actions-center">
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

        <!-- Review Reschedule Request Modal -->
        <div class="admin-modal" id="reviewReschedModal">
            <h3 class="modal-main-title">Review Reschedule Request</h3>

            <div class="summary-grid">
                <span class="label">Customer Name:</span> <span class="value" id="rr-customer">--</span>
                <span class="label">Venue:</span> <span class="value" id="rr-venue">--</span>
                <span class="label">Current Dates:</span> <span class="value" id="rr-old-dates"
                    style="text-decoration: line-through; color: #888;">--</span>
                <span class="label">Requested Dates:</span> <span class="value" id="rr-new-dates"
                    style="color: var(--color-gold); font-weight: 600; font-size: 1.1rem;">--</span>
                <span class="label">Reason:</span> <span class="value" id="rr-reason"
                    style="font-size: 0.9rem; color: #666;">--</span>
            </div>

            <div id="rr-conflict-warning"
                style="display: none; background: #fee2e2; color: #dc2626; padding: 12px; border-radius: 4px; margin-top: 15px; font-weight: 500; text-align: center;">
                <i class="fa-solid fa-triangle-exclamation"></i> Warning: These requested dates are already booked by
                another customer!
            </div>

            <div id="rr-reject-box" style="display: none; margin-top: 15px;">
                <label style="font-weight: 600; display: block; margin-bottom: 5px;">Reason for Rejection:</label>
                <textarea id="rr-reject-reason"
                    style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ccc;" rows="2"
                    placeholder="e.g. Sorry, those dates are unavailable."></textarea>
            </div>

            <div class="modal-actions" style="margin-top: 30px;">
                <button class="btn-modal btn-modal-cancel close-modal">Close</button>
                <button class="btn-modal btn-modal-danger" id="btn-reject-resched">Reject Request</button>
                <button class="btn-modal btn-modal-primary" id="btn-approve-resched"
                    style="background-color: #4ade80; color: var(--color-dark);">Approve & Move</button>
            </div>
        </div>

        <!-- Admin Force Cancel Modal -->
        <div class="admin-modal modal-sm" id="forceCancelModal">
            <i class="fa-solid fa-cloud-bolt modal-icon-warning" style="color: #e06666;"></i>
            <h3 class="modal-title">Admin Override Cancel</h3>
            <div class="modal-body">
                <p style="margin-bottom: 15px; font-size: 0.95rem;">You are forcing a cancellation for <strong
                        id="fc-customer">--</strong>.</p>
                <div
                    style="background: #fee2e2; color: #dc2626; padding: 10px; border-radius: 4px; font-size: 0.85rem; margin-bottom: 15px;">
                    <i class="fa-solid fa-circle-info"></i> Because the resort is initiating this, the customer will
                    receive a <strong>100% Full Refund</strong> (₱<span id="fc-refund-amt">0</span>). The resort absorbs
                    all processing fees.
                </div>

                <label style="font-weight: 600; display: block; margin-bottom: 5px;">Reason for Cancellation:</label>
                <textarea id="fc-reason" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ccc;"
                    rows="2" placeholder="e.g. Typhoon, Maintenance Issue, Overbooked..."></textarea>
            </div>
            <div class="modal-actions-center">
                <button class="btn btn-outline btn-modal-cancel close-modal">Go Back</button>
                <button class="btn btn-primary btn-modal-danger" id="btn-execute-force-cancel">Confirm
                    Cancellation</button>
            </div>
        </div>

        <!-- Event Hall Itemized Invoice / Edit Price Modal -->
        <div class="admin-modal" id="editPriceModal" style="max-width: 650px;">
            <h3 class="modal-main-title">Finalize Event Invoice</h3>
            <p style="margin-bottom: 20px; font-size: 0.9rem; color: #666;">
                Review the consultation details and finalize the itemized invoice for Booking <strong
                    id="ep-booking-id">--</strong>. Once saved, an email will be sent to the customer to collect their
                downpayment.
            </p>

            <div class="summary-grid" style="grid-template-columns: 1fr 1fr; margin-bottom: 20px;">
                <div>
                    <label style="display:block; font-weight:600; margin-bottom:5px;">Guest Count</label>
                    <input type="number" id="ep-guests"
                        style="width:100%; padding:10px; border:1px solid #ccc; border-radius:4px;">
                </div>
                <div>
                    <label style="display:block; font-weight:600; margin-bottom:5px;">Event Type</label>
                    <input type="text" id="ep-event-type"
                        style="width:100%; padding:10px; border:1px solid #ccc; border-radius:4px;">
                </div>
            </div>

            <div style="margin-bottom: 25px;">
                <label style="display:block; font-weight:600; margin-bottom:5px;">Venue Base Rate (₱)</label>
                <input type="number" id="ep-base-rate" step="0.01" class="ep-calc-trigger"
                    style="width:100%; padding:12px; border:1px solid #ccc; border-radius:4px; font-size:1.1rem; color:var(--color-dark);">
            </div>

            <div
                style="display:flex; justify-content:space-between; align-items:center; border-bottom: 2px solid #eee; padding-bottom:10px; margin-bottom:15px;">
                <h4 style="margin:0; font-size:1.1rem; color:var(--color-dark);">Additional Line Items</h4>
                <button type="button" class="btn-action" id="ep-btn-add-item"
                    style="background: var(--color-dark); color: white; padding: 6px 12px; border-radius: 4px; font-size: 0.85rem;"><i
                        class="fa-solid fa-plus"></i> Add Item</button>
            </div>

            <!-- JS will inject rows here -->
            <div id="ep-line-items"
                style="max-height: 200px; overflow-y: auto; padding-right: 5px; margin-bottom: 20px;"></div>

            <!-- NEW: ADMIN PAYMENT SCHEME SELECTOR -->
            <div style="margin-bottom: 20px;">
                <label style="display:block; font-weight:600; margin-bottom:5px;">Required Payment Scheme</label>
                <select id="ep-payment-scheme"
                    style="width:100%; padding:10px; border:1px solid #ccc; border-radius:4px; font-size: 0.95rem;">
                    <option value="100% Full">100% Full Payment</option>
                    <option value="50% Downpayment">50% Downpayment</option>
                    <option value="20% Reservation" selected>20% Reservation Fee</option>
                </select>
                <small style="color: #666; display: block; margin-top: 5px;">This dictates how much the customer must
                    pay today via their dashboard.</small>
            </div>

            <div class="refund-total"
                style="margin-top: 10px; justify-content: space-between; background: #faf9f7; padding: 15px; border-radius: 6px; border: 1px dashed #ccc;">
                <span class="label" style="font-size:1.2rem;">Final Total:</span>
                <span class="value amount" id="ep-calc-total"
                    style="color: var(--color-gold); font-size:1.5rem;">₱0.00</span>
            </div>

            <div class="modal-actions" style="margin-top: 25px;">
                <button class="btn-modal btn-modal-cancel close-modal"
                    style="background: transparent; color: var(--color-dark); border: 1px solid #ccc;">Cancel</button>
                <button class="btn-modal btn-modal-primary" id="btn-execute-edit-price"
                    style="background-color: var(--color-gold); color: white; border: none;">Save & Send
                    Invoice</button>
            </div>
        </div>
    </div>
</div>