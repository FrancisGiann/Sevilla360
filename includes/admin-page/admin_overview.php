<!-- Main Dashboard Overview Container -->
<div class="dashboard-container">

    <!-- Top Bar & Header Controls -->
    <div class="dashboard-header-bar">
        <h2>Command Center</h2>
        <div class="quick-actions">
            <a href="admin_dashboard.php?page=walkin" class="btn-quick-action"><i class="fa-solid fa-plus"></i> Walk-in / Event</a>
            <a href="admin_dashboard.php?page=calendar" class="btn-quick-action outline"><i class="fa-solid fa-calendar"></i> Master Calendar</a>
        </div>
    </div>

    <!-- Maintenance Alerts Container -->
    <div id="maintenance-alerts-container"></div>

    <!-- Key Performance Metrics Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <h4>Monthly Revenue <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'staff'): ?><i class="fa-solid fa-lock stat-lock-icon" title="Restricted to Admins"></i><?php endif; ?></h4>
            <span class="stat-number color-green" id="stat-monthly-revenue">
                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'staff'): ?>
                    <span class="stat-restricted-text">Restricted</span>
                <?php else: ?>
                    ₱0.00
                <?php endif; ?>
            </span>
        </div>

        <div class="stat-card clickable" onclick="window.location.href='admin_dashboard.php?page=bookings&filter=action_req';">
            <h4>Action Required</h4>
            <div class="stat-card-row">
                <span class="stat-number color-red" id="stat-action-req">0</span>
                <i class="fa-solid fa-arrow-right color-red stat-arrow-icon"></i>
            </div>
        </div>

        <div class="stat-card">
            <h4>Arrivals Today</h4>
            <span class="stat-number color-gold" id="stat-arrivals-today">0</span>
        </div>
        <div class="stat-card">
            <h4>Today's Occupancy</h4>
            <span class="stat-number color-dark" id="stat-occupancy-rate">0%</span>
        </div>
    </div>

    <!-- Charts Section (Revenue & Venue Distribution) -->
    <div class="charts-grid-2">
        <div class="chart-card bar-card chart-card-relative">
            <h3>Revenue Trend <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'staff'): ?><span class="admin-only-badge">(Admin Only)</span><?php endif; ?></h3>
            <div class="canvas-wrapper" id="revenueChartContainer">
                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'staff'): ?>
                    <div class="chart-restricted-placeholder">
                        <i class="fa-solid fa-lock-keyhole chart-restricted-icon"></i>
                        <p class="chart-restricted-title">Financial Data Restricted</p>
                        <p class="chart-restricted-sub">Revenue trend charts are visible to administrators only.</p>
                    </div>
                <?php else: ?>
                    <canvas id="revenueChart"></canvas>
                <?php endif; ?>
            </div>
        </div>

        <div class="chart-card donut-card">
            <h3>Venue Bookings Distribution</h3>
            <div class="canvas-wrapper">
                <canvas id="venueDistributionChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Today's Operations & Major Events Widgets -->
    <div class="widgets-grid">
        <div class="widget-card">
            <div class="widget-header">
                <h3>Today's Itinerary</h3>
                <span class="view-all" id="btn-view-all-today">View All</span>
            </div>
            <div class="widget-list" id="widget-todays-list">
                <p class="tba-text">Loading today's schedule...</p>
            </div>
        </div>

        <div class="widget-card">
            <div class="widget-header">
                <h3>Major Events Radar (30 Days)</h3>
                <span class="view-all" id="btn-view-all-events">View All</span>
            </div>
            <div class="widget-list" id="widget-events-list">
                <p class="tba-text">Loading upcoming major events...</p>
            </div>
        </div>
    </div>

    <!-- Recent Bookings Table -->
    <div class="table-card">
        <div class="table-header">
            <h3>Recent Bookings</h3>
            <a href="admin_dashboard.php?page=bookings" class="view-all-link">Manage All Bookings &rarr;</a>
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
                    </tr>
                </thead>
                <tbody id="recent-bookings-tbody">
                    <tr>
                        <td colspan="6" class="tba-text">Loading recent bookings...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Overview Overlay Modals -->
<div class="modal-overlay" id="overviewModalOverlay">

    <!-- Today's Itinerary Full View Modal -->
    <div class="admin-modal overview-modal" id="modalTodayFull">
        <div class="maint-modal-header">
            <h3 class="modal-main-title vd-title">Today's Itinerary & Operations</h3>
            <button class="close-modal modal-close-btn">&times;</button>
        </div>
        <div class="table-responsive">
            <table class="bookings-table">
                <thead>
                    <tr>
                        <th>TIME / TYPE</th>
                        <th>UNIT / VENUE</th>
                        <th>GUEST / DETAILS</th>
                        <th>STATUS</th>
                    </tr>
                </thead>
                <tbody id="modal-today-tbody"></tbody>
            </table>
        </div>
    </div>

    <!-- Major Events Radar Full View Modal -->
    <div class="admin-modal overview-modal" id="modalEventsFull">
        <div class="maint-modal-header">
            <h3 class="modal-main-title vd-title">30-Day Major Events Radar</h3>
            <button class="close-modal modal-close-btn">&times;</button>
        </div>
        <div class="table-responsive">
            <table class="bookings-table">
                <thead>
                    <tr>
                        <th>DATE</th>
                        <th>EVENT NAME</th>
                        <th>VENUE</th>
                        <th>GUEST COUNT</th>
                        <th>STATUS</th>
                    </tr>
                </thead>
                <tbody id="modal-events-tbody"></tbody>
            </table>
        </div>
    </div>

    <div class="overview-modal modal-maint-detail" id="modal-maintenance-detail">
        <div class="maint-modal-header">
            <h3 class="maint-modal-title">
                <i class="fa-solid fa-triangle-exclamation"></i> Maintenance Details
            </h3>
            <button class="close-overview-modal btn-icon-close"><i class="fa-solid fa-xmark"></i></button>
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
            <button class="btn-modal btn-modal-cancel close-overview-modal btn-modal-padded">Close</button>
            <a href="admin_dashboard.php?page=maintenance" class="btn-modal btn-manage-dark">
                <i class="fa-solid fa-screwdriver-wrench"></i> Manage Maintenance
            </a>
        </div>
    </div>

    <div class="overview-modal modal-overview-booking" id="overviewBookingModal">
        <div class="overview-booking-header">
            <div class="overview-booking-header-left">
                <h3 class="modal-main-title overview-booking-main-title" id="ov-vd-title">Booking Details</h3>
                <span class="status-badge" id="ov-vd-status-badge">--</span>
            </div>
            <button class="close-overview-modal btn-icon-close"><i class="fa-solid fa-xmark"></i></button>
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
                <span class="label hidden-element" id="ov-vd-transaction-label">Transaction Ref:</span>
                <span class="value hidden-element-mono" id="ov-vd-transaction-value">--</span>
            </div>

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
            <button class="btn-modal btn-modal-cancel close-overview-modal btn-modal-padded">Close</button>
            <a id="ov-btn-manage-link" href="admin_dashboard.php?page=bookings" class="btn-modal btn-manage-dark">
                <i class="fa-solid fa-arrow-right-to-bracket"></i> Manage in Bookings
            </a>
        </div>
    </div>
</div>