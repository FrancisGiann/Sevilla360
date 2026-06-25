<?php
// Get the requested page from the URL. If none is set, default to 'overview'
$page = isset($_GET['page']) ? $_GET['page'] : 'overview';
?>
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

    <!-- Base Stylesheets -->
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/admin_dashboard.css">

    <!-- Load specific assets based on the active page -->
    <?php if ($page === 'overview'): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <?php elseif ($page === 'bookings'): ?>
    <link rel="stylesheet" href="assets/css/admin_bookings.css">
    <?php elseif ($page === 'walkin'): ?>
    <link rel="stylesheet" href="assets/css/admin_walkin.css">
    <?php elseif ($page === 'maintenance'): ?>
    <link rel="stylesheet" href="assets/css/admin_maintenance.css">
    <?php endif; ?>

</head>

<body class="admin-body">

    <div class="admin-layout">

        <!-- Left Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <a href="index.php" class="navbar-brand">SEVILLA360</a>
                <span class="admin-badge">ADMIN</span>
            </div>

            <nav class="sidebar-nav">
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="admin_dashboard.php?page=overview"
                            class="nav-link <?php echo $page === 'overview' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-chart-pie"></i> Overview
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="admin_dashboard.php?page=bookings"
                            class="nav-link <?php echo $page === 'bookings' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-calendar-check"></i> Bookings
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="admin_dashboard.php?page=walkin"
                            class="nav-link <?php echo $page === 'walkin' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-person-walking-arrow-right"></i> Walk-in Entry
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="admin_dashboard.php?page=maintenance"
                            class="nav-link <?php echo $page === 'maintenance' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-screwdriver-wrench"></i> Maintenance
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link">
                            <i class="fa-solid fa-gear"></i> Settings
                        </a>
                    </li>
                </ul>
            </nav>

            <div class="sidebar-footer">
                <a href="#" class="nav-link sign-out"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out</a>
            </div>
        </aside>

        <!-- Main Content Area -->
        <!-- Added 'bookings' to the scroll class condition so the table scrolls properly if it overflows -->
        <main
            class="main-content <?php echo ($page === 'walkin' || $page === 'maintenance' || $page === 'bookings') ? 'booking-main-scroll' : ''; ?>">

            <!-- Top Header -->
            <header class="admin-header">
                <h2 class="page-title">
                    <?php 
                        if ($page === 'overview') echo 'Dashboard Overview';
                        elseif ($page === 'bookings') echo 'Bookings Management';
                        elseif ($page === 'walkin') echo 'Walk-In Booking Entry';
                        elseif ($page === 'maintenance') echo 'Facility Maintenance';
                    ?>
                </h2>
                <div class="header-actions">
                    <a href="index.php" class="btn-back"><i class="fa-solid fa-house"></i> Back to Home</a>
                    <div class="admin-profile">
                        <i class="fa-solid fa-circle-user profile-icon"></i>
                    </div>
                </div>
            </header>

            <!-- Dynamically Include Content Here -->
            <?php 
                if ($page === 'walkin') {
                    include 'includes/admin_walkin.php';
                } elseif ($page === 'maintenance') {
                    include 'includes/admin_maintenance.php';
                } elseif ($page === 'bookings') {
                    include 'includes/admin_bookings.php';
                } else {
                    include 'includes/admin_overview.php';
                }
            ?>

        </main>
    </div>

    <!-- Load specific JS based on the active page -->
    <?php if ($page === 'overview'): ?>
    <script src="assets/js/admin_dashboard.js"></script>
    <?php elseif ($page === 'bookings'): ?>
    <script src="assets/js/admin_bookings.js"></script>
    <?php elseif ($page === 'walkin'): ?>
    <script src="assets/js/admin_walkin.js"></script>
    <?php elseif ($page === 'maintenance'): ?>
    <script src="assets/js/admin_maintenance.js"></script>
    <?php endif; ?>

</body>

</html>