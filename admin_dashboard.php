<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SEVILLA360 - Admin Dashboard</title>

    <!-- Fonts & Icons -->
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Playfair+Display:ital,wght@0,400;0,500;0,600;1,400&family=Great+Vibes&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!-- Stylesheets -->
    <link rel="stylesheet" href="assets/css/style.css"> <!-- Your master styles -->
    <link rel="stylesheet" href="assets/css/admin_dashboard.css"> <!-- Admin specific styles -->
</head>

<body class="admin-body">

    <div class="admin-layout">

        <!-- Left Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <a href="#" class="navbar-brand">SEVILLA360</a>
                <span class="admin-badge">ADMIN</span>
            </div>

            <nav class="sidebar-nav">
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="#" class="nav-link active"><i class="fa-solid fa-chart-pie"></i> Overview</a>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link"><i class="fa-solid fa-calendar-check"></i> Bookings</a>
                    </li>
                    <li class="nav-item">
                        <a href="booking_admin.php" class="nav-link"><i
                                class="fa-solid fa-person-walking-arrow-right"></i>
                            Walk-in
                            Entry</a>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link"><i class="fa-solid fa-screwdriver-wrench"></i> Maintenance</a>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link"><i class="fa-solid fa-gear"></i> Settings</a>
                    </li>
                </ul>
            </nav>

            <div class="sidebar-footer">
                <a href="#" class="nav-link sign-out"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out</a>
            </div>
        </aside>

        <!-- Main Content Area -->
        <main class="main-content">

            <!-- Top Header -->
            <header class="admin-header">
                <h2 class="page-title">Dashboard Overview</h2>
                <div class="header-actions">
                    <a href="index.html" class="btn-back"><i class="fa-solid fa-house"></i> Back to Home</a>
                    <div class="admin-profile">
                        <i class="fa-solid fa-circle-user profile-icon"></i>
                    </div>
                </div>
            </header>

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
        </main>

    </div>

    <!-- Admin JS logic -->
    <script src="assets/js/admin_dashboard.js"></script>
</body>

</html>