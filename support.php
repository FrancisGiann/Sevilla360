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
    'biz_policies' => "• Standard Check-in is at 2:00 PM. Check-out is at 12:00 PM (Unless booking Day Time Stay).\n• Please bring a valid Government ID matching the name on this itinerary.\n• Cancellations made less than 7 days before arrival are subject to fees.",
    'support_intro' => 'Everything you need to plan your event or stay with confidence.',
    'support_contact_heading' => 'We are here to help',
    'support_contact_description' => 'Reach our team for booking questions, venue details, or help with an existing reservation.',
    'support_faq_json' => json_encode([
        ['question' => 'How long are online dates held?', 'answer' => 'Confirmed selections are temporarily locked while you complete a paid booking. If the lock expires, select the dates again.'],
        ['question' => 'Are hotel rooms priced per night?', 'answer' => "Yes. Hotel stays require at least one night, and the checkout date may coincide with another guest's check-in."],
        ['question' => 'What happens after an Event Hall inquiry?', 'answer' => 'The resort team reviews the inquiry and contacts you about the final quotation and schedule. No online payment is required when the inquiry is submitted.'],
        ['question' => 'Where can I see my booking status?', 'answer' => 'Sign in and open your User Dashboard to view status, payment information, notifications, and booking details.']
    ], JSON_UNESCAPED_SLASHES),
    'support_privacy' => "We collect the information needed to create and manage reservations, communicate with guests, process payments, and provide resort services.\n\nAccount and booking information is available only to the customer it belongs to and authorized resort staff or administrators. Contact us if you need help reviewing or correcting your information.",
    'support_terms' => "Bookings are subject to availability and the selected payment or inquiry process.\nMaximum capacities and venue rules are enforced.\nCancellation and refund handling follows the applicable booking policy and administrator review.\nGuests are responsible for damage to resort property.\nVirtual showroom images are illustrative; actual arrangements and lighting may vary."
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
$faq_items = json_decode((string)$support_settings['support_faq_json'], true);
$faq_items = is_array($faq_items) ? array_values(array_filter($faq_items, static fn($item) => is_array($item) && trim((string)($item['question'] ?? '')) !== '')) : [];
$privacy_paragraphs = preg_split('/\r\n\r\n|\r\r|\n\n/', trim((string)$support_settings['support_privacy'])) ?: [];
$terms_lines = preg_split('/\r\n|\r|\n/', trim((string)$support_settings['support_terms'])) ?: [];
include 'includes/header.php';
?>
<main class="support-page">
    <section class="support-hero">
        <p class="support-eyebrow">SEVILLA360</p>
        <h1>Support &amp; Information</h1>
        <p><?php echo htmlspecialchars($support_settings['support_intro'], ENT_QUOTES, 'UTF-8'); ?></p>
    </section>
    <div class="support-grid">
        <section class="support-card" id="contact">
            <p class="support-eyebrow">CONTACT</p><h2><?php echo htmlspecialchars($support_settings['support_contact_heading'], ENT_QUOTES, 'UTF-8'); ?></h2>
            <p><?php echo htmlspecialchars($support_settings['support_contact_description'], ENT_QUOTES, 'UTF-8'); ?></p>
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
            <div class="support-faq"><?php foreach ($faq_items as $faq): ?><details><summary><?php echo htmlspecialchars((string)$faq['question'], ENT_QUOTES, 'UTF-8'); ?></summary><p><?php echo htmlspecialchars((string)($faq['answer'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p></details><?php endforeach; ?></div>
        </section>
        <section class="support-card" id="privacy">
            <p class="support-eyebrow">PRIVACY</p><h2>Privacy policy</h2>
            <?php foreach ($privacy_paragraphs as $paragraph): if (trim($paragraph) === '') continue; ?><p><?php echo htmlspecialchars(trim($paragraph), ENT_QUOTES, 'UTF-8'); ?></p><?php endforeach; ?>
        </section>
        <section class="support-card" id="terms">
            <p class="support-eyebrow">TERMS</p><h2>Terms and conditions</h2>
            <ol><?php foreach ($terms_lines as $term): if (trim($term) === '') continue; ?><li><?php echo htmlspecialchars(trim($term), ENT_QUOTES, 'UTF-8'); ?></li><?php endforeach; ?></ol>
        </section>
    </div>
</main>
<?php include 'includes/footer.php'; ?>
