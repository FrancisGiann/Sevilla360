<!-- Dashboard Content -->
<div class="dashboard-container">

    <!-- NEW: Quick Actions Header -->
    <div class="dashboard-header-bar">
        <h2>Command Center</h2>
        <div class="quick-actions">
            <!-- This button is how you "Make a Major Event" manually -->
            <a href="admin_dashboard.php?page=walkin" class="btn-quick-action"><i class="fa-solid fa-plus"></i> Walk-in
                / Event</a>

            <!-- FIX: Changed page=bookings to page=calendar -->
            <a href="admin_dashboard.php?page=calendar" class="btn-quick-action outline"><i
                    class="fa-solid fa-calendar"></i> Master Calendar</a>
        </div>
    </div>

    <!-- NEW: Maintenance Alerts Injection Point -->
    <div id="maintenance-alerts-container"></div>

    <!-- Top Stats Row (Updated with Action Required & Occupancy) -->
    <div class="stats-grid">
        <div class="stat-card">
            <h4>Monthly Revenue <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'staff'): ?><i class="fa-solid fa-lock" style="font-size: 0.8rem; color: #a3a3a3;" title="Restricted to Admins"></i><?php endif; ?></h4>
            <span class="stat-number color-green" id="stat-monthly-revenue">
                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'staff'): ?>
                    <span style="font-size: 1.1rem; color: #a3a3a3; font-style: italic;">Restricted</span>
                <?php else: ?>
                    ₱0.00
                <?php endif; ?>
            </span>
        </div>

        <!-- FIX: Added &filter=action_req to the URL -->
        <div class="stat-card" style="cursor: pointer;"
            onclick="window.location.href='admin_dashboard.php?page=bookings&filter=action_req';">
            <h4>Action Required</h4>
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <span class="stat-number color-red" id="stat-action-req">0</span>
                <i class="fa-solid fa-arrow-right color-red" style="opacity: 0.5;"></i>
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

    <!-- Middle Charts Grid -->
    <div class="charts-grid-2">
        <div class="chart-card bar-card" style="position: relative;">
            <h3>Revenue Trend <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'staff'): ?><span style="font-size: 0.75rem; color: #888; font-weight: normal;">(Admin Only)</span><?php endif; ?></h3>
            <div class="canvas-wrapper" id="revenueChartContainer">
                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'staff'): ?>
                    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #888; text-align: center; padding: 20px;">
                        <i class="fa-solid fa-lock-keyhole" style="font-size: 2rem; margin-bottom: 10px; color: var(--color-gold);"></i>
                        <p style="font-size: 0.9rem; font-weight: 500;">Financial chart restricted to Admin accounts.</p>
                    </div>
                <?php else: ?>
                    <canvas id="revenueChart"></canvas>
                <?php endif; ?>
            </div>
        </div>
        <div class="chart-card">
            <h3>Booking Pipeline</h3>
            <div class="canvas-wrapper"><canvas id="statusChart"></canvas></div>
        </div>
    </div>

    <!-- Operational Widgets (Compact Boxes) -->
    <div class="operations-grid">
        <!-- Widget 1: Today -->
        <div class="widget-card">
            <div class="widget-header">
                <h3><i class="fa-solid fa-bell" style="color: var(--color-gold);"></i> Today's Itinerary</h3>
                <button class="view-all-text btn-open-modal" data-target="modal-today">View All</button>
            </div>
            <div class="widget-list" id="widget-today-list">
                <p style="text-align:center; padding: 10px; color: #888;">Loading...</p>
            </div>
        </div>

        <!-- Widget 2: Events -->
        <div class="widget-card">
            <div class="widget-header">
                <h3><i class="fa-solid fa-calendar-star" style="color: var(--color-gold);"></i> Major Events Radar</h3>
                <button class="view-all-text btn-open-modal" data-target="modal-events">View All</button>
            </div>
            <div class="widget-list" id="widget-events-list">
                <p style="text-align:center; padding: 10px; color: #888;">Loading...</p>
            </div>
        </div>
    </div>

    <!-- Bottom Table: Recent Bookings -->
    <div class="table-card" style="margin-top: 30px;">
        <div class="table-header">
            <h3>Recent Bookings</h3>
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
                        <td colspan="5" style="text-align: center;">Loading...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ================= MODALS ================= -->
