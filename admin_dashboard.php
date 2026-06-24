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
                        <a href="admin_dashboard.php" class="nav-link active"><i class="fa-solid fa-chart-pie"></i>
                            Overview</a>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link"><i class="fa-solid fa-calendar-check"></i> Bookings</a>
                    </li>
                    <li class="nav-item">
                        <a href="booking_admin.php" class="nav-link"><i
                                class="fa-solid fa-person-walking-arrow-right"></i> Walk-in Entry</a>
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
                    <a href="index.php" class="btn-back"><i class="fa-solid fa-house"></i> Back to Home</a>
                    <div class="admin-profile">
                        <i class="fa-solid fa-circle-user profile-icon"></i>
                    </div>
                </div>
            </header>

            <!-- Dashboard Content Included Here -->
            <?php include 'includes/admin_overview.php'; ?>

        </main>

    </div>

    <!-- Admin JS logic -->
    <script src="assets/js/admin_dashboard.js"></script>
</body>

</html>