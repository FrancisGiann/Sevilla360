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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Master Stylesheets -->
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/booking.css">
    <link rel="stylesheet" href="assets/css/admin_dashboard.css">

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body class="admin-body">

    <!-- TOP HEADER -->
    <header class="admin-header">
        <div class="header-left">
            <h1 class="admin-brand">Sevilla<span style="font-weight:300; opacity: 0.6;">360</span></h1>
            <span class="admin-badge">ADMIN</span>
        </div>
        <div class="header-right">
            <a href="index.php" class="btn-home"><i class="fas fa-home"></i> Back to Home</a>
            <div class="admin-profile-icon"><i class="fas fa-user"></i></div>
        </div>
    </header>

    <div class="admin-layout">

        <!-- SIDEBAR -->
        <aside class="sidebar">
            <nav class="sidebar-nav">
                <a href="#overview" class="nav-item active" data-target="view-overview"><i class="fas fa-chart-pie"></i>
                    OVERVIEW</a>
                <a href="#bookings" class="nav-item" data-target="view-bookings"><i class="fas fa-book"></i>
                    BOOKINGS</a>
                <a href="#walk-in" class="nav-item" data-target="view-walkin"><i class="fas fa-walking"></i> WALK-IN
                    ENTRY</a>
                <a href="#maintenance" class="nav-item" data-target="view-maintenance"><i class="fas fa-tools"></i>
                    MAINTENANCE</a>
                <a href="#settings" class="nav-item" data-target="view-settings"><i class="fas fa-cog"></i> SETTINGS</a>
            </nav>
            <div class="sidebar-footer">
                <a href="login.php" class="nav-item sign-out"><i class="fas fa-sign-out-alt"></i> Sign out</a>
            </div>
        </aside>

        <!-- MAIN CONTENT -->
        <main class="main-content">

            <!-- ================= VIEW 1: OVERVIEW ================= -->
            <section id="view-overview" class="view-section active">
                <h2 class="page-title">Overview</h2>

                <!-- Stats Row -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <h3 class="stat-gold">3</h3>
                        <h5>Bookings Today</h5>
                    </div>
                    <div class="stat-card">
                        <h3 class="stat-green">₱ 100,000</h3>
                        <h5>Monthly Revenue</h5>
                    </div>
                    <div class="stat-card">
                        <h3 class="stat-red">3</h3>
                        <h5>Pending Payment</h5>
                    </div>
                    <div class="stat-card">
                        <h3 class="stat-black">50%</h3>
                        <h5>Room Occupancy</h5>
                    </div>
                </div>

                <div class="overview-beige-wrapper">
                    <div class="charts-row">
                        <div class="chart-card">
                            <h4>Revenue Trend</h4>
                            <div class="chart-container"><canvas id="revenueChart"></canvas></div>
                        </div>
                        <div class="chart-card">
                            <h4>Booking Status</h4>
                            <div class="chart-container"><canvas id="statusChart"></canvas></div>
                        </div>
                        <div class="chart-card">
                            <h4>Occupancy</h4>
                            <div class="chart-container" style="position: relative;">
                                <canvas id="occupancyChart"></canvas>
                                <div class="donut-inner-text">85%</div>
                            </div>
                        </div>
                    </div>

                    <div class="chart-card mt-15">
                        <h4>Recent Bookings</h4>
                        <div class="table-scroll-wrapper">
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
                                        <td>#6767</td>
                                        <td>Event Hall</td>
                                        <td>Apr 20, 2026</td>
                                        <td>₱ 20,000</td>
                                        <td><span class="status-pill pill-green">Paid</span></td>
                                    </tr>
                                    <tr>
                                        <td>#1234</td>
                                        <td>Standard Room</td>
                                        <td>Apr 15, 2026</td>
                                        <td>₱ 4,500</td>
                                        <td><span class="status-pill pill-yellow">Pending</span></td>
                                    </tr>
                                    <tr>
                                        <td>#1235</td>
                                        <td>Deluxe Room</td>
                                        <td>Apr 13-14, 2026</td>
                                        <td>₱ 9,000</td>
                                        <td><span class="status-pill pill-green">Paid</span></td>
                                    </tr>
                                    <tr>
                                        <td>#6712</td>
                                        <td>Resort Villa</td>
                                        <td>Apr 1-7, 2026</td>
                                        <td>₱ 24,500</td>
                                        <td><span class="status-pill pill-red">Cancelled</span></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ================= VIEW 2: BOOKINGS ================= -->
            <section id="view-bookings" class="view-section">
                <h2 class="page-title">Bookings</h2>

                <div class="controls-container">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search by name, id, or venue">
                    </div>
                    <select class="admin-select">
                        <option>All Venues</option>
                    </select>
                    <select class="admin-select">
                        <option>This Month</option>
                    </select>
                </div>

                <div class="table-card">
                    <div class="table-header-row">
                        <h3>Booking History</h3>
                        <div class="filters-row">
                            <button class="pill-btn active">All</button>
                            <button class="pill-btn">Pending</button>
                            <button class="pill-btn">Paid</button>
                            <button class="pill-btn">Cancelled</button>
                        </div>
                    </div>

                    <div class="table-full-scroll">
                        <table class="admin-table main-table">
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
                                    <td>#12312</td>
                                    <td>Event Hall</td>
                                    <td>Francis Empleo</td>
                                    <td>Jun 13, 2026</td>
                                    <td>₱ 20,000</td>
                                    <td><span class="status-pill pill-yellow">Pending</span></td>
                                    <td class="actions-cell">
                                        <button class="action-btn btn-confirm" title="Confirm"><i
                                                class="fas fa-check"></i></button>
                                        <button class="action-btn btn-cancel-act" title="Cancel"><i
                                                class="fas fa-times"></i></button>
                                        <button class="action-btn btn-view" title="Details"><i
                                                class="fas fa-eye"></i></button>
                                    </td>
                                </tr>
                                <tr>
                                    <td>#12313</td>
                                    <td>Standard Room</td>
                                    <td>Lebron James</td>
                                    <td>May 5-6, 2026</td>
                                    <td>₱ 9,000</td>
                                    <td><span class="status-pill pill-green">Paid</span></td>
                                    <td class="actions-cell">
                                        <button class="action-btn btn-reschedule" title="Reschedule"
                                            onclick="openModal('reschedule-modal')"><i
                                                class="fas fa-calendar-alt"></i></button>
                                        <button class="action-btn btn-refund" title="Refund"
                                            onclick="openModal('refund-modal')"><i
                                                class="fas fa-money-bill-wave"></i></button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- ================= VIEW 3: WALK-IN ENTRY ================= -->
            <section id="view-walkin" class="view-section">
                <h2 class="page-title">Walk-In Entry</h2>

                <div class="booking-grid" style="margin-top: 10px; padding: 0;">
                    <!-- LEFT COLUMN -->
                    <div class="booking-main"
                        style="padding: 30px; border-radius: 8px; box-shadow: var(--shadow-soft);">

                        <h3 class="form-section-title">1. Guest Information</h3>
                        <div class="form-row">
                            <div class="form-group"><label>Full Name</label><input type="text"
                                    placeholder="Juan Dela Cruz"></div>
                            <div class="form-group"><label>Contact Number</label><input type="text"
                                    placeholder="09XX XXX XXXX"></div>
                        </div>

                        <h3 class="form-section-title mt-15">2. Select Accommodation</h3>
                        <div class="booking-tabs">
                            <button class="tab-btn active" data-tab="admin-event">Event Hall</button>
                            <button class="tab-btn" data-tab="admin-hotel">Hotel Rooms</button>
                            <button class="tab-btn" data-tab="admin-villa">Resort Villa</button>
                        </div>

                        <!-- EVENT HALL TAB -->
                        <div class="tab-content active" id="admin-event">
                            <div class="calendar-ui" id="walkin-cal">
                                <div class="cal-header">
                                    <button type="button" class="cal-nav prev-month">&larr;</button>
                                    <h4 class="cal-month-year">June 2026</h4>
                                    <button type="button" class="cal-nav next-month">&rarr;</button>
                                </div>
                                <div class="cal-weekdays">
                                    <span>SUN</span><span>MON</span><span>TUE</span><span>WED</span><span>THU</span><span>FRI</span><span>SAT</span>
                                </div>
                                <div class="cal-days-grid" id="walkin-cal-grid"></div>
                                <div class="cal-legend">
                                    <span class="legend-item"><span class="dot selected"></span> Selected</span>
                                    <span class="legend-item"><span class="dot booked"></span> Booked</span>
                                    <span class="legend-item"><span class="dot available"></span> Available</span>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group"><label>Select Venue Space</label><select>
                                        <option>Grand Ballroom</option>
                                    </select></div>
                                <div class="form-group"><label>Number of Guests</label><input type="number"
                                        placeholder="100"></div>
                            </div>
                        </div>

                        <div class="tab-content" id="admin-hotel">
                            <p>Hotel selection form goes here...</p>
                        </div>
                        <div class="tab-content" id="admin-villa">
                            <p>Villa selection form goes here...</p>
                        </div>

                        <h3 class="form-section-title mt-15">3. Payment Details</h3>
                        <div class="radio-group block-radios"
                            style="flex-direction: row; gap: 15px; margin-bottom: 20px;">
                            <label style="flex:1; justify-content:center; background: #FDF2E2;"><input type="radio"
                                    name="pay-scheme" checked> Full Payment</label>
                            <label style="flex:1; justify-content:center; background: #FDF2E2;"><input type="radio"
                                    name="pay-scheme"> 50% Downpayment</label>
                        </div>

                        <label class="small-label">Payment Method</label>
                        <div class="admin-payment-grid">
                            <button class="admin-pay-btn active">CASH</button>
                            <button class="admin-pay-btn">GCASH</button>
                            <button class="admin-pay-btn">MAYA</button>
                            <button class="admin-pay-btn">BANK TRANSFER</button>
                        </div>
                        <div class="form-group mt-15" id="admin-ref-no" style="display:none;">
                            <label>Reference No. (If Non Cash)</label>
                            <input type="text" placeholder="Enter Transaction Reference Number">
                        </div>
                    </div>

                    <!-- RIGHT COLUMN -->
                    <div class="booking-sidebar" style="margin-top:0;">
                        <div class="sticky-summary" style="top: 20px;">
                            <h3
                                style="font-family: var(--font-heading); font-size: 1.5rem; border-bottom: 1px solid rgba(0,0,0,0.1); padding-bottom: 10px; margin-bottom: 15px;">
                                Walk-In Summary</h3>
                            <div class="summary-container active">
                                <p><strong>Service:</strong> <span class="sum-val">Event Hall</span></p>
                                <p><strong>Venue:</strong> <span class="sum-val">Grand Ballroom</span></p>
                                <p><strong>Dates:</strong> <span class="sum-val">Select Date</span></p>
                                <p><strong>Total Due:</strong> <span class="sum-val stat-gold"
                                        style="font-weight:bold; font-size:1.1rem;">₱ 150,000</span></p>
                            </div>
                            <div class="summary-footer">
                                <button class="btn btn-primary" style="width: 100%;">CONFIRM WALK-IN</button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ================= VIEW 4: MAINTENANCE ================= -->
            <section id="view-maintenance" class="view-section">
                <h2 class="page-title">Maintenance Setup</h2>

                <div class="maintenance-layout">
                    <div class="maintenance-main">
                        <div class="admin-tabs-text">
                            <button class="m-tab active">Event Hall</button>
                            <button class="m-tab">Resort Villa</button>
                            <button class="m-tab">Standard Room</button>
                        </div>

                        <!-- Maintenance Interactive Calendar -->
                        <div class="calendar-ui full-width-cal mt-15" id="maint-cal">
                            <div class="cal-header">
                                <button class="cal-nav prev-month">&larr;</button>
                                <h4 class="cal-month-year">June 2026</h4>
                                <button class="cal-nav next-month">&rarr;</button>
                            </div>
                            <div class="cal-weekdays">
                                <span>SUN</span><span>MON</span><span>TUE</span><span>WED</span><span>THU</span><span>FRI</span><span>SAT</span>
                            </div>
                            <div class="cal-days-grid" id="maint-cal-grid"></div>
                        </div>
                    </div>

                    <div class="maintenance-sidebar">
                        <div class="maint-card">
                            <h3 style="font-family: var(--font-heading); margin-bottom: 1.5rem;">Schedule Block</h3>
                            <div class="form-group"><label>Area / Unit</label><input type="text"
                                    placeholder="e.g. Garden Pavilion"></div>
                            <div class="form-group">
                                <label>Maintenance Type</label>
                                <select>
                                    <option>General Cleaning</option>
                                    <option>Electrical</option>
                                    <option>Plumbing</option>
                                </select>
                            </div>
                            <div class="form-group"><label>Notes</label><textarea rows="3"
                                    placeholder="Details..."></textarea></div>

                            <div class="toggle-group mt-15">
                                <label class="switch"><input type="checkbox" checked><span
                                        class="slider round"></span></label>
                                <span>Block venue from new bookings</span>
                            </div>
                            <button class="btn btn-primary mt-15" style="width:100%;">APPLY SCHEDULE</button>
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
            <h3 style="margin-top:0;">Process Refund</h3>
            <div class="refund-details">
                <p><span>Booking ID:</span> <strong>#12313</strong></p>
                <p><span>Customer:</span> <strong>Lebron James</strong></p>
                <p><span>Paid Amount:</span> <strong>₱9,000</strong></p>
                <p style="margin-top:15px; font-size:1.1rem; color:#e06666;"><span>Refund Amount:</span>
                    <strong>₱8,500</strong>
                </p>
            </div>
            <div class="modal-actions-center mt-15">
                <button class="btn btn-outline" style="border-color:#ccc; color:#333;"
                    onclick="closeModal('refund-modal')">Cancel</button>
                <button class="btn btn-primary" style="background:#e06666;">Confirm Refund</button>
            </div>
        </div>
    </div>

    <!-- Reschedule Modal -->
    <div class="modal-overlay" id="reschedule-modal">
        <div class="modal-content admin-modal modal-large">
            <h3 style="margin-top:0; text-align:center;">Reschedule Booking</h3>
            <div class="reschedule-info" style="text-align:center; margin-bottom:15px;">
                <p>Original Date: <strong>May 5-6, 2026</strong></p>
                <p>Select new dates on the calendar below.</p>
            </div>

            <div class="calendar-ui" id="modal-cal"
                style="box-shadow:none; border:1px solid #eee; padding:15px; margin-bottom:15px;">
                <div class="cal-header" style="margin-bottom:10px;">
                    <button class="cal-nav prev-month">&larr;</button>
                    <h4 class="cal-month-year" style="font-size:1.1rem;">May 2026</h4>
                    <button class="cal-nav next-month">&rarr;</button>
                </div>
                <div class="cal-weekdays">
                    <span>S</span><span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span>
                </div>
                <div class="cal-days-grid" id="modal-cal-grid" style="gap:2px;"></div>
            </div>

            <div class="modal-actions-center">
                <button class="btn btn-outline" style="border-color:#ccc; color:#333;"
                    onclick="closeModal('reschedule-modal')">Cancel</button>
                <button class="btn btn-primary" style="background:#3498db;">Reschedule</button>
            </div>
        </div>
    </div>

    <script src="assets/js/admin_dashboard.js"></script>
</body>

</html>