<div class="overview-modal-overlay" id="overviewModalOverlay">

    <!-- Today's Itinerary Full Modal -->
    <div class="overview-modal" id="modal-today">
        <div class="modal-header">
            <h3>Today's Complete Itinerary</h3>
            <button class="close-overview-modal"><i class="fa-solid fa-xmark"></i></button>
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

    <!-- Events Full Modal -->
    <div class="overview-modal" id="modal-events">
        <div class="modal-header">
            <h3>30-Day Events Radar</h3>
            <button class="close-overview-modal"><i class="fa-solid fa-xmark"></i></button>
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

    <!-- Maintenance Alert Detail Modal -->
    <div class="overview-modal" id="modal-maintenance-detail" style="max-width: 520px; padding: 35px 30px;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 15px; margin-bottom: 20px;">
            <h3 style="color: #dc2626; margin: 0; font-size: 1.3rem; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-triangle-exclamation"></i> Maintenance Details
            </h3>
            <button class="close-overview-modal" style="background: none; border: none; font-size: 1.2rem; cursor: pointer; color: #888;"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div>
            <div class="summary-grid" style="grid-template-columns: 140px 1fr; row-gap: 12px; margin-bottom: 20px;">
                <span class="label" style="font-weight: 600; color: #555;">Affected Venue:</span>
                <strong id="md-venue" style="font-size: 1.1rem; color: var(--color-dark);">--</strong>

                <span class="label" style="font-weight: 600; color: #555;">Maintenance Type:</span>
                <div><span id="md-type" class="status-badge status-refunded" style="font-size: 0.85rem; background: #fee2e2; color: #dc2626; padding: 4px 10px; border-radius: 4px; font-weight: 600;">--</span></div>

                <span class="label" style="font-weight: 600; color: #555;">Schedule Dates:</span>
                <span id="md-dates" style="font-weight: 500;">--</span>
            </div>

            <div style="background: #faf9f7; padding: 15px; border-radius: 6px; border: 1px dashed #ccc; margin-top: 15px;">
                <strong style="display: block; margin-bottom: 6px; font-size: 0.85rem; color: #888; text-transform: uppercase; letter-spacing: 0.05em;">Notes / Issues:</strong>
                <p id="md-notes" style="margin: 0; font-size: 0.95rem; color: #333; line-height: 1.5;">--</p>
            </div>
        </div>
        <div class="modal-actions" style="margin-top: 25px; padding-top: 15px; border-top: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
            <button class="btn-modal btn-modal-cancel close-overview-modal" style="padding: 10px 22px; font-size: 0.85rem;">Close</button>
            <a href="admin_dashboard.php?page=maintenance" class="btn-modal" style="background: var(--color-dark); color: #fff; text-decoration: none; padding: 10px 22px; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-screwdriver-wrench"></i> Manage Maintenance
            </a>
        </div>
    </div>

    <!-- Booking View Details Modal (Embedded on Overview) -->
    <div class="overview-modal" id="overviewBookingModal" style="max-width: 600px; padding: 35px 30px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 15px;">
            <div style="display: flex; align-items: center; gap: 15px;">
                <h3 class="modal-main-title" id="ov-vd-title" style="margin-bottom: 0;">Booking Details</h3>
                <span class="status-badge" id="ov-vd-status-badge">--</span>
            </div>
            <button class="close-overview-modal" style="background: none; border: none; font-size: 1.2rem; cursor: pointer; color: #888;"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div>
            <!-- Customer Info -->
            <h4 class="modal-subtitle" style="font-size: 1.05rem; border-bottom: 1px solid #eee; padding-bottom: 5px; color: var(--color-dark); margin-top: 0;">
                Customer Information</h4>
            <div class="summary-grid" style="grid-template-columns: 130px 1fr; margin-bottom: 20px; row-gap: 8px;">
                <span class="label">Name:</span> <span class="value" id="ov-vd-customer-name">--</span>
                <span class="label">Email:</span> <span class="value" id="ov-vd-customer-email">--</span>
                <span class="label">Phone:</span> <span class="value" id="ov-vd-customer-phone">--</span>
            </div>

            <!-- Booking Info -->
            <h4 class="modal-subtitle" style="font-size: 1.05rem; border-bottom: 1px solid #eee; padding-bottom: 5px; color: var(--color-dark);">
                Reservation Details</h4>
            <div class="summary-grid" style="grid-template-columns: 130px 1fr; margin-bottom: 20px; row-gap: 8px;">
                <span class="label">Venue:</span> <span class="value" id="ov-vd-venue">--</span>
                <span class="label">Dates:</span> <span class="value" id="ov-vd-dates">--</span>
                <span class="label">Guests:</span> <span class="value" id="ov-vd-guests">--</span>
                <span class="label" id="ov-vd-specific-label" style="display:none;">Specifics:</span>
                <span class="value" id="ov-vd-specific-value" style="display:none;">--</span>
                <span class="label" id="ov-vd-transaction-label" style="display:none;">Transaction Ref:</span>
                <span class="value" id="ov-vd-transaction-value" style="display:none; font-family: monospace;">--</span>
            </div>

            <!-- Add-ons Container -->
            <div id="ov-vd-addons-container" style="display: none; margin-bottom: 20px;">
                <h4 class="modal-subtitle" style="font-size: 1.05rem; border-bottom: 1px solid #eee; padding-bottom: 5px; color: var(--color-dark);">Add-ons & Line Items</h4>
                <div class="summary-grid" id="ov-vd-addons-list" style="grid-template-columns: 1fr auto; row-gap: 8px;"></div>
            </div>

            <!-- Financials -->
            <h4 class="modal-subtitle" style="font-size: 1.05rem; border-bottom: 1px solid #eee; padding-bottom: 5px; color: var(--color-dark);">
                Financial Breakdown</h4>
            <div class="summary-grid" style="grid-template-columns: 1fr auto; margin-bottom: 10px; row-gap: 8px;">
                <span class="label">Base Amount:</span> <span class="value" id="ov-vd-base-amt">₱0.00</span>
                <span class="label">Add-ons Amount:</span> <span class="value" id="ov-vd-addons-amt">₱0.00</span>
                <span class="label">Extra Pax Amount:</span> <span class="value" id="ov-vd-extrapax-amt">₱0.00</span>
            </div>

            <div class="refund-total" style="margin-top: 10px; padding: 12px 15px; background: #faf9f7; border-radius: 6px; border: 1px dashed #ccc; display: flex; justify-content: space-between; align-items: center;">
                <span class="label" style="font-size: 1.1rem; font-weight: 600;">Total Amount:</span>
                <span class="value amount" id="ov-vd-total-amt" style="color: var(--color-gold); font-size: 1.2rem; font-weight: 700;">₱0.00</span>
            </div>

            <div class="summary-grid" style="grid-template-columns: 1fr auto; margin-bottom: 0; margin-top: 12px; row-gap: 8px;">
                <span class="label">Payment Scheme:</span> <span class="value" id="ov-vd-scheme">--</span>
                <span class="label">Amount Paid:</span> <span class="value" id="ov-vd-paid-amt" style="color: #4ade80; font-weight: 600;">₱0.00</span>
            </div>
        </div>

        <div class="modal-actions" style="margin-top: 25px; padding-top: 15px; border-top: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
            <button class="btn-modal btn-modal-cancel close-overview-modal" style="padding: 10px 22px; font-size: 0.85rem;">Close</button>
            <a id="ov-btn-manage-link" href="admin_dashboard.php?page=bookings" class="btn-modal" style="background: var(--color-dark); color: #fff; text-decoration: none; padding: 10px 22px; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-arrow-right-to-bracket"></i> Manage in Bookings
            </a>
        </div>
    </div>
</div>