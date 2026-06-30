<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Only allow logged-in admins
if (
    !isset($_SESSION['logged_in']) ||
    ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'superadmin')
) {
    header("Location: index.php");
    exit();
}

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
    <link rel="stylesheet" href="assets/css/admin-page/admin_dashboard.css">

    <!-- Load specific assets based on the active page -->
    <?php if ($page === 'overview'): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <?php elseif ($page === 'bookings'): ?>
    <link rel="stylesheet" href="assets/css/admin-page/admin_bookings.css">
    <?php elseif ($page === 'walkin'): ?>
    <link rel="stylesheet" href="assets/css/admin-page/admin_walkin.css">
    <?php elseif ($page === 'maintenance'): ?>
    <link rel="stylesheet" href="assets/css/admin-page/admin_maintenance.css">
    <?php elseif ($page === 'settings'): ?>
    <link rel="stylesheet" href="assets/css/admin-page/admin_settings.css">

    <!-- SUPER ADMIN CSS -->
    <?php elseif ($page === 'auditlog' && isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin'): ?>
    <link rel="stylesheet" href="assets/css/admin-page/admin_auditlog.css">
    <?php elseif ($page === 'usermanagement' && isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin'): ?>
    <link rel="stylesheet" href="assets/css/admin-page/admin_usermanagement.css">
    <?php elseif ($page === 'cms' && isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin'): ?>
    <link rel="stylesheet" href="assets/css/admin-page/admin_cms.css">
    <?php endif; ?>

</head>

<body class="admin-body">

    <div class="admin-layout">

        <!-- Left Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <a href="index.php" class="navbar-brand">SEVILLA360</a>

                <!-- DYNAMIC ROLE BADGE -->
                <span class="admin-badge">
                    <?php 
                if (isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin') {
                    echo 'SUPER ADMIN';
                } else {
                    echo 'ADMIN';
                }
            ?>
                </span>
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

                    <!-- SUPER ADMIN ONLY LINKS -->
                    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin'): ?>
                    <li class="nav-item">
                        <a href="admin_dashboard.php?page=usermanagement"
                            class="nav-link <?php echo $page === 'usermanagement' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-users-gear"></i> User Management
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="admin_dashboard.php?page=auditlog"
                            class="nav-link <?php echo $page === 'auditlog' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-clipboard-list"></i> Audit Log
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="admin_dashboard.php?page=cms"
                            class="nav-link <?php echo $page === 'cms' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-images"></i> Media CMS
                        </a>
                    </li>
                    <?php endif; ?>

                    <li class="nav-item">
                        <a href="admin_dashboard.php?page=settings"
                            class="nav-link <?php echo $page === 'settings' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-gear"></i> Settings
                        </a>
                    </li>
                </ul>
            </nav>

            <div class="sidebar-footer">
                <a href="actions/auth/logout.php" class="nav-link sign-out"><i
                        class="fa-solid fa-arrow-right-from-bracket"></i> Sign out</a>
            </div>
        </aside>

        <!-- Main Content Area -->
        <main
            class="main-content <?php echo ($page === 'walkin' || $page === 'maintenance' || $page === 'bookings' || $page === 'settings' || $page === 'auditlog' || $page === 'usermanagement' || $page === 'cms') ? 'booking-main-scroll' : ''; ?>">

            <!-- Top Header -->
            <header class="admin-header">
                <h2 class="page-title">
                    <?php 
                        if ($page === 'overview') echo 'Dashboard Overview';
                        elseif ($page === 'bookings') echo 'Bookings Management';
                        elseif ($page === 'walkin') echo 'Walk-In Booking';
                        elseif ($page === 'maintenance') echo 'Maintenance';
                        elseif ($page === 'settings') echo 'System Settings'; 
                        elseif ($page === 'auditlog') echo 'System Audit Log';
                        elseif ($page === 'usermanagement') echo 'User Management';
                        elseif ($page === 'cms') echo 'Media CMS';
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
                    include 'includes/admin-page/admin_walkin.php';
                } elseif ($page === 'maintenance') {
                    include 'includes/admin-page/admin_maintenance.php';
                } elseif ($page === 'bookings') {
                    include 'includes/admin-page/admin_bookings.php';
                } elseif ($page === 'settings') {
                    include 'includes/admin-page/admin_settings.php'; 
                } elseif ($page === 'auditlog') {
                    if (isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin') {
                        include 'includes/admin-page/admin_auditlog.php';
                    } else {
                        echo '
                        <div class="unauthorized-access">
                            <i class="fa-solid fa-lock"></i>
                            <h3>Unauthorized Access</h3>
                            <p>You do not have permission to view the Audit Log.</p>
                        </div>';
                    }
                } elseif ($page === 'usermanagement') {
                    if (isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin') {
                        include 'includes/admin-page/admin_usermanagement.php';
                    } else {
                        echo '
                        <div class="unauthorized-access">
                            <i class="fa-solid fa-lock"></i>
                            <h3>Unauthorized Access</h3>
                            <p>You do not have permission to view User Management.</p>
                        </div>';
                    }
                }elseif ($page === 'cms') {
                    if (isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin') {
                        include 'includes/admin-page/admin_cms.php';
                    } else {
                        echo '
                        <div class="unauthorized-access">
                            <i class="fa-solid fa-lock"></i>
                            <h3>Unauthorized Access</h3>
                            <p>You do not have permission to view CMS.</p>
                        </div>';
                    }
                } else {
                    include 'includes/admin-page/admin_overview.php';
                }
            ?>

        </main>
    </div>

    <!-- Specific JS for each page -->
    <?php if ($page === 'overview'): ?>
    <script src="assets/js/admin-page/admin_dashboard.js"></script>
    <?php elseif ($page === 'bookings'): ?>
    <script src="assets/js/admin-page/admin_bookings.js"></script>
    <?php elseif ($page === 'walkin'): ?>
    <script src="assets/js/admin-page/admin_walkin.js"></script>
    <?php elseif ($page === 'maintenance'): ?>
    <script src="assets/js/admin-page/admin_maintenance.js"></script>
    <?php elseif ($page === 'settings'): ?>
    <script src="assets/js/admin-page/admin_settings.js"></script>

    <!-- SUPER ADMIN JS -->
    <?php elseif ($page === 'auditlog' && isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin'): ?>
    <script src="assets/js/admin-page/admin_auditlog.js"></script>
    <?php elseif ($page === 'usermanagement' && isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin'): ?>
    <script src="assets/js/admin-page/admin_usermanagement.js"></script>
    <?php elseif ($page === 'cms' && isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin'): ?>
    <script src="assets/js/admin-page/admin_cms.js"></script>
    <?php endif; ?>

</body>

</html>