<?php
$footer_settings = ['biz_email' => 'reservations@sevilla360.com', 'social_links_json' => '[]'];
if (isset($conn) && $conn instanceof mysqli) {
    $footer_query = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('biz_email', 'social_links_json')");
    if ($footer_query) {
        while ($footer_row = $footer_query->fetch_assoc()) {
            $footer_settings[$footer_row['setting_key']] = $footer_row['setting_value'];
        }
    }
}
$footer_social_links = json_decode($footer_settings['social_links_json'], true);
$footer_social_links = is_array($footer_social_links) ? $footer_social_links : [];
?>
<!-- Footer -->
<footer class="idx-footer">
    <div class="idx-footer-inner">
        <div class="idx-footer-grid">
            <div class="idx-footer-brand">
                <span>Sevilla360</span>
                <p>M.I. Sevilla Resort &amp; Events Place — a private estate for weddings,
                    celebrations and quiet stays.</p>
            </div>

            <nav class="idx-footer-col">
                <h4>Quick Links</h4>
                <ul>
                    <li><a href="index.php">Home</a></li>
                    <li><a href="index.php#about">About</a></li>
                    <li><a href="index.php#experiences">Events</a></li>
                    <li><a href="index.php#accommodations">Accommodations</a></li>
                    <li><a href="showroom.php">Virtual Showroom</a></li>
                </ul>
            </nav>

            <nav class="idx-footer-col">
                <h4>Support</h4>
                <ul>
                    <li><a href="support.php#contact">Contact Us</a></li>
                    <li><a href="support.php#booking-policy">Booking Policy</a></li>
                    <li><a href="support.php#faqs">FAQs</a></li>
                    <li><a href="support.php#privacy">Privacy</a></li>
                    <li><a href="support.php#terms">Terms</a></li>
                </ul>
            </nav>

            <nav class="idx-footer-col">
                <h4>Connect</h4>
                <ul>
                    <?php foreach ($footer_social_links as $footer_social):
                        $footer_label = trim((string)($footer_social['label'] ?? ''));
                        $footer_url = trim((string)($footer_social['url'] ?? ''));
                        if ($footer_label === '' || !preg_match('/^https?:\/\//i', $footer_url)) continue;
                    ?>
                    <li><a href="<?php echo htmlspecialchars($footer_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($footer_label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                    <?php endforeach; ?>
                    <?php if (filter_var($footer_settings['biz_email'], FILTER_VALIDATE_EMAIL)): ?>
                    <li><a href="mailto:<?php echo htmlspecialchars($footer_settings['biz_email'], ENT_QUOTES, 'UTF-8'); ?>">Email Us</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>

        <div class="idx-footer-bottom">
            &copy; <?php echo date("Y"); ?> M.I. Sevilla Resort &amp; Events Place. All rights reserved.
        </div>
    </div>
</footer>

<!-- Global Scripts (Nav Menu etc) -->
<script src="assets/js/global_modals.js?v=<?php echo time(); ?>"></script>
<script src="assets/js/index.js?v=<?php echo time(); ?>"></script>
<script src="assets/js/calendar.js?v=<?php echo time(); ?>"></script>

<!-- Page Specific Script (Loads dynamically) -->
<?php if (isset($extra_js) && !empty($extra_js) && $extra_js !== 'assets/js/index.js'): ?>
<script src="<?php echo $extra_js; ?>"></script>
<?php endif; ?>

<?php
$latest_unread_hp = null;
if (isset($hp_notifications) && !empty($hp_notifications) && isset($isAdmin) && !$isAdmin) {
    foreach ($hp_notifications as $n) {
        if (isset($n['is_read']) && $n['is_read'] == 0) {
            $latest_unread_hp = $n;
            break;
        }
    }
}
?>
<?php if ($latest_unread_hp): ?>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        setTimeout(() => {
            if (typeof playNotificationChime === 'function') {
                playNotificationChime();
            }
            if (typeof showAlert === 'function') {
                showAlert(
                    <?php echo json_encode((string)$latest_unread_hp['title'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                    <?php echo json_encode((string)$latest_unread_hp['message'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                    "info"
                );
            }
            // Mark this auto-popped notification as read so it doesn't repeatedly auto-popup on future refreshes
            fetch("actions/user/mark_notifications_read.php", { method: 'POST', headers: { 'X-CSRF-TOKEN': <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>, 'Content-Type': 'application/x-www-form-urlencoded' }, body: "id=<?php echo (int)$latest_unread_hp['id']; ?>" });
        }, 500);
    });
</script>
<?php endif; ?>

</body>

</html>
