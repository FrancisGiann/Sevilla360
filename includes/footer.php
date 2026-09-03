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
$footer_social_links = array_values(array_filter($footer_social_links, static function ($footer_social): bool {
    if (!is_array($footer_social)) return false;
    $footer_label = trim((string)($footer_social['label'] ?? ''));
    $footer_url = trim((string)($footer_social['url'] ?? ''));
    return $footer_label !== '' && preg_match('/^https?:\/\//i', $footer_url) === 1;
}));
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

            <?php if (!empty($footer_social_links)): ?>
            <nav class="idx-footer-col">
                <h4>Connect</h4>
                <ul>
                    <?php foreach ($footer_social_links as $footer_social):
                        $footer_label = trim((string)($footer_social['label'] ?? ''));
                        $footer_url = trim((string)($footer_social['url'] ?? ''));
                    ?>
                    <li><a href="<?php echo htmlspecialchars($footer_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($footer_label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </nav>
            <?php endif; ?>
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

</body>

</html>
