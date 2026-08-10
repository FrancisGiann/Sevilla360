<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$page_title  = isset($page_title) ? $page_title : 'SEVILLA360';
$extra_css   = isset($extra_css) ? $extra_css : '';
$active_page = isset($active_page) ? $active_page : '';

// Check login status based on your existing session variables
$isLoggedIn = isset($_SESSION['logged_in']) || isset($_SESSION['user_id']);
$firstName  = $_SESSION['first_name'] ?? ($_SESSION['username'] ?? 'Account');
$isAdmin    = (($_SESSION['role'] ?? '') === 'admin' || ($_SESSION['role'] ?? '') === 'superadmin');

// Updated to point to your actual site pages and anchors!
$nav = [
    'home'           => ['label' => 'Home',             'url' => 'index.php'],
    'about'          => ['label' => 'About',            'url' => 'index.php#about'],
    'events'         => ['label' => 'Events',           'url' => 'index.php#experiences'],
    'accommodations' => ['label' => 'Accommodations',   'url' => 'index.php#accommodations'],
    'showroom'       => ['label' => 'Virtual Showroom', 'url' => 'showroom.php'],
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <title><?php echo htmlspecialchars($page_title); ?></title>

    <!-- Google Fonts (Playfair Display, Inter, Great Vibes) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Playfair+Display:ital,wght@0,400;0,500;0,600;1,400&family=Great+Vibes&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Load CSS -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/header.css?v=<?php echo time(); ?>">
    <?php if (!empty($extra_css)): ?>
    <link rel="stylesheet" href="<?php echo $extra_css; ?>">
    <?php endif; ?>
</head>

<body>

    <header class="s-header" id="siteHeader">
        <nav class="s-nav" aria-label="Main navigation">
            <a class="s-brand" href="index.php">Sevilla<span>360</span></a>

            <ul class="s-links">
                <?php foreach ($nav as $key => $item): ?>
                <li>
                    <a href="<?php echo $item['url']; ?>" class="<?php echo $active_page === $key ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($item['label']); ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>

            <div class="s-desktop-only">
                <?php if (!$isLoggedIn): ?>
                <a class="s-cta" href="auth.php">Login / Register</a>
                <?php else: ?>
                <div class="s-user" id="userMenu">
                    <button type="button" class="s-user__btn" id="userMenuBtn" aria-expanded="false">
                        <?php echo ($isAdmin ? '<i class="fa-solid fa-gear"></i>' : '<i class="fa-regular fa-user"></i>') . ' ' . htmlspecialchars($firstName); ?>
                        <span aria-hidden="true">&#9662;</span>
                    </button>
                    <div class="s-user__menu" role="menu">
                        <a href="<?php echo $isAdmin ? 'admin_dashboard.php' : 'user_dashboard.php'; ?>">Dashboard</a>
                        <hr>
                        <a href="actions/auth/logout.php" style="color: #d32f2f;">Logout</a>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <button type="button" class="s-burger" id="burger" aria-label="Toggle navigation" aria-expanded="false">
                <svg class="icon-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M3 6h18M3 12h18M3 18h18" stroke-linecap="round" />
                </svg>
                <svg class="icon-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M6 6l12 12M18 6L6 18" stroke-linecap="round" />
                </svg>
            </button>
        </nav>

        <div class="s-mobile">
            <?php foreach ($nav as $key => $item): ?>
            <a href="<?php echo $item['url']; ?>"><?php echo htmlspecialchars($item['label']); ?></a>
            <?php endforeach; ?>

            <?php if (!$isLoggedIn): ?>
            <a class="s-cta" href="auth.php">Login / Register</a>
            <?php else: ?>
            <a href="<?php echo $isAdmin ? 'admin_dashboard.php' : 'user_dashboard.php'; ?>">Dashboard</a>
            <a class="s-cta" href="actions/auth/logout.php">Logout</a>
            <?php endif; ?>
        </div>
    </header>

    <script>
    // Javascript for the new Lovable Header
    (function() {
        var header = document.getElementById('siteHeader');
        var burger = document.getElementById('burger');
        var userMenu = document.getElementById('userMenu');

        window.addEventListener('scroll', function() {
            header.classList.toggle('is-scrolled', window.scrollY > 30);
        }, {
            passive: true
        });

        burger.addEventListener('click', function() {
            var open = header.classList.toggle('is-open');
            burger.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        if (userMenu) {
            var btn = document.getElementById('userMenuBtn');
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var open = userMenu.classList.toggle('is-open');
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.addEventListener('click', function() {
                userMenu.classList.remove('is-open');
                btn.setAttribute('aria-expanded', 'false');
            });
        }
    })();
    </script>