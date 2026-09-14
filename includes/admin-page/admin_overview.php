<!-- Main Dashboard Overview Container -->
<div class="dashboard-container">
    <nav class="dashboard-header-bar" aria-label="Quick actions">
        <div class="quick-actions">
            <a href="admin_dashboard.php?page=walkin" class="btn-quick-action"><i class="fa-solid fa-plus" aria-hidden="true"></i> Walk-in / Event</a>
            <a href="admin_dashboard.php?page=calendar" class="btn-quick-action outline"><i class="fa-solid fa-calendar" aria-hidden="true"></i> Master Calendar</a>
        </div>
    </nav>

    <div id="dashboard-status-region" class="dashboard-status-region" role="status" aria-live="polite" aria-atomic="true" hidden>
        <span id="dashboard-status-message"></span>
        <button type="button" id="dashboard-retry" class="dashboard-retry" hidden>Retry</button>
    </div>

    <section class="overview-section overview-needs-attention" aria-labelledby="overview-attention-title">
        <h3 class="overview-section-title" id="overview-attention-title">Needs Attention</h3>
        <div class="overview-attention-items alerts-empty">
            <a href="admin_dashboard.php?page=bookings&amp;filter=action_req" class="stat-card action-required-card" aria-label="Review bookings requiring action">
                <div class="action-required-copy">
                    <h4>Action Required</h4>
                    <span>Review booking requests</span>
                </div>
                <div class="stat-card-row">
                    <span class="stat-number color-red dashboard-metric-pending" id="stat-action-req">Loading…</span>
                    <i class="fa-solid fa-arrow-right color-red stat-arrow-icon" aria-hidden="true"></i>
                </div>
            </a>
            <div id="maintenance-alerts-container" class="maintenance-alerts-list" aria-live="polite" aria-relevant="additions text" hidden></div>
        </div>
    </section>

    <section class="overview-section overview-glance" aria-labelledby="overview-glance-title">
        <h3 class="overview-section-title" id="overview-glance-title">Today at a Glance</h3>
        <div class="stats-grid overview-glance-grid">
            <div class="stat-card">
                <h4>Arrivals Today</h4>
                <span class="stat-number color-gold dashboard-metric-pending" id="stat-arrivals-today">Loading…</span>
            </div>
            <div class="stat-card">
                <h4>Today’s Occupancy</h4>
                <span class="stat-number color-dark dashboard-metric-pending" id="stat-occupancy-rate">Loading…</span>
            </div>
            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
            <a href="admin_dashboard.php?page=sales" class="stat-card stat-card-link">
                <h4>Monthly Sales</h4>
                <span class="stat-number color-green dashboard-metric-pending" id="stat-monthly-sales">Loading…</span>
            </a>
            <?php endif; ?>
        </div>
    </section>

    <div class="overview-workspace">
        <div class="overview-workspace-column overview-operations-column overview-support-grid">
            <section class="widget-card overview-itinerary" aria-labelledby="overview-itinerary-title">
                <div class="widget-header">
                    <h3 id="overview-itinerary-title"><i class="fa-solid fa-bell widget-title-icon" aria-hidden="true"></i> Today’s Itinerary</h3>
                    <button type="button" class="view-all-text btn-open-modal" data-target="modal-today">View All</button>
                </div>
                <div class="widget-list" id="widget-today-list">
                    <p class="widget-placeholder-text">Loading…</p>
                </div>
            </section>

            <section class="overview-module overview-maintenance" aria-labelledby="overview-maintenance-title">
                <div class="module-heading">
                    <h3 id="overview-maintenance-title"><i class="fa-solid fa-screwdriver-wrench" aria-hidden="true"></i> Maintenance Summary</h3>
                    <a href="admin_dashboard.php?page=maintenance">Manage maintenance</a>
                </div>
                <div id="overview-maintenance-summary" class="maintenance-summary-list">
                    <p class="widget-placeholder-text">Loading maintenance…</p>
                </div>
            </section>

            <section class="overview-module overview-pipeline" aria-labelledby="overview-pipeline-title">
                <div class="module-heading">
                    <h3 id="overview-pipeline-title"><i class="fa-solid fa-chart-pie" aria-hidden="true"></i> Booking Pipeline</h3>
                    <a href="admin_dashboard.php?page=bookings">View bookings</a>
                </div>
                <div class="canvas-wrapper canvas-wrapper-compact">
                    <p id="overview-pipeline-state" class="dashboard-chart-state">Loading booking pipeline…</p>
                    <canvas id="statusChart" role="img" aria-label="Booking pipeline chart" hidden></canvas>
                </div>
            </section>

            <section class="widget-card overview-events" aria-labelledby="overview-events-title">
                <div class="widget-header">
                    <h3 id="overview-events-title"><i class="fa-solid fa-calendar-star widget-title-icon" aria-hidden="true"></i> Major Events Radar</h3>
                    <button type="button" class="view-all-text btn-open-modal" data-target="modal-events">View All</button>
                </div>
                <div class="widget-list" id="widget-events-list">
                    <p class="widget-placeholder-text">Loading…</p>
                </div>
            </section>
        </div>

        <div class="overview-workspace-column overview-planning-column">
            <section class="overview-module overview-mini-calendar" aria-labelledby="overview-calendar-title">
                <div class="module-heading">
                    <h3 id="overview-calendar-title"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i> Schedule Snapshot</h3>
                    <a href="admin_dashboard.php?page=calendar">Full calendar</a>
                </div>
                <div id="overview-mini-calendar" class="mini-calendar-grid" aria-live="polite">
                    <p class="widget-placeholder-text">Loading calendar…</p>
                </div>
            </section>

            <section class="table-card recent-bookings-card overview-recent-bookings" aria-labelledby="overview-recent-title">
                <div class="table-header">
                    <h3 id="overview-recent-title">Recent Bookings</h3>
                    <a href="admin_dashboard.php?page=bookings" class="view-all-text">Manage All</a>
                </div>
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Booking ID</th>
                                <th>Venue</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="recent-bookings-tbody">
                            <tr>
                                <td colspan="5" class="table-loading-td">Loading…</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</div>

