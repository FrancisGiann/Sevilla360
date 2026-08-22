<?php
$required_role = 'admin';
require 'includes/auth_guard.php';

// Get the requested page from the URL. If none is set, default to 'overview'
$page = isset($_GET['page']) ? $_GET['page'] : 'overview';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/img/Logo.png">
    <meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?? ''; ?>">
    <title>SEVILLA360 - Admin Dashboard</title>

    <!-- Fonts & Icons -->
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Playfair+Display:ital,wght@0,400;0,500;0,600;1,400&family=Great+Vibes&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Base Stylesheets -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="assets/css/admin-page/admin_overview.css?v=<?= time() ?>">

    <!-- Load specific assets based on the active page -->
    <?php if ($page === 'overview'): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="assets/css/admin-page/admin_bookings.css?v=<?= time() ?>">
    <?php elseif ($page === 'calendar'): ?>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
    <link rel="stylesheet" href="assets/css/admin-page/admin_calendar.css?v=<?= time() ?>">
    <?php elseif ($page === 'bookings'): ?>
    <link rel="stylesheet" href="assets/css/admin-page/admin_bookings.css?v=<?= time() ?>">
    <?php elseif ($page === 'walkin'): ?>
    <link rel="stylesheet" href="assets/css/admin-page/admin_walkin.css?v=<?= time() ?>">
    <?php elseif ($page === 'maintenance'): ?>
    <link rel="stylesheet" href="assets/css/admin-page/admin_maintenance.css?v=<?= time() ?>">
    <?php elseif ($page === 'settings'): ?>
    <link rel="stylesheet" href="assets/css/admin-page/admin_settings.css?v=<?= filemtime(__DIR__ . '/assets/css/admin-page/admin_settings.css') ?>">
    <?php elseif ($page === 'auditlog' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
    <link rel="stylesheet" href="assets/css/admin-page/admin_auditlog.css?v=<?= time() ?>">
    <?php elseif ($page === 'usermanagement' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
    <link rel="stylesheet" href="assets/css/admin-page/admin_usermanagement.css?v=<?= time() ?>">
    <?php elseif ($page === 'cms' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
    <link rel="stylesheet" href="assets/css/admin-page/admin_cms.css?v=<?= time() ?>">
    <?php elseif ($page === 'backups' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
    <link rel="stylesheet" href="assets/css/admin-page/admin_backups.css?v=<?= time() ?>">
    <?php endif; ?>
</head>

<body class="admin-body">
    <div class="admin-layout">
        <!-- Left Sidebar -->
        <aside class="sidebar" id="admin-sidebar">
            <div class="sidebar-header">
                <div class="sidebar-brand-row">
                    <a href="index.php" class="navbar-brand">SEVILLA360</a>
                    <span class="admin-badge">
                        <?php echo (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') ? 'ADMIN' : 'STAFF'; ?>
                    </span>
                </div>
                <button type="button" class="sidebar-collapse-toggle" id="sidebar-collapse-toggle"
                    aria-label="Minimize sidebar" aria-pressed="false" title="Minimize sidebar">
                    <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                </button>
            </div>

            <nav class="sidebar-nav">
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="admin_dashboard.php?page=overview"
                            class="nav-link <?php echo $page === 'overview' ? 'active' : ''; ?>"><i
                                class="fa-solid fa-chart-pie"></i> Overview</a>
                    </li>
                    <li class="nav-item">
                        <a href="admin_dashboard.php?page=calendar"
                            class="nav-link <?php echo $page === 'calendar' ? 'active' : ''; ?>"><i
                                class="fa-solid fa-calendar-days"></i> Master Calendar</a>
                    </li>
                    <li class="nav-item">
                        <a href="admin_dashboard.php?page=bookings"
                            class="nav-link <?php echo $page === 'bookings' ? 'active' : ''; ?>"><i
                                class="fa-solid fa-calendar-check"></i> Bookings</a>
                    </li>
                    <li class="nav-item">
                        <a href="admin_dashboard.php?page=walkin"
                            class="nav-link <?php echo $page === 'walkin' ? 'active' : ''; ?>"><i
                                class="fa-solid fa-person-walking-arrow-right"></i> Walk-in Entry</a>
                    </li>
                    <li class="nav-item">
                        <a href="admin_dashboard.php?page=maintenance"
                            class="nav-link <?php echo $page === 'maintenance' ? 'active' : ''; ?>"><i
                                class="fa-solid fa-screwdriver-wrench"></i> Maintenance</a>
                    </li>

                    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                    <li class="nav-item">
                        <a href="admin_dashboard.php?page=usermanagement"
                            class="nav-link <?php echo $page === 'usermanagement' ? 'active' : ''; ?>"><i
                                class="fa-solid fa-users-gear"></i> User Management</a>
                    </li>
                    <li class="nav-item">
                        <a href="admin_dashboard.php?page=auditlog"
                            class="nav-link <?php echo $page === 'auditlog' ? 'active' : ''; ?>"><i
                                class="fa-solid fa-clipboard-list"></i> Audit Log</a>
                    </li>
                    <li class="nav-item">
                        <a href="admin_dashboard.php?page=cms"
                            class="nav-link <?php echo $page === 'cms' ? 'active' : ''; ?>"><i
                                class="fa-solid fa-images"></i> Media CMS</a>
                    </li>
                    <li class="nav-item">
                        <a href="admin_dashboard.php?page=backups"
                            class="nav-link <?php echo $page === 'backups' ? 'active' : ''; ?>"><i
                                class="fa-solid fa-database"></i> Backup & Recovery</a>
                    </li>
                    <?php endif; ?>

                    <li class="nav-item">
                        <a href="admin_dashboard.php?page=settings"
                            class="nav-link <?php echo $page === 'settings' ? 'active' : ''; ?>"><i
                                class="fa-solid fa-gear"></i> Settings</a>
                    </li>
                </ul>
            </nav>

            <div class="sidebar-footer">
                <a href="actions/auth/logout.php" class="nav-link sign-out"><i
                        class="fa-solid fa-arrow-right-from-bracket"></i> Sign out</a>
            </div>
        </aside>
        <div class="mobile-nav-scrim" id="mobile-nav-scrim" aria-hidden="true"></div>

        <!-- Main Content Area -->
        <main class="main-content <?php echo ($page !== 'overview') ? 'booking-main-scroll' : ''; ?>">
            <!-- Top Header -->
            <header class="admin-header">
                <div class="admin-header-title-row">
                    <button type="button" class="mobile-nav-toggle" id="mobile-nav-toggle" aria-expanded="false" aria-controls="admin-sidebar">
                        <i class="fa-solid fa-bars" aria-hidden="true"></i><span>Menu</span>
                    </button>
                    <h2 class="page-title">
                    <?php 
                        if ($page === 'overview') echo 'Dashboard Overview';
                        elseif ($page === 'calendar') echo 'Master Calendar';
                        elseif ($page === 'bookings') echo 'Bookings Management';
                        elseif ($page === 'walkin') echo 'Walk-In Booking';
                        elseif ($page === 'maintenance') echo 'Maintenance';
                        elseif ($page === 'settings') echo 'System Settings'; 
                        elseif ($page === 'auditlog') echo 'System Audit Log';
                        elseif ($page === 'usermanagement') echo 'User Management';
                        elseif ($page === 'cms') echo 'Media CMS';
                        elseif ($page === 'backups') echo 'Database Backup & Recovery';
                    ?>
                    </h2>
                </div>
                <div class="header-actions">
                    <!-- Notification Center -->
                    <div class="notification-center" id="notifCenter" style="position: relative; margin-right: 20px;">

                        <!-- The Bell Button -->
                        <button type="button" class="notification-bell" id="notifBell"
                            aria-label="Open notifications" aria-expanded="false"
                            style="cursor: pointer; position: relative; padding: 5px;">
                            <i class="fa-regular fa-bell"
                                style="font-size: 1.4rem; color: var(--color-dark); transition: 0.2s;"
                                onmouseover="this.style.color='var(--color-gold)'"
                                onmouseout="this.style.color='var(--color-dark)'"></i>
                            <span id="global-notif-badge"
                                style="display:none; position: absolute; top: -2px; right: -2px; background: #ef4444; color: white; border-radius: 50%; padding: 2px 5px; font-size: 0.65rem; font-weight: bold; border: 2px solid white;">0</span>
                        </button>

                        <!-- The Dropdown Window (Hidden by Default) -->
                        <div class="notif-dropdown" id="notifDropdown">
                            <div class="notif-header">
                                <h4>Notifications</h4>
                                <a href="admin_dashboard.php?page=bookings&filter=action_req"
                                    style="font-size: 0.8rem; color: var(--color-gold); text-decoration: none;">View
                                    All</a>
                            </div>
                            <div class="notif-body" id="notifList">
                                <!-- JS will inject notification items here -->
                                <div style="padding: 20px; text-align: center; color: #888; font-size: 0.85rem;">You're
                                    all caught up!</div>
                            </div>
                        </div>

                    </div>

                    <div class="admin-profile" role="img" aria-label="Admin profile">
                        <i class="fa-solid fa-circle-user profile-icon" aria-hidden="true"></i>
                    </div>
                </div>
            </header>

            <!-- Dynamically Include Content Here -->
            <?php 
                if ($page === 'calendar') include 'includes/admin-page/admin_calendar.php';
                elseif ($page === 'walkin') include 'includes/admin-page/admin_walkin.php';
                elseif ($page === 'maintenance') include 'includes/admin-page/admin_maintenance.php';
                elseif ($page === 'bookings') include 'includes/admin-page/admin_bookings.php';
                elseif ($page === 'settings') include 'includes/admin-page/admin_settings.php'; 
                elseif ($page === 'auditlog') {
                    if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') include 'includes/admin-page/admin_auditlog.php';
                    else echo '<div class="unauthorized-access"><i class="fa-solid fa-lock"></i><h3>Unauthorized Access</h3></div>';
                } elseif ($page === 'usermanagement') {
                    if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') include 'includes/admin-page/admin_usermanagement.php';
                    else echo '<div class="unauthorized-access"><i class="fa-solid fa-lock"></i><h3>Unauthorized Access</h3></div>';
                } elseif ($page === 'cms') {
                    if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') include 'includes/admin-page/admin_cms.php';
                    else echo '<div class="unauthorized-access"><i class="fa-solid fa-lock"></i><h3>Unauthorized Access</h3></div>';
                } elseif ($page === 'backups') {
                    if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') include 'includes/admin-page/admin_backups.php';
                    else echo '<div class="unauthorized-access"><i class="fa-solid fa-lock"></i><h3>Unauthorized Access</h3></div>';
                } else {
                    include 'includes/admin-page/admin_overview.php';
                }
            ?>
        </main>
    </div>


    <!-- Shared Calendar Engine -->
    <script src="assets/js/calendar.js?v=1"></script>
    <!-- Notification Engine -->
    <script src="assets/js/admin-page/admin_notifications.js?v=<?= time() ?>"></script>
    <!-- Global Custom Modals -->
    <script src="assets/js/global_modals.js?v=<?= time() ?>"></script>
    <script src="assets/js/admin-page/admin_navigation.js?v=<?= time() ?>"></script>

    <!-- Specific JS for each page -->
    <?php if ($page === 'overview'): ?>
    <script src="assets/js/admin-page/admin_overview.js?v=<?= time() ?>"></script>
    <?php elseif ($page === 'calendar'): ?>
    <script src="assets/js/admin-page/admin_calendar.js?v=<?= time() ?>"></script>
    <?php elseif ($page === 'bookings'): ?>
    <script src="assets/js/admin-page/admin_bookings.js?v=<?= time() ?>"></script>
    <?php elseif ($page === 'walkin'): ?>
    <script src="assets/js/admin-page/admin_walkin.js?v=<?= time() ?>"></script>
    <?php elseif ($page === 'maintenance'): ?>
    <script src="assets/js/admin-page/admin_maintenance.js?v=<?= time() ?>"></script>
    <?php elseif ($page === 'settings'): ?>
    <script src="assets/js/admin-page/admin_settings.js?v=<?= filemtime(__DIR__ . '/assets/js/admin-page/admin_settings.js') ?>"></script>
    <?php elseif ($page === 'auditlog' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
    <script src="assets/js/admin-page/admin_auditlog.js?v=<?= time() ?>"></script>
    <?php elseif ($page === 'usermanagement' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
    <script src="assets/js/admin-page/admin_usermanagement.js?v=<?= time() ?>"></script>
    <?php elseif ($page === 'cms' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
    <!-- Three.js version required by Panolens -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/105/three.min.js"></script>
    <!-- Polyfill for Panolens Node.js bug -->
    <script>
    window.process = {
        env: {
            NODE_ENV: 'production'
        }
    };
    </script>
    <script src="https://cdn.jsdelivr.net/npm/panolens@0.12.1/build/panolens.min.js"></script>

    <!-- CMS Scripts -->
    <script src="assets/js/admin-page/admin_cms.js?v=<?= time() ?>"></script>
    <script src="assets/js/admin-page/admin_hotspots.js?v=<?= time() ?>"></script>
    <?php endif; ?>
</body>

</html>
