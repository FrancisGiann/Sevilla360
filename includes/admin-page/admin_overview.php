<!-- Dashboard Content -->
<div class="dashboard-container">

    <!-- NEW: Quick Actions Header -->
    <div class="dashboard-header-bar">
        <h2>Command Center</h2>
        <div class="quick-actions">
            <!-- This button is how you "Make a Major Event" manually -->
            <a href="admin_dashboard.php?page=walkin" class="btn-quick-action"><i class="fa-solid fa-plus"></i> Walk-in
                / Event</a>
            <a href="admin_dashboard.php?page=bookings" class="btn-quick-action outline"><i
                    class="fa-solid fa-calendar"></i> Master Calendar</a>
        </div>
    </div>

    <!-- NEW: Maintenance Alerts Injection Point -->
    <div id="maintenance-alerts-container"></div>

    <!-- Top Stats Row (Updated with Action Required & Occupancy) -->
    <div class="stats-grid">
        <div class="stat-card">
            <h4>Monthly Revenue</h4>
            <span class="stat-number color-green" id="stat-monthly-revenue">₱0.00</span>
        </div>
        <div class="stat-card" style="cursor: pointer;"
            onclick="window.location.href='admin_dashboard.php?page=bookings';">
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
        <div class="chart-card bar-card">
            <h3>Revenue Trend</h3>
            <div class="canvas-wrapper"><canvas id="revenueChart"></canvas></div>
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
</div>