<!-- Overview Modals Overlay -->
<div class="overview-modal-overlay" id="overviewModalOverlay" aria-hidden="true">

    <!-- Today's Itinerary Modal -->
    <div class="overview-modal" id="modal-today" role="dialog" aria-modal="true" aria-labelledby="modal-today-title" aria-hidden="true">
        <div class="modal-header">
            <h3 id="modal-today-title" tabindex="-1">Today’s Complete Itinerary</h3>
            <button type="button" class="close-overview-modal" aria-label="Close today’s itinerary"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Guest</th>
                        <th>Venue</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="modal-today-tbody"></tbody>
            </table>
        </div>
    </div>

    <!-- Major Events Radar Modal -->
    <div class="overview-modal" id="modal-events" role="dialog" aria-modal="true" aria-labelledby="modal-events-title" aria-hidden="true">
        <div class="modal-header">
            <h3 id="modal-events-title" tabindex="-1">30-Day Events Radar</h3>
            <button type="button" class="close-overview-modal" aria-label="Close events radar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Event Details</th>
                        <th>Venue</th>
                    </tr>
                </thead>
                <tbody id="modal-events-tbody"></tbody>
            </table>
        </div>
    </div>

    <!-- Maintenance Detail Modal -->
    <div class="overview-modal modal-maint-detail" id="modal-maintenance-detail" role="dialog" aria-modal="true" aria-labelledby="modal-maintenance-title" aria-hidden="true">
        <div class="maint-modal-header">
            <h3 class="maint-modal-title" id="modal-maintenance-title" tabindex="-1">
                <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Maintenance Details
            </h3>
            <button type="button" class="close-overview-modal btn-icon-close" aria-label="Close maintenance details"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div>
        <div>
            <div class="summary-grid maint-summary-grid">
                <span class="label maint-label">Affected Venue:</span>
                <strong id="md-venue" class="maint-venue-value">--</strong>

                <span class="label maint-label">Maintenance Type:</span>
                <div><span id="md-type" class="status-badge status-refunded maint-type-badge">--</span></div>

                <span class="label maint-label">Schedule Dates:</span>
                <span id="md-dates" class="maint-dates-value">--</span>
            </div>

            <div class="maint-notes-box">
                <strong class="maint-notes-title">Notes / Issues:</strong>
                <p id="md-notes" class="maint-notes-text">--</p>
            </div>
        </div>
        <div class="modal-actions maint-modal-footer">
            <button type="button" class="btn-modal btn-modal-cancel close-overview-modal btn-modal-padded">Close</button>
            <a href="admin_dashboard.php?page=maintenance" class="btn-modal btn-manage-dark">
                <i class="fa-solid fa-screwdriver-wrench"></i> Manage Maintenance
            </a>
        </div>
    </div>

    <!-- In-Place Booking Details Modal -->
    <div class="overview-modal modal-overview-booking" id="overviewBookingModal" role="dialog" aria-modal="true" aria-labelledby="ov-vd-title" aria-hidden="true">
        <div class="overview-booking-header">
            <div class="overview-booking-header-left">
                <h3 class="modal-main-title overview-booking-main-title" id="ov-vd-title" tabindex="-1">Booking Details</h3>
                <span class="status-badge" id="ov-vd-status-badge">--</span>
            </div>
            <button type="button" class="close-overview-modal btn-icon-close" aria-label="Close booking details"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div>

        <div>
            <h4 class="modal-subtitle overview-subtitle-first">Customer Information</h4>
            <div class="summary-grid overview-grid-info">
                <span class="label">Name:</span> <span class="value" id="ov-vd-customer-name">--</span>
                <span class="label">Email:</span> <span class="value" id="ov-vd-customer-email">--</span>
                <span class="label">Phone:</span> <span class="value" id="ov-vd-customer-phone">--</span>
            </div>

            <h4 class="modal-subtitle overview-subtitle">Reservation Details</h4>
            <div class="summary-grid overview-grid-info">
                <span class="label">Venue:</span> <span class="value" id="ov-vd-venue">--</span>
                <span class="label">Dates:</span> <span class="value" id="ov-vd-dates">--</span>
                <span class="label">Guests:</span> <span class="value" id="ov-vd-guests">--</span>
                <span class="label hidden-element" id="ov-vd-specific-label">Specifics:</span>
                <span class="value hidden-element" id="ov-vd-specific-value">--</span>
            </div>

            <section id="ov-vd-refund-section" class="booking-detail-section" aria-labelledby="ov-vd-refund-title" hidden>
                <h4 class="modal-subtitle overview-subtitle" id="ov-vd-refund-title">Refund request</h4>
                <div class="booking-detail-grid">
                    <span>Status</span><strong id="ov-vd-refund-status">—</strong>
                    <span>Request reason</span><strong id="ov-vd-refund-reason">—</strong>
                    <span>Resort reply</span><strong id="ov-vd-refund-reply">—</strong>
                    <span>Refund amount</span><strong id="ov-vd-refund-amount">—</strong>
                    <span>Destination method</span><strong id="ov-vd-refund-method">—</strong>
                    <span>Account holder</span><strong id="ov-vd-refund-account-name">—</strong>
                    <span>Wallet / account number</span><strong id="ov-vd-refund-account-identifier">—</strong>
                    <span id="ov-vd-refund-bank-label" hidden>Bank</span><strong id="ov-vd-refund-bank" hidden>—</strong>
                    <span id="ov-vd-refund-tx-label" hidden>Outbound transaction ID</span><strong id="ov-vd-refund-tx-value" hidden>—</strong>
                </div>
            </section>

            <section id="ov-vd-payment-history-section" class="admin-payment-history-section" hidden>
                <h4 class="modal-subtitle overview-subtitle">Payment history</h4>
                <div id="ov-vd-payment-history" class="admin-payment-history-list"></div>
            </section>
            <details id="ov-vd-proof-history" class="admin-proof-history" hidden>
                <summary>
                    <span class="admin-proof-history-summary-title">Submitted proof history <span class="admin-proof-history-count" id="ov-vd-proof-history-count"></span></span>
                    <span class="admin-proof-history-summary-action">
                        <span class="admin-proof-history-action-open">View proofs</span>
                        <span class="admin-proof-history-action-close">Hide proofs</span>
                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                    </span>
                </summary>
                <div id="ov-vd-proof-history-list" class="admin-proof-history-list"></div>
            </details>

            <div id="ov-vd-addons-container" class="hidden-element">
                <h4 class="modal-subtitle overview-subtitle">Add-ons & Line Items</h4>
                <div class="summary-grid overview-grid-financials" id="ov-vd-addons-list"></div>
            </div>

            <h4 class="modal-subtitle overview-subtitle">Financial Breakdown</h4>
            <div class="summary-grid overview-grid-financials">
                <span class="label">Base Amount:</span> <span class="value" id="ov-vd-base-amt">₱0.00</span>
                <span class="label">Add-ons Amount:</span> <span class="value" id="ov-vd-addons-amt">₱0.00</span>
                <span class="label">Extra Pax Amount:</span> <span class="value" id="ov-vd-extrapax-amt">₱0.00</span>
            </div>

            <div class="refund-total overview-total-box">
                <span class="label overview-total-label">Total Amount:</span>
                <span class="value amount overview-total-value" id="ov-vd-total-amt">₱0.00</span>
            </div>

            <div class="summary-grid overview-grid-financials">
                <span class="label">Payment Scheme:</span> <span class="value" id="ov-vd-scheme">--</span>
                <span class="label">Amount Paid:</span> <span class="value overview-paid-value" id="ov-vd-paid-amt">₱0.00</span>
            </div>
        </div>

        <div class="modal-actions maint-modal-footer">
            <button type="button" class="btn-modal btn-modal-cancel close-overview-modal btn-modal-padded">Close</button>
            <a id="ov-btn-manage-link" href="admin_dashboard.php?page=bookings" class="btn-modal btn-manage-dark">
                <i class="fa-solid fa-arrow-right-to-bracket"></i> Manage in Bookings
            </a>
        </div>
    </div>
</div>
