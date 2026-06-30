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
    <link rel="stylesheet" href="assets/css/style.css">

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
                <!-- We use PHP to add the gold color to the active page -->
                <a href="index.php"
                    <?php echo ($active_page === 'home') ? 'style="color: var(--color-gold);"' : ''; ?>>Home</a>
                <a href="index.php#about">About</a>
                <a href="index.php#events">Events</a>
                <a href="index.php#accommodations">Accommodations</a>
                <a href="showroom.php"
                    <?php echo ($active_page === 'showroom') ? 'style="color: var(--color-gold);"' : ''; ?>>Virtual
                    Showroom</a>

                <!--------lOGIN------->
                <?php if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']) : ?>
                <a href="user_dashboard.php" class="btn btn-primary">👤 My Account</a>
                <?php else : ?>
                <a href="auth.php" class="btn btn-primary">Login / Register</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>