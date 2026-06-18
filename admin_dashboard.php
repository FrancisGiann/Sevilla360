<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - SEVILLA360</title>

    <!-- External Fonts -->
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Playfair+Display:ital,wght@0,400;0,500;0,600;1,400&family=Great+Vibes&display=swap"
        rel="stylesheet">

    <!-- Master Stylesheets -->
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/booking.css">

    <!-- Admin Specific Styles -->
    <link rel="stylesheet" href="assets/css/admin_dashboard.css">

    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body class="admin-body">

    <div class="admin-layout">

        <!-- ================= SIDEBAR ================= -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h1 class="admin-brand">Sevilla360 <span class="admin-badge">ADMIN</span></h1>
            </div>
            <nav class="sidebar-nav">
                <a href="#overview" class="nav-item active" data-target="view-overview">Overview</a>
                <a href="#bookings" class="nav-item" data-target="view-bookings">Bookings</a>
                <a href="#walk-in" class="nav-item" data-target="view-walkin">Walk-in Entry</a>
                <a href="#maintenance" class="nav-item" data-target="view-maintenance">Maintenance</a>
                <a href="#settings" class="nav-item" data-target="view-settings">Settings</a>
            </nav>
            <div class="sidebar-footer">
                <a href="login.php" class="nav-item sign-out">Sign out</a>
            </div>
        </aside>

        <!-- ================= MAIN CONTENT ================= -->
        <main class="main-content">

            <!-- TOP HEADER -->
            <header class="top-header">
                <h2 id="page-title" class="page-title">Dashboard Overview</h2>
                <div class="top-actions">
                    <a href="index.php" class="btn btn-outline dark-outline">View Site</a>
                    <div class="admin-profile">
                        <div class="profile-icon">A</div>
                        <span>Admin User</span>
                    </div>
                </div>
            </header>

            <!-- VIEW 1: OVERVIEW -->
            <section id="view-overview" class="view-section active">
                <!-- Stats Row -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <h5>Bookings Today</h5>
                        <h3 class="stat-gold">12</h3>
                    </div>
                    <div class="stat-card">
                        <h5>Monthly Revenue</h5>
                        <h3 class="stat-green">₱ 450,000</h3>
                    </div>
                    <div class="stat-card">
                        <h5>Pending Payment</h5>
                        <h3 class="stat-red">5</h3>
                    </div>
                    <div class="stat-card">
                        <h5>Room Occupancy</h5>
                        <h3 class="stat-black">85%</h3>
                    </div>
                </div>

                <!-- Charts Grid -->
                <div class="charts-grid">
                    <div class="chart-card chart-large">
                        <h4>Revenue Trend</h4>
                        <canvas id="revenueChart"></canvas>
                    </div>
                    <div class="chart-card chart-small">
                        <h4>Booking Status</h4>
                        <canvas id="statusChart"></canvas>
                    </div>
                    <div class="chart-card chart-small">
                        <h4>Occupancy by Area</h4>
                        <canvas id="occupancyChart"></canvas>
                    </div>
                </div>

                <!-- Recent Bookings Table -->
                <div class="table-card">
                    <h4>Recent Bookings</h4>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Booking ID</th>
                                <th>Customer</th>
                                <th>Venue</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>#SVL-1042</td>
                                <td>Maria Clara</td>
                                <td>Grand Ballroom</td>
                                <td>Oct 15, 2024</td>
                                <td>₱ 150,000</td>
                                <td><span class="status-pill pill-paid">Paid</span></td>
                            </tr>
                            <tr>
                                <td>#SVL-1043</td>
                                <td>Juan Dela Cruz</td>
                                <td>VIP Suite</td>
                                <td>Oct 16, 2024</td>
                                <td>₱ 8,500</td>
                                <td><span class="status-pill pill-pending">Pending</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- VIEW 2: BOOKINGS -->
            <section id="view-bookings" class="view-section">
                <div class="controls-row">
                    <input type="text" class="admin-input search-bar" placeholder="Search ID, Name, or Venue...">
                    <select class="admin-select">
                        <option>All Venues</option>
                        <option>Event Hall</option>
                        <option>Hotel Rooms</option>
                        <option>Resort Villa</option>
                    </select>
                    <select class="admin-select">
                        <option>This Month</option>
                        <option>Next Month</option>
                        <option>All Time</option>
                    </select>
                </div>

                <div class="filters-row">
                    <button class="pill-btn active">All</button>
                    <button class="pill-btn">Pending</button>
                    <button class="pill-btn">Paid</button>
                    <button class="pill-btn">Cancelled</button>
                </div>

                <div class="table-card mt-2">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Booking ID</th>
                                <th>Venue</th>
                                <th>Customer</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>#SVL-1044</td>
                                <td>La Casita</td>
                                <td>Leonor Rivera</td>
                                <td>Oct 20, 2024</td>
                                <td>₱ 3,500</td>
                                <td><span class="status-pill pill-pending">Pending</span></td>
                                <td class="actions-cell">
                                    <button class="action-btn btn-confirm" title="Confirm">✔</button>
                                    <button class="action-btn btn-cancel-act" title="Cancel Booking">✖</button>
                                    <button class="action-btn btn-reschedule" title="Reschedule"
                                        onclick="openModal('reschedule-modal')">🗓</button>
                                    <button class="action-btn btn-refund" title="Refund"
                                        onclick="openModal('refund-modal')">₱</button>
                                    <button class="action-btn btn-view" title="View Details">👁</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- VIEW 3: WALK-IN ENTRY -->
            <section id="view-walkin" class="view-section">
                <div class="booking-grid" style="padding: 0; background: transparent;">
                    <!-- LEFT COLUMN -->
                    <div class="booking-main admin-card-bg">
                        <!-- Guest Info Addition -->
                        <h2 class="section-title" style="font-size: 1.8rem;">Walk-In Entry</h2>
                        <div class="admin-form-section">
                            <h4 class="form-section-title">1. Guest Information</h4>
                            <div class="form-row">
                                <div class="form-group"><label>Full Name</label><input type="text"
                                        placeholder="Juan Dela Cruz"></div>
                                <div class="form-group"><label>Contact Number</label><input type="text"
                                        placeholder="09XX XXX XXXX"></div>
                            </div>
                            <div class="form-group"><label>Email Address (Optional)</label><input type="email"
                                    placeholder="juan@example.com"></div>
                        </div>

                        <!-- Booking Tabs -->
                        <div class="admin-form-section mt-2">
                            <h4 class="form-section-title">2. Select Accommodation</h4>
                            <div class="booking-tabs admin-tabs">
                                <button class="tab-btn admin-tab-btn active" data-admintab="admin-event">Event
                                    Hall</button>
                                <button class="tab-btn admin-tab-btn" data-admintab="admin-hotel">Hotel Rooms</button>
                                <button class="tab-btn admin-tab-btn" data-admintab="admin-villa">Resort Villa</button>
                            </div>

                            <div class="tab-content active" id="admin-event">
                                <div class="form-row">
                                    <div class="form-group"><label>Select Venue Space</label><select>
                                            <option>Grand Ballroom</option>
                                        </select></div>
                                    <div class="form-group"><label>Number of Guests</label><input type="number"
                                            placeholder="100"></div>
                                </div>
                                <div class="calendar-ui" style="margin-bottom:0;">
                                    <!-- Dummy admin calendar -->
                                    <div class="cal-header">
                                        <h4 class="cal-month-year">Select Date</h4>
                                    </div>
                                </div>
                            </div>
                            <div class="tab-content" id="admin-hotel">
                                <div class="form-group"><label>Room Type</label><select>
                                        <option>Deluxe Room</option>
                                    </select></div>
                            </div>
                            <div class="tab-content" id="admin-villa">
                                <div class="form-group"><label>Select Villa</label><select>
                                        <option>La Casita</option>
                                    </select></div>
                            </div>
                        </div>

                        <!-- Payment Method Addition -->
                        <div class="admin-form-section mt-2">
                            <h4 class="form-section-title">3. Payment Options</h4>
                            <label class="small-label">PAYMENT METHOD</label>
                            <div class="payment-methods radio-group">
                                <button class="pay-btn active">CASH</button>
                                <button class="pay-btn">GCASH</button>
                                <button class="pay-btn">MAYA</button>
                                <button class="pay-btn">BANK TRANSFER</button>
                            </div>
                            <div class="form-group mt-2" id="ref-no-group" style="display:none;">
                                <label>REFERENCE NO. (IF NON CASH)</label>
                                <input type="text" placeholder="Enter Transaction Reference Number">
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT COLUMN: STICKY SUMMARY -->
                    <div class="booking-sidebar" style="margin-top: 0;">
                        <div class="sticky-summary" style="top: 20px;">
                            <h3
                                style="font-family: var(--font-heading); margin-bottom: 1.5rem; font-size: 1.6rem; border-bottom: 1px solid rgba(0,0,0,0.1); padding-bottom: 10px;">
                                Walk-In Summary</h3>

                            <div class="summary-container active">
                                <p><strong>Service:</strong> <span class="sum-val">Event Hall</span></p>
                                <p><strong>Venue:</strong> <span class="sum-val">Grand Ballroom</span></p>
                                <p><strong>Guest:</strong> <span class="sum-val">Juan Dela Cruz</span></p>
                                <p><strong>Date:</strong> <span class="sum-val">Oct 25, 2024</span></p>
                                <p><strong>Total Due:</strong> <span class="sum-val stat-gold"
                                        style="font-weight:bold; font-size:1.1rem;">₱ 150,000</span></p>
                            </div>

                            <div class="summary-footer">
                                <button class="btn btn-primary" style="width:100%;">CONFIRM WALK-IN BOOKING</button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- VIEW 4: MAINTENANCE -->
            <section id="view-maintenance" class="view-section">
                <div class="maintenance-layout">

                    <div class="maintenance-main">
                        <div class="admin-tabs-text">
                            <button class="m-tab active">Event Hall</button>
                            <button class="m-tab">Resort Villa</button>
                            <button class="m-tab">Standard Room</button>
                            <button class="m-tab">Deluxe Room</button>
                        </div>

                        <div class="calendar-ui full-width-cal mt-2">
                            <div class="cal-header">
                                <button class="cal-nav">&larr;</button>
                                <h4 class="cal-month-year">Maintenance Calendar - Oct 2024</h4>
                                <button class="cal-nav">&rarr;</button>
                            </div>
                            <div class="cal-weekdays">
                                <span>SUN</span><span>MON</span><span>TUE</span><span>WED</span><span>THU</span><span>FRI</span><span>SAT</span>
                            </div>
                            <div class="cal-days-grid" id="maint-cal-grid">
                                <!-- JS Injected -->
                            </div>
                        </div>
                    </div>

                    <div class="maintenance-sidebar">
                        <div class="maint-card">
                            <h3
                                style="font-family: var(--font-heading); margin-bottom: 1.5rem; color: var(--color-dark);">
                                Schedule Maintenance</h3>
                            <div class="form-group">
                                <label>AREA / SPECIFIC LOCATION</label>
                                <input type="text" placeholder="e.g. Garden Pavilion Aircon">
                            </div>
                            <div class="form-group">
                                <label>MAINTENANCE TYPE</label>
                                <select>
                                    <option>General Cleaning</option>
                                    <option>Electrical / Wiring</option>
                                    <option>Plumbing</option>
                                    <option>Pest Control</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>DESCRIPTION / NOTES</label>
                                <textarea rows="3" placeholder="Additional details..."></textarea>
                            </div>

                            <div class="toggle-group mt-2">
                                <label class="switch">
                                    <input type="checkbox" checked>
                                    <span class="slider round"></span>
                                </label>
                                <span>BLOCK THE VENUE FROM NEW BOOKINGS</span>
                            </div>

                            <div class="summary-footer mt-2">
                                <button class="btn btn-primary" style="width:100%; margin-bottom:10px;">SCHEDULE
                                    MAINTENANCE</button>
                                <button class="btn btn-outline dark-outline" style="width:100%;">CLEAR FORM</button>
                            </div>
                        </div>
                    </div>

                </div>
            </section>

        </main>
    </div>

    <!-- ================= MODALS ================= -->

    <!-- Refund Modal -->
    <div class="modal-overlay" id="refund-modal">
        <div class="modal-content admin-modal">
            <h3>Process Refund</h3>
            <div class="modal-body">
                <div class="refund-summary">
                    <p><strong>Customer:</strong> Leonor Rivera</p>
                    <p><strong>Venue:</strong> La Casita</p>
                    <p><strong>Paid Amount:</strong> ₱ 3,500</p>
                    <p><strong>Reason:</strong> Customer Cancellation</p>
                </div>
                <div class="form-group mt-2">
                    <label>Cancellation Fee (%)</label>
                    <input type="number" value="10">
                </div>
                <div class="refund-calc">
                    <h4>Calculated Refund: <span class="stat-red">₱ 3,150</span></h4>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-outline dark-outline" onclick="closeModal('refund-modal')">Cancel</button>
                <button class="btn" style="background:#e06666; color:white;">Process Refund</button>
            </div>
        </div>
    </div>

    <!-- Reschedule Modal -->
    <div class="modal-overlay" id="reschedule-modal">
        <div class="modal-content admin-modal modal-large">
            <h3>Reschedule Booking #SVL-1044</h3>
            <div class="modal-body">
                <!-- Mini Calendar for Rescheduling -->
                <div class="calendar-ui" style="margin-bottom: 20px; padding: 15px;">
                    <div class="cal-header" style="margin-bottom: 10px;">
                        <button class="cal-nav">&larr;</button>
                        <h4 class="cal-month-year" style="font-size: 1.2rem;">Nov 2024</h4>
                        <button class="cal-nav">&rarr;</button>
                    </div>
                    <div class="cal-weekdays">
                        <span>S</span><span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span>
                    </div>
                    <div class="cal-days-grid" id="resched-cal-grid" style="margin-bottom: 10px; gap: 2px;"></div>
                </div>
                <div class="form-group">
                    <label>Reason for Reschedule</label>
                    <textarea rows="2" placeholder="State reason..."></textarea>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-outline dark-outline" onclick="closeModal('reschedule-modal')">Cancel</button>
                <button class="btn" style="background:#3498db; color:white;">Confirm Reschedule</button>
            </div>
        </div>
    </div>

    <script src="assets/js/admin_dashboard.js"></script>
</body>

</html>