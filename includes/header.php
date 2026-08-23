<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/admin_notifications.php';
$page_title  = isset($page_title) ? $page_title : 'SEVILLA360';
$extra_css   = isset($extra_css) ? $extra_css : '';
$active_page = isset($active_page) ? $active_page : '';

$isLoggedIn = isset($_SESSION['logged_in']) || isset($_SESSION['user_id']);
$firstName  = $_SESSION['first_name'] ?? ($_SESSION['username'] ?? 'Account');
$isAdmin    = (($_SESSION['role'] ?? '') === 'staff' || ($_SESSION['role'] ?? '') === 'admin');

// Fetch notifications for homepage notification bell if logged in
$hp_unread_count = 0;
$hp_notifications = [];
if ($isLoggedIn && isset($conn) && $conn instanceof mysqli) {
    if (!$isAdmin && !empty($_SESSION['user_id'])) {
        $u_id = $_SESSION['user_id'];
        $st_h_unread = $conn->prepare("SELECT COUNT(*) AS unread FROM user_notifications WHERE user_id = ? AND is_read = 0");
        if ($st_h_unread) {
            $st_h_unread->bind_param("i", $u_id);
            $st_h_unread->execute();
            $hp_unread_count = $st_h_unread->get_result()->fetch_assoc()['unread'] ?? 0;
            $st_h_unread->close();
        }

        $st_h_notifs = $conn->prepare("SELECT id, title, message, is_read, created_at, 'customer' as notif_type FROM user_notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
        if ($st_h_notifs) {
            $st_h_notifs->bind_param("i", $u_id);
            $st_h_notifs->execute();
            $hp_notifications = $st_h_notifs->get_result()->fetch_all(MYSQLI_ASSOC);
            $st_h_notifs->close();
        }
    } elseif ($isAdmin) {
        try {
            $admin_notifications = get_admin_action_notifications($conn, 10);
        } catch (Throwable $e) {
            error_log('Unable to load admin homepage notifications: ' . $e->getMessage());
            $admin_notifications = [];
        }

        foreach ($admin_notifications as $b) {
            $title = "Action Required";
            $msg = "";
            $url = "admin_dashboard.php?page=bookings&search=" . urlencode($b['reference_no']);
            $date_str = date('M j, Y', strtotime($b['start_date']));

            if ($b['cancel_status'] === 'Pending') {
                $title = "Refund Requested";
                $msg = "Refund request for {$b['venue_name']} (#{$b['reference_no']})";
                $hp_unread_count++;
            } elseif ($b['resched_status'] === 'Pending') {
                $title = "Reschedule Requested";
                $msg = "Reschedule request for {$b['venue_name']} (#{$b['reference_no']})";
                $hp_unread_count++;
            } elseif ($b['venue_category'] === 'Event Hall' && $b['booking_status'] === 'Pending') {
                $title = "New Event Inquiry";
                $msg = "Event Hall inquiry for {$b['venue_name']} (#{$b['reference_no']})";
                $hp_unread_count++;
            }

            if (!empty($msg)) {
                $hp_notifications[] = [
                    'id' => $b['id'],
                    'title' => $title,
                    'message' => $msg,
                    'url' => $url,
                    'is_read' => 0,
                    'created_at' => $date_str,
                    'notif_type' => 'admin'
                ];
            }
        }
    }
}

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
    <link rel="icon" type="image/png" href="assets/img/Logo.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Playfair+Display:ital,wght@0,400;0,500;0,600;1,400&family=Great+Vibes&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

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
                    <!-- Homepage Notification Bell (Customers & Admins) -->
                    <div class="s-notif-wrapper" id="hpNotifWrapper">
                        <button type="button" class="s-notif-btn" id="hpNotifBtn" aria-label="Notifications">
                            <i class="fa-regular fa-bell"></i>
                            <?php if ($hp_unread_count > 0): ?>
                            <span class="s-notif-badge" id="hpNotifBadge"><?php echo $hp_unread_count; ?></span>
                            <?php endif; ?>
                        </button>

                        <div class="s-notif-dropdown" id="hpNotifDropdown">
                            <div class="s-notif-header">
                                <span>Notifications</span>
                                <?php if (!$isAdmin && $hp_unread_count > 0): ?>
                                <button type="button" id="hpBtnMarkRead" class="s-notif-mark-btn">Mark all read</button>
                                <?php elseif ($isAdmin): ?>
                                <a href="admin_dashboard.php?page=bookings&filter=action_req" class="s-notif-mark-btn" style="text-decoration:none;">View All</a>
                                <?php endif; ?>
                            </div>
                            <div class="s-notif-body">
                                <?php if (empty($hp_notifications)): ?>
                                <div class="s-notif-empty">No notifications yet.</div>
                                <?php else: ?>
                                <?php foreach ($hp_notifications as $n): 
                                    $icon = 'fa-bell';
                                    $color = '#d6a870';
                                    $t = strtolower($n['title']);
                                    if (strpos($t, 'payment') !== false) { $icon = 'fa-money-bill-wave'; $color = '#28a745'; }
                                    elseif (strpos($t, 'cancel') !== false || strpos($t, 'reject') !== false || strpos($t, 'refund') !== false) { $icon = 'fa-arrow-rotate-left'; $color = '#dc3545'; }
                                    elseif (strpos($t, 'reschedule') !== false) { $icon = 'fa-calendar-day'; $color = '#17a2b8'; }
                                    elseif (strpos($t, 'booking') !== false || strpos($t, 'quotation') !== false || strpos($t, 'inquiry') !== false) { $icon = 'fa-champagne-glasses'; $color = '#d6a870'; }

                                    $item_url = isset($n['url']) ? $n['url'] : '#';
                                ?>
                                <a href="<?php echo htmlspecialchars($item_url); ?>" class="s-notif-item <?php echo $n['is_read'] ? '' : 'unread'; ?>" <?php if (!$isAdmin): ?>data-id="<?php echo $n['id']; ?>" data-title="<?php echo htmlspecialchars($n['title']); ?>" data-message="<?php echo htmlspecialchars($n['message']); ?>"<?php endif; ?> style="text-decoration:none; display:flex;">
                                    <div class="s-notif-icon" style="color: <?php echo $color; ?>;"><i class="fa-solid <?php echo $icon; ?>"></i></div>
                                    <div class="s-notif-info">
                                        <h5><?php echo htmlspecialchars($n['title']); ?></h5>
                                        <p><?php echo htmlspecialchars($n['message']); ?></p>
                                        <small><?php echo htmlspecialchars($n['created_at']); ?></small>
                                    </div>
                                </a>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

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

        var hpNotifWrapper = document.getElementById('hpNotifWrapper');
        var hpNotifBtn = document.getElementById('hpNotifBtn');
        if (hpNotifWrapper && hpNotifBtn) {
            hpNotifBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                hpNotifWrapper.classList.toggle('is-open');
            });
            document.addEventListener('click', function() {
                hpNotifWrapper.classList.remove('is-open');
            });

            var hpBtnMarkRead = document.getElementById('hpBtnMarkRead');
            if (hpBtnMarkRead) {
                hpBtnMarkRead.addEventListener('click', function(e) {
                    e.stopPropagation();
                    fetch('actions/user/mark_notifications_read.php')
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res.success) {
                            var badge = document.getElementById('hpNotifBadge');
                            if (badge) badge.remove();
                            if (hpBtnMarkRead) hpBtnMarkRead.remove();
                            document.querySelectorAll('#hpNotifDropdown .s-notif-item').forEach(function(el) {
                                el.classList.remove('unread');
                            });
                        }
                    });
                });
            }

            document.querySelectorAll('#hpNotifDropdown .s-notif-item').forEach(function(item) {
                item.addEventListener('click', function(e) {
                    var id = this.getAttribute('data-id');
                    if (!id) return;
                    e.stopPropagation();
                    var title = this.getAttribute('data-title');
                    var msg = this.getAttribute('data-message');
                    var self = this;
                    
                    fetch('actions/user/mark_notifications_read.php?id=' + id)
                    .then(function() {
                        self.classList.remove('unread');
                    });
                    if (window.showAlert) {
                        window.showAlert(title, msg, 'info');
                    } else {
                        alert(title + "\n\n" + msg);
                    }
                });
            });
        }
    })();
    </script>
