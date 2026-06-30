<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Default values if variables aren't set on the main page
$page_title = isset($page_title) ? $page_title : 'SEVILLA360';
$extra_css = isset($extra_css) ? $extra_css : '';
$active_page = isset($active_page) ? $active_page : '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>

    <!-- Master Stylesheet -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">

    <!-- Page-Specific Stylesheet (Loads dynamically) -->
    <?php if (!empty($extra_css)): ?>
    <link rel="stylesheet" href="<?php echo $extra_css; ?>">
    <?php endif; ?>
</head>

<body>

    <!-- Header / Navbar -->
    <nav class="navbar">
        <div class="container">
            <a href="index.php" class="navbar-brand">Sevilla360</a>

            <!-- Hamburger Icon -->
            <div class="hamburger" id="hamburger">
                <span></span>
                <span></span>
                <span></span>
            </div>

            <!-- Nav Links -->
            <div class="nav-links" id="nav-links">
                <a href="index.php"
                    <?php echo ($active_page === 'home') ? 'style="color: var(--color-gold);"' : ''; ?>>Home</a>
                <a href="index.php#about">About</a>
                <a href="index.php#events">Events</a>
                <a href="index.php#accommodations">Accommodations</a>
                <a href="showroom.php"
                    <?php echo ($active_page === 'showroom') ? 'style="color: var(--color-gold);"' : ''; ?>>Virtual
                    Showroom</a>

                <!-------- LOGIN / USER MENU ------->
                <?php if (!isset($_SESSION['logged_in'])) : ?>
                <a href="auth.php" class="btn btn-primary">Login / Register</a>
                <?php else : ?>

                <!-- NEW DROPDOWN MENU -->
                <div class="nav-dropdown">
                    <button class="btn-user-menu">
                        <?php echo ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'superadmin') ? '⚙ Admin' : '👤 My Account'; ?>
                        <span class="dropdown-arrow">▼</span>
                    </button>

                    <div class="nav-dropdown-menu">
                        <?php if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'superadmin') : ?>
                        <a href="admin_dashboard.php">Dashboard</a>
                        <?php else : ?>
                        <a href="user_dashboard.php">Dashboard</a>
                        <?php endif; ?>

                        <a href="actions/auth/logout.php" class="logout-link">Logout</a>
                    </div>
                </div>

                <?php endif; ?>
            </div>
        </div>
    </nav>