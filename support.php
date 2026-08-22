<?php
$page_title = 'Support - SEVILLA360';
$extra_css = 'assets/css/support.css?v=' . time();
$active_page = '';
require_once 'config/db_connect.php';

$support_settings = [
    'biz_name' => 'Sevilla360',
    'biz_email' => 'reservations@sevilla360.com',
    'biz_phone' => '+63 912 345 6789',
    'biz_address' => '123 Resort Drive, Paradise City',
    'biz_policies' => "• Standard Check-in is at 2:00 PM. Check-out is at 12:00 PM (Unless booking Day Time Stay).\n• Please bring a valid Government ID matching the name on this itinerary.\n• Cancellations made less than 7 days before arrival are subject to fees."
];
$support_query = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'biz_%'");
if ($support_query) {
    while ($support_row = $support_query->fetch_assoc()) {
        if (array_key_exists($support_row['setting_key'], $support_settings)) {
            $support_settings[$support_row['setting_key']] = $support_row['setting_value'];
        }
    }
}
$policy_lines = preg_split('/\r\n|\r|\n/', trim((string)$support_settings['biz_policies'])) ?: [];
include 'includes/header.php';
?>
<main class="support-page">
    <section class="support-hero">
        <p class="support-eyebrow">SEVILLA360</p>
        <h1>Support &amp; Information</h1>
        <p>Everything you need to plan your event or stay with confidence.</p>
    </section>
    <div class="support-grid">
        <section class="support-card" id="contact">
            <p class="support-eyebrow">CONTACT</p><h2>We are here to help</h2>
            <p>Reach our team for booking questions, venue details, or help with an existing reservation.</p>
            <dl class="support-contact-list">
                <div><dt>Email</dt><dd><a href="mailto:<?php echo htmlspecialchars($support_settings['biz_email'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($support_settings['biz_email']); ?></a></dd></div>
                <div><dt>Phone</dt><dd><a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $support_settings['biz_phone'])); ?>"><?php echo htmlspecialchars($support_settings['biz_phone']); ?></a></dd></div>
                <div><dt>Address</dt><dd><?php echo htmlspecialchars($support_settings['biz_address']); ?></dd></div>
            </dl>
        </section>
        <section class="support-card" id="booking-policy">
            <p class="support-eyebrow">BOOKING POLICY</p><h2>Before you reserve</h2>
            <ul><?php foreach ($policy_lines as $line): $line = trim(preg_replace('/^[•\-*]\s*/', '', $line)); if ($line === '') continue; ?><li><?php echo htmlspecialchars($line); ?></li><?php endforeach; ?></ul>
        </section>
        <section class="support-card" id="faqs">
            <p class="support-eyebrow">FAQS</p><h2>Frequently asked questions</h2>
            <div class="support-faq">
                <details><summary>How long are online dates held?</summary><p>Confirmed selections are temporarily locked while you complete a paid booking. If the lock expires, select the dates again.</p></details>
                <details><summary>Are hotel rooms priced per night?</summary><p>Yes. Hotel stays require at least one night, and the checkout date may coincide with another guest's check-in.</p></details>
                <details><summary>What happens after an Event Hall inquiry?</summary><p>The resort team reviews the inquiry and contacts you about the final quotation and schedule. No online payment is required when the inquiry is submitted.</p></details>
                <details><summary>Where can I see my booking status?</summary><p>Sign in and open your User Dashboard to view status, payment information, notifications, and booking details.</p></details>
            </div>
        </section>
        <section class="support-card" id="privacy">
            <p class="support-eyebrow">PRIVACY</p><h2>Privacy policy</h2>
            <p>We collect the information needed to create and manage reservations, communicate with guests, process payments, and provide resort services.</p>
            <p>Account and booking information is available only to the customer it belongs to and authorized resort staff or administrators. Contact us if you need help reviewing or correcting your information.</p>
        </section>
        <section class="support-card" id="terms">
            <p class="support-eyebrow">TERMS</p><h2>Terms and conditions</h2>
            <ol><li>Bookings are subject to availability and the selected payment or inquiry process.</li><li>Maximum capacities and venue rules are enforced.</li><li>Cancellation and refund handling follows the applicable booking policy and administrator review.</li><li>Guests are responsible for damage to resort property.</li><li>Virtual showroom images are illustrative; actual arrangements and lighting may vary.</li></ol>
        </section>
    </div>
</main>
<?php include 'includes/footer.php'; ?>
