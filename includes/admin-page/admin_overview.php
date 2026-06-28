<!-- Dashboard Content -->
<div class="dashboard-container">

    <!-- Top Stats Row -->
    <div class="stats-grid">
        <div class="stat-card">
            <h4>Bookings Today</h4>
            <span class="stat-number color-gold">12</span>
        </div>
        <div class="stat-card">
            <h4>Monthly Revenue</h4>
            <span class="stat-number color-green">$24,500</span>
        </div>
        <div class="stat-card">
            <h4>Pending Items</h4>
            <span class="stat-number color-red">5</span>
        </div>
        <div class="stat-card">
            <h4>Room Occupancy</h4>
            <span class="stat-number color-dark">85%</span>
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
            <a href="#" class="view-all">View All</a>
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
                <tbody>
                    <tr>
                        <td>#SV-9021</td>
                        <td>The Grand Hall</td>
                        <td>Oct 24, 2024</td>
                        <td>$3,200</td>
                        <td><span class="badge badge-confirmed">Confirmed</span></td>
                    </tr>
                    <tr>
                        <td>#SV-9022</td>
                        <td>Garden Pavilion</td>
                        <td>Oct 25, 2024</td>
                        <td>$1,500</td>
                        <td><span class="badge badge-pending">Pending</span></td>
                    </tr>
                    <tr>
                        <td>#SV-9023</td>
                        <td>Studio A</td>
                        <td>Oct 26, 2024</td>
                        <td>$800</td>
                        <td><span class="badge badge-confirmed">Confirmed</span></td>
                    </tr>
                    <tr>
                        <td>#SV-9024</td>
                        <td>The Grand Hall</td>
                        <td>Nov 02, 2024</td>
                        <td>$3,200</td>
                        <td><span class="badge badge-cancelled">Cancelled</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

</div>