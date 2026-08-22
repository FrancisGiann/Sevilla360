<div class="admin-bookings-container">
    <p class="bookings-subtitle">MANAGE CUSTOMER RESERVATIONS</p>

    <!-- Page Header & Search Controls -->
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

    <!-- Table Card & History -->
    <div class="table-card">
        <div class="booking-history-heading" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 class="card-title" style="margin-bottom: 0;">Booking History</h3>
            <button id="btn-refresh-bookings" class="btn btn-outline" style="padding: 6px 12px; font-size: 13px; display: flex; align-items: center; gap: 5px;">
                <i class="fa-solid fa-arrows-rotate"></i> Refresh Bookings
            </button>
        </div>

        <!-- Booking Status Filter Tabs -->
        <div class="booking-tabs" id="bookingFilters">
            <button class="tab-btn active" data-filter="all">All</button>
            <button class="tab-btn tab-action-req" data-filter="action_req">Action Required</button>
            <button class="tab-btn" data-filter="partial">Balances Due</button>
            <button class="tab-btn" data-filter="pending">Pending</button>
            <button class="tab-btn" data-filter="confirmed">Confirmed</button>
            <button class="tab-btn" data-filter="cancelled">Cancelled</button>
        </div>
        <label class="booking-filter-select-label" for="bookingFilterSelect">Booking status</label>
        <select class="booking-filter-select" id="bookingFilterSelect" aria-label="Filter bookings by status">
            <option value="all">All</option>
            <option value="action_req">Action Required</option>
            <option value="partial">Balances Due</option>
            <option value="pending">Pending</option>
            <option value="confirmed">Confirmed</option>
            <option value="cancelled">Cancelled</option>
        </select>

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
                    <!-- Default loading state (populated dynamically by JavaScript) -->
                    <tr>
                        <td colspan="7" class="table-loading-td-padded">
                            <i class="fa-solid fa-circle-notch fa-spin spinner-icon-gold"></i><br>
                            Loading Bookings...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Server-Side Pagination Controls -->
        <div class="pagination-controls pagination-wrapper">
            <div class="pagination-info">
                Showing <span id="pag-total-rows" class="pagination-bold-dark">0</span> bookings
            </div>
            <div class="pagination-controls-right">
                <span class="pagination-page-label">Page <span id="pag-current-page" class="text-gold-bold">1</span> of <span id="pag-total-pages">1</span></span>
                <button id="btn-prev-page" class="btn-outline btn-pag" disabled>&laquo; Prev</button>
                <button id="btn-next-page" class="btn-outline btn-pag" disabled>Next &raquo;</button>
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
                <span class="value text-sub-muted" id="modal-ref-reason">--</span>
            </div>
            
            <div class="form-group" style="margin-top: 15px;">
                <label class="form-label-med">Refund Transaction / Reference ID <span style="color:red;">*</span></label>
                <input type="text" id="refund-transaction-id" class="form-input-padded" placeholder="Enter bank/wallet ref number" required>
            </div>

            <div class="refund-total" style="margin-top: 15px;">
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

            <!-- Dynamic Availability Calendar -->
            <div class="date-picker-wrapper date-picker-wrapper-bordered">
                <label class="form-label-bold">Select New Dates:</label>
                <?php
                    $calendarId = 'cal-ui-reschedule';
                    include 'includes/partials/booking_calendar.php';
                ?>
            </div>

            <div class="modal-actions modal-actions-top-25">
                <button class="btn-modal btn-modal-cancel close-modal">Cancel</button>
                <button class="btn-modal btn-modal-primary btn-modal-refund">Confirm Reschedule</button>
            </div>
        </div>

        <!-- View Details Modal -->
        <div class="admin-modal modal-view-details" id="viewDetailsModal">
            <div class="vd-header">
                <h3 class="modal-main-title vd-title" id="vd-title">Booking Details</h3>
                <span class="status-badge" id="vd-status-badge">--</span>
            </div>

            <!-- Customer Information -->
            <h4 class="modal-subtitle vd-section-title">Customer Information</h4>
            <div class="summary-grid vd-summary-grid">
                <span class="label">Name:</span> <span class="value" id="vd-customer-name">--</span>
                <span class="label">Email:</span> <span class="value" id="vd-customer-email">--</span>
                <span class="label">Phone:</span> <span class="value" id="vd-customer-phone">--</span>
            </div>

            <!-- Reservation Details -->
            <h4 class="modal-subtitle vd-section-title">Reservation Details</h4>
            <div class="summary-grid vd-summary-grid">
                <span class="label">Venue:</span> <span class="value" id="vd-venue">--</span>
                <span class="label">Dates:</span> <span class="value" id="vd-dates">--</span>
                <span class="label">Guests:</span> <span class="value" id="vd-guests">--</span>
                <span class="label hidden-element" id="vd-specific-label">Specifics:</span>
                <span class="value hidden-element" id="vd-specific-value">--</span>
                <span class="label" id="vd-transaction-label" style="display:none;">Transaction ID:</span> 
                <span class="value" id="vd-transaction-value" style="display:none;">--</span>
                <span class="label" id="vd-refund-tx-label" style="display:none;">Refund Tx ID:</span> 
                <span class="value text-red-danger" id="vd-refund-tx-value" style="display:none;">--</span>
            </div>

            <!-- Add-ons & Line Items Container -->
            <div id="vd-addons-container" class="hidden-element">
                <h4 class="modal-subtitle vd-section-title">Add-ons & Line Items</h4>
                <div class="summary-grid vd-addons-grid" id="vd-addons-list"></div>
            </div>

            <!-- Financial Breakdown -->
            <h4 class="modal-subtitle vd-section-title">Financial Breakdown</h4>
            <div class="summary-grid vd-financials-grid">
                <span class="label">Base Amount:</span> <span class="value" id="vd-base-amt">₱0.00</span>
                <span class="label">Add-ons Amount:</span> <span class="value" id="vd-addons-amt">₱0.00</span>
                <span class="label">Extra Pax Amount:</span> <span class="value" id="vd-extrapax-amt">₱0.00</span>
            </div>

            <div class="refund-total vd-total-row">
                <span class="label">Total Amount:</span>
                <span class="value amount text-gold" id="vd-total-amt">₱0.00</span>
            </div>

            <div class="summary-grid vd-paid-grid">
                <span class="label">Payment Scheme:</span> <span class="value" id="vd-scheme">--</span>
                <span class="label">Amount Paid:</span> <span class="value text-green-paid" id="vd-paid-amt">₱0.00</span>
            </div>

            <div class="modal-actions vd-modal-actions">
                <button class="btn-modal btn-modal-cancel close-modal">Close</button>
                <button class="btn-modal btn-gold" id="btn-admin-print"><i class="fa-solid fa-print"></i> <span>Print</span></button>
                <button class="btn-modal btn-dark" id="btn-admin-resend"><i class="fa-solid fa-envelope"></i> <span>Resend Email</span></button>
            </div>
        </div>

        <!-- Collect Payment Modal -->
        <div class="admin-modal modal-sm" id="paymentModal">
            <h3 class="modal-title">Collect Payment</h3>
            <div class="modal-body">
                <p class="pmt-balance-wrapper">Remaining Balance: <strong id="pmt-balance" class="pmt-balance-amount">₱0.00</strong></p>

                <div class="form-group form-group-mb15">
                    <label class="form-label-med">Amount to Collect (₱)</label>
                    <input type="number" id="pmt-amount-input" step="0.01" class="form-input-padded">
                </div>

                <div class="form-group form-group-mb15">
                    <label class="form-label-med">Payment Method</label>
                    <select id="pmt-method" class="form-input-padded">
                        <option value="Cash" selected>Cash (Front Desk)</option>
                        <option value="GCash">GCash</option>
                        <option value="Maya">Maya</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                    </select>
                </div>

                <div class="form-group hidden form-group-mb15" id="pmt-trans-wrapper">
                    <label class="form-label-med">Transaction / Ref ID</label>
                    <input type="text" id="pmt-trans-id" placeholder="Enter reference number" class="form-input-padded">
                </div>
            </div>

            <div class="modal-actions-center">
                <button class="btn btn-outline btn-modal-cancel close-modal">Cancel</button>
                <button class="btn btn-primary" id="btn-execute-payment">Confirm</button>
            </div>
        </div>

        <!-- Approve Booking Modal -->
        <div class="admin-modal modal-sm" id="approveModal">
            <i class="fa-solid fa-circle-check modal-icon-warning icon-green"></i>
            <h3 class="modal-title">Approve Booking?</h3>
            <div class="modal-body modal-text-center">
                <p>Are you sure you want to manually confirm Booking <strong class="text-gold-highlight">#<span id="approve-booking-id"></span></strong>?</p>
            </div>
            <div class="modal-actions-center">
                <button class="btn btn-modal-cancel close-modal btn-cancel-outline">Cancel</button>
                <button class="btn btn-primary btn-approve-green" id="btn-execute-approve">Yes, Approve</button>
            </div>
        </div>

        <!-- Decline/Cancel Booking Modal -->
        <div class="admin-modal modal-sm" id="declineModal">
            <i class="fa-solid fa-triangle-exclamation modal-icon-warning"></i>
            <h3 class="modal-title">Decline Booking?</h3>
            <div class="modal-body modal-text-center">
                <p>Are you sure you want to cancel Booking <strong class="text-red-danger">#<span id="decline-booking-id"></span></strong>?</p>
                <p class="modal-subtext">This action cannot be undone and will free up the dates.</p>
            </div>
            <div class="modal-actions-center">
                <button class="btn btn-modal-cancel close-modal btn-cancel-outline">Go Back</button>
                <button class="btn btn-primary btn-modal-danger" id="btn-execute-decline">Yes, Cancel It</button>
            </div>
        </div>

        <!-- Universal Confirm Modal -->
        <div class="admin-modal modal-sm" id="uniConfirmModal">
            <i class="fa-solid fa-circle-question modal-icon-warning text-gold-highlight"></i>
            <h3 class="modal-title">Confirm Action</h3>
            <div class="modal-body modal-text-center">
                <p id="uc-message">Are you sure you want to proceed?</p>
            </div>
            <div class="modal-actions-center">
                <button class="btn btn-outline btn-modal-cancel" id="uc-btn-no">No, Go Back</button>
                <button class="btn btn-primary" id="uc-btn-yes">Yes, Proceed</button>
            </div>
        </div>

        <!-- Universal Alert Modal -->
        <div class="admin-modal modal-sm" id="uniAlertModal">
            <i class="fa-solid fa-circle-info modal-icon-warning" id="ua-icon"></i>
            <h3 class="modal-title" id="ua-title">Notice</h3>
            <div class="modal-body modal-text-center">
                <p id="ua-message">Message text goes here.</p>
            </div>
            <div class="modal-actions-center">
                <button class="btn btn-primary btn-full-width" id="ua-btn-ok">OK</button>
            </div>
        </div>

        <!-- Review Reschedule Request Modal -->
        <div class="admin-modal" id="reviewReschedModal">
            <h3 class="modal-main-title">Review Reschedule Request</h3>

            <div class="summary-grid">
                <span class="label">Customer Name:</span> <span class="value" id="rr-customer">--</span>
                <span class="label">Venue:</span> <span class="value" id="rr-venue">--</span>
                <span class="label">Current Dates:</span> <span class="value text-line-through" id="rr-old-dates">--</span>
                <span class="label">Requested Dates:</span> <span class="value text-gold-bold-large" id="rr-new-dates">--</span>
                <span class="label">Reason:</span> <span class="value text-sub-muted" id="rr-reason">--</span>
            </div>

            <div id="rr-conflict-warning" class="resched-warning-banner">
                <i class="fa-solid fa-triangle-exclamation"></i> Warning: These requested dates are already booked by another customer!
            </div>

            <div id="rr-reject-box" class="resched-reject-box">
                <label class="form-label-bold-block">Reason for Rejection:</label>
                <textarea id="rr-reject-reason" class="form-textarea-padded" rows="2" placeholder="e.g. Sorry, those dates are unavailable."></textarea>
            </div>

            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button class="btn-modal btn-modal-cancel close-modal" style="flex: 1; letter-spacing: 2px; text-transform: uppercase; font-size: 12px; font-weight: 500;">Close / Cancel</button>
                <button class="btn-modal btn-modal-danger" id="btn-reject-resched" style="flex: 1; letter-spacing: 2px; text-transform: uppercase; font-size: 12px; font-weight: 500;">Reject Request</button>
            </div>
            <div style="margin-top: 10px;">
                <button class="btn-modal" id="btn-approve-resched" style="width: 100%; background-color: #332d2a; color: #fff; border: none; padding: 12px; letter-spacing: 2px; text-transform: uppercase; font-size: 12px; font-weight: 500; cursor: pointer;">Approve Reschedule</button>
            </div>
        </div>

        <!-- Admin Override Cancel Modal -->
        <div class="admin-modal modal-sm" id="forceCancelModal">
            <i class="fa-solid fa-cloud-bolt modal-icon-warning text-red-danger"></i>
            <h3 class="modal-title">Admin Override Cancel</h3>
            <div class="modal-body">
                <p class="fc-subtitle">You are forcing a cancellation for <strong id="fc-customer">--</strong>.</p>
                <div class="fc-info-banner">
                    <i class="fa-solid fa-circle-info"></i> Because the resort is initiating this, the customer will receive a <strong>100% Full Refund</strong> (₱<span id="fc-refund-amt">0</span>). The resort absorbs all processing fees.
                </div>

                <label class="form-label-bold-block">Reason for Cancellation:</label>
                <textarea id="fc-reason" class="form-textarea-padded" rows="2" placeholder="e.g. Typhoon, Maintenance Issue, Overbooked..."></textarea>
            </div>
            <div class="modal-actions-center">
                <button class="btn btn-outline btn-modal-cancel close-modal">Go Back</button>
                <button class="btn btn-primary btn-modal-danger" id="btn-execute-force-cancel">Confirm Cancellation</button>
            </div>
        </div>

        <!-- Event Hall Itemized Invoice / Edit Price Modal -->
        <div class="admin-modal modal-edit-price" id="editPriceModal">
            <h3 class="modal-main-title">Finalize Event Invoice</h3>
            <p class="ep-subtitle">
                Review the consultation details and finalize the itemized invoice for Booking <strong id="ep-booking-id">--</strong>. Once saved, an email will be sent to the customer to collect their downpayment.
            </p>

            <div class="summary-grid ep-grid-2col">
                <div>
                    <label class="ep-label">Guest Count</label>
                    <input type="number" id="ep-guests" class="ep-input">
                </div>
                <div>
                    <label class="ep-label">Event Type</label>
                    <input type="text" id="ep-event-type" class="ep-input">
                </div>
            </div>

            <div class="ep-base-rate-group">
                <label class="ep-label">Venue Base Rate (₱)</label>
                <input type="number" id="ep-base-rate" step="0.01" class="ep-calc-trigger ep-input-lg">
            </div>

            <div class="ep-line-items-header">
                <h4 class="ep-line-items-title">Additional Line Items</h4>
                <button type="button" class="btn-action btn-add-item" id="ep-btn-add-item"><i class="fa-solid fa-plus"></i> Add Item</button>
            </div>

            <div id="ep-line-items" class="ep-line-items-container"></div>

            <div class="ep-field-group">
                <label class="ep-label">Required Payment Scheme</label>
                <select id="ep-payment-scheme" class="ep-select">
                    <option value="100% Full">100% Full Payment</option>
                    <option value="50% Downpayment">50% Downpayment</option>
                    <option value="20% Reservation" selected>20% Reservation Fee</option>
                </select>
                <small class="ep-help-text">This dictates how much the customer must pay today via their dashboard.</small>
            </div>

            <div class="ep-field-group">
                <label class="ep-label">Internal Preparation Notes (Admin Only)</label>
                <textarea id="ep-admin-notes" rows="3" placeholder="Style type, theme, setup time, decoration/setup instructions..." class="ep-textarea"></textarea>
                <small class="ep-help-text-muted">Saved internally for staff/admin view details only. Not sent to customer.</small>
            </div>

            <div class="refund-total ep-total-box">
                <span class="label ep-total-label">Final Total:</span>
                <span class="value amount ep-total-amount" id="ep-calc-total">₱0.00</span>
            </div>

            <div class="modal-actions modal-actions-top-25">
                <button class="btn-modal btn-modal-cancel close-modal btn-cancel-outline">Cancel</button>
                <button class="btn-modal btn-modal-primary btn-gold-save" id="btn-execute-edit-price">Save & Send Invoice</button>
            </div>
        </div>
    </div>
</div>
