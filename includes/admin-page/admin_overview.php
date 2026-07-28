<!-- Dashboard Content -->
<div class="dashboard-container">

    <!-- Top Stats Row -->
    <div class="stats-grid">
        <div class="stat-card">
            <h4>Bookings Today</h4>
            <span class="stat-number color-gold" id="stat-bookings-today">0</span>
        </div>
        <div class="stat-card">
            <h4>Monthly Revenue</h4>
            <span class="stat-number color-green" id="stat-monthly-revenue">₱0.00</span>
        </div>
        <div class="stat-card">
            <h4>Pending Items</h4>
            <span class="stat-number color-red" id="stat-pending-items">0</span>
        </div>
        <div class="stat-card">
            <h4>Room Occupancy</h4>
            <span class="stat-number color-dark" id="stat-occupancy-rate">0%</span>
        </div>
    </div>

    <!-- Middle Charts Grid -->
    <div class="charts-grid">
        <div class="chart-card bar-card">
            <h3>Revenue Trend</h3>
            <div class="canvas-wrapper">
                <canvas id="revenueChart"></canvas>
            </div>
        </div>
        <div class="chart-card">
            <h3>Booking Status</h3>
            <div class="canvas-wrapper">
                <canvas id="statusChart"></canvas>
            </div>
        </div>
        <div class="chart-card">
            <h3>Occupancy by Area</h3>
            <div class="canvas-wrapper">
                <canvas id="occupancyChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Bottom Table Row -->
    <div class="table-card">
        <div class="table-header">
            <h3>Recent Bookings</h3>
            <a href="admin_dashboard.php?page=bookings" class="view-all">View All</a>
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
                    <!-- Data will be dynamically injected here by JS -->
                    <tr>
                        <td colspan="5" style="text-align: center; color: var(--color-dark-light);">Loading recent
                            bookings...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

</div>