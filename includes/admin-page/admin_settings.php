<?php
require_once 'config/db_connect.php';

// 1. Fetch current settings
$settings_query = $conn->query("SELECT setting_key, setting_value FROM system_settings");
$current_settings = [];
if ($settings_query) {
    while($row = $settings_query->fetch_assoc()) {
        $current_settings[$row['setting_key']] = $row['setting_value'];
    }
}

$maintenance_checked = (isset($current_settings['maintenance_mode']) && $current_settings['maintenance_mode'] === 'true') ? 'checked' : '';
$walkins_checked = (isset($current_settings['allow_walkins']) && $current_settings['allow_walkins'] === 'true') ? 'checked' : '';
$refund_fee_raw = trim((string)($current_settings['refund_fee_percent'] ?? '3.00'));
$refund_fee_percent = preg_match('/\A(?:\d+(?:\.\d{1,2})?|\.\d{1,2})\z/D', $refund_fee_raw) && is_finite((float)$refund_fee_raw) && (float)$refund_fee_raw >= 0 && (float)$refund_fee_raw <= 100
    ? number_format((float)$refund_fee_raw, 2, '.', '')
    : '3.00';
$social_links = json_decode($current_settings['social_links_json'] ?? '[]', true);
$social_links = is_array($social_links) ? $social_links : [];
$support_defaults = [
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
$support_content = [];
foreach ($support_defaults as $key => $default) {
    $support_content[$key] = $current_settings[$key] ?? $default;
}
$support_faq = json_decode($support_content['support_faq_json'], true);
$support_faq = is_array($support_faq) && count($support_faq) ? $support_faq : [
    ['question' => 'How long are online dates held?', 'answer' => 'Confirmed selections are temporarily locked while you complete a paid booking. If the lock expires, select the dates again.'],
    ['question' => 'Are hotel rooms priced per night?', 'answer' => "Yes. Hotel stays require at least one night, and the checkout date may coincide with another guest's check-in."],
    ['question' => 'What happens after an Event Hall inquiry?', 'answer' => 'The resort team reviews the inquiry and contacts you about the final quotation and schedule. No online payment is required when the inquiry is submitted.'],
    ['question' => 'Where can I see my booking status?', 'answer' => 'Sign in and open your User Dashboard to view status, payment information, notifications, and booking details.']
];

// 2. Fetch all Venues and their specific child-table data
$venues_query = $conn->query("
    SELECT 
        v.*, 
        hr.room_type, hr.room_number, hr.bed_count, hr.base_capacity as hr_base, hr.max_capacity as hr_max, hr.nightly_rate, hr.extra_pax_rate as hr_extra,
        hr.check_in_time, hr.check_out_time,
        eh.base_capacity as eh_base, eh.max_capacity as eh_max, eh.base_rate, eh.capacity_theater, eh.capacity_classroom, eh.capacity_banquet,
        vi.base_capacity as vi_base, vi.max_capacity as vi_max, vi.day_rate, vi.overnight_rate, vi.extra_pax_rate as vi_extra,
        vi.has_private_pool, vi.day_check_in_time, vi.day_check_out_time, vi.overnight_check_in_time, vi.overnight_check_out_time,
        vi.day_stay_inclusions, vi.overnight_stay_inclusions
    FROM venues v
    LEFT JOIN hotel_rooms hr ON v.id = hr.venue_id
    LEFT JOIN event_halls eh ON v.id = eh.venue_id
    LEFT JOIN villas vi ON v.id = vi.venue_id
    ORDER BY v.category, v.name
");

$all_venues = [];
if ($venues_query && $venues_query->num_rows > 0) {
    while($row = $venues_query->fetch_assoc()) {
        $all_venues[] = $row;
    }
}
?>

<!-- Pass venue data to JS for the Edit Modal -->
<script>
window.allVenuesData = <?php echo json_encode($all_venues, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>

<div class="admin-settings-container">
    <div class="settings-header">
        <p class="settings-subtitle">Manage your account and system preferences.</p>
    </div>

    <div class="settings-layout">
        <!-- LEFT COLUMN: Navigation Tabs -->
        <div class="settings-sidebar">
            <button class="tab-link active" data-target="panel-profile">Profile & Security</button>

            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
            <button class="tab-link" data-target="panel-venues">Manage Venues</button>
            <button class="tab-link" data-target="panel-support">Support &amp; Information</button>
            <button class="tab-link" data-target="panel-prefs">System Preferences</button>
            <?php endif; ?>
        </div>
        <label class="settings-tab-select-label" for="settingsTabSelect">Settings section</label>
        <select class="settings-tab-select" id="settingsTabSelect" aria-label="Choose settings section">
            <option value="panel-profile">Profile &amp; Security</option>
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
            <option value="panel-venues">Manage Venues</option>
            <option value="panel-support">Support &amp; Information</option>
            <option value="panel-prefs">System Preferences</option>
            <?php endif; ?>
        </select>

        <!-- RIGHT COLUMN: Content Panels -->
        <div class="settings-content">

            <!-- PANEL 1: Profile & Security (Visible to all) -->
            <div class="settings-panel active" id="panel-profile">
                <h2 class="panel-heading">Profile & Security</h2>
                <?php
                    // Fetch user info
                    $uid = $_SESSION['user_id'];
                    $stmt_u = $conn->prepare("SELECT u.email, s.full_name, s.phone FROM users u LEFT JOIN staff s ON u.id = s.user_id WHERE u.id = ?");
                    $stmt_u->bind_param("i", $uid);
                    $stmt_u->execute();
                    $u_res = $stmt_u->get_result()->fetch_assoc();
                    $admin_name = htmlspecialchars($u_res['full_name'] ?? 'Admin User');
                    $admin_email = htmlspecialchars($u_res['email'] ?? '');
                    $admin_phone = htmlspecialchars($u_res['phone'] ?? '');
                ?>
                <form class="settings-form" id="form-profile-security" onsubmit="return false;">
                    <section class="profile-section-card">
                        <div class="profile-section-heading">
                            <h3>Profile information</h3>
                            <p>Update the name and contact number shown on your staff account.</p>
                        </div>
                        <div class="form-grid profile-form-grid">
                        <div class="form-group">
                            <label for="prof-name">Full Name</label>
                            <input type="text" class="form-control" id="prof-name" name="name" autocomplete="name" placeholder="John Doe" value="<?php echo $admin_name; ?>">
                        </div>
                        <div class="form-group">
                            <label for="prof-email">Email Address</label>
                            <input type="email" class="form-control" id="prof-email" name="email" autocomplete="email" placeholder="admin@sevilla360.com" value="<?php echo $admin_email; ?>" readonly aria-describedby="prof-email-help">
                            <small class="field-help" id="prof-email-help">Email changes are managed by an administrator.</small>
                        </div>
                        <div class="form-group">
                            <label for="prof-contact">Contact Number</label>
                            <input type="tel" class="form-control" id="prof-contact" name="phone" autocomplete="tel" placeholder="+63 912 345 6789" value="<?php echo $admin_phone; ?>">
                        </div>
                        </div>
                    </section>

                    <hr class="panel-divider">

                    <section class="profile-section-card">
                    <div class="profile-section-heading">
                        <h3>Update password</h3>
                        <p>Leave these fields blank if you only want to update your profile information.</p>
                    </div>
                    <div class="form-grid profile-form-grid">
                        <div class="form-group">
                            <label for="prof-curr-pass">Current Password</label>
                            <div class="password-input-wrap"><input type="password" class="form-control" id="prof-curr-pass" name="current-password" autocomplete="current-password" placeholder="Enter current password"><button type="button" class="password-toggle" data-target="prof-curr-pass" aria-label="Show current password">Show</button></div>
                        </div>
                        <div class="form-group">
                            <label for="prof-new-pass">New Password</label>
                            <div class="password-input-wrap"><input type="password" class="form-control" id="prof-new-pass" name="new-password" autocomplete="new-password" placeholder="Enter new password" aria-describedby="password-help"><button type="button" class="password-toggle" data-target="prof-new-pass" aria-label="Show new password">Show</button></div>
                        </div>
                        <div class="form-group">
                            <label for="prof-conf-pass">Confirm Password</label>
                            <div class="password-input-wrap"><input type="password" class="form-control" id="prof-conf-pass" name="confirm-password" autocomplete="new-password" placeholder="Confirm new password"><button type="button" class="password-toggle" data-target="prof-conf-pass" aria-label="Show confirmation password">Show</button></div>
                        </div>
                    </div>
                    <small class="field-help" id="password-help">Use 8–72 characters with a capital letter, lowercase letter, number, and symbol (like ! or @).</small>
                    </section>

                    <div class="panel-footer">
                        <button type="button" class="btn btn-primary" id="btn-save-profile">Save Changes</button>
                    </div>
                </form>
            </div>

            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>

            <!-- PANEL 2: Manage Venues (Super Admin Only) -->
            <!-- PANEL 2: Manage Venues (Super Admin Only) -->
            <div class="settings-panel" id="panel-venues">
                <div class="venue-panel-heading" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h2 class="panel-heading" style="border: none; padding: 0; margin: 0;">Manage Venues</h2>
                    <button class="btn btn-primary" id="btn-add-venue">+ Add New Venue</button>
                </div>

                <!-- CATEGORY FILTER TABS & SEARCH -->
                <div class="venue-filters" id="venueFilters" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <div>
                        <button class="venue-filter-btn active" data-filter="all">All</button>
                        <button class="venue-filter-btn" data-filter="Event Hall">Event Halls</button>
                        <button class="venue-filter-btn" data-filter="Hotel Room">Hotel Rooms</button>
                        <button class="venue-filter-btn" data-filter="Resort Villa">Resort Villas</button>
                    </div>
                    <div>
                        <input type="text" id="venue-search-input" class="form-control" placeholder="Search venues..." style="max-width: 250px; border-radius: 20px; padding: 6px 15px; border: 1px solid var(--gray-border);">
                    </div>
                </div>
                <div class="venues-table-wrapper">
                    <table class="venues-table">
                        <thead>
                            <tr>
                                <th>Venue Name</th>
                                <th>Category</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $rendered_hotel_groups = []; foreach ($all_venues as $v): $group_attr = ''; $group_key = ''; ?>
                                <?php if ($v['category'] === 'Hotel Room'):
                                    $group_key = $v['name'] . '|' . ($v['room_type'] ?? 'Hotel Room');
                                    $group_attr = htmlspecialchars($group_key, ENT_QUOTES, 'UTF-8');
                                    if (!isset($rendered_hotel_groups[$group_key])):
                                        $rendered_hotel_groups[$group_key] = true;
                                ?>
                                    <tr class="venue-group-row" data-category="Hotel Room" data-group="<?php echo $group_attr; ?>">
                                        <td colspan="4">
                                            <button type="button" class="venue-group-toggle" aria-expanded="false" aria-controls="hotel-group-<?php echo md5($group_key); ?>">
                                                <span class="venue-group-arrow" aria-hidden="true">▸</span>
                                                <span><?php echo htmlspecialchars($v['name'] . ' — ' . ($v['room_type'] ?: 'Hotel Room')); ?></span>
                                                <span class="venue-group-count">Rooms are collapsed</span>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endif; endif; ?>

                                <?php
                                    $display_name = htmlspecialchars($v['name']);
                                    if ($v['category'] === 'Hotel Room') {
                                        $display_name = 'Room ' . htmlspecialchars($v['room_number'] ?: '—');
                                    }
                                    $badge_class = 'v-badge-inactive';
                                    if ($v['status'] === 'Available') $badge_class = 'v-badge-available';
                                    if ($v['status'] === 'Maintenance') $badge_class = 'v-badge-maintenance';
                                    $group_id_attr = $v['category'] === 'Hotel Room' ? md5($group_key) : '';
                                ?>
                                <tr class="venue-row<?php echo $v['category'] === 'Hotel Room' ? ' room-row room-row-collapsed' : ''; ?>" data-category="<?php echo htmlspecialchars($v['category'], ENT_QUOTES, 'UTF-8'); ?>" data-group="<?php echo $group_attr; ?>" data-group-id="<?php echo $group_id_attr; ?>">
                                    <td data-label="Venue Name" style="font-weight: 500;">
                                        <?php echo $display_name; ?>
                                        <span class="venue-id-text">ID: #<?php echo $v['id']; ?></span>
                                    </td>
                                    <td data-label="Category" style="color: var(--color-dark-light);"><?php echo $v['category']; ?></td>
                                    <td data-label="Status"><span class="v-badge <?php echo $badge_class; ?>"><?php echo $v['status']; ?></span></td>
                                    <td data-label="Actions"><button class="btn-edit-venue" data-id="<?php echo $v['id']; ?>">Edit</button></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- PANEL 3: Support & Information -->
            <div class="settings-panel" id="panel-support">
                <h2 class="panel-heading">Support &amp; Information</h2>
                <p class="settings-section-note">Edit the content shown on the public Support &amp; Information page. Leave each FAQ on its own card and use one line per term.</p>
                <form id="form-support-content" class="settings-form" onsubmit="return false;">
                    <div class="form-grid">
                        <div class="form-group support-field-wide">
                            <label for="support-intro">Page introduction</label>
                            <textarea id="support-intro" name="support_intro" class="form-control" rows="2"><?php echo htmlspecialchars($support_content['support_intro'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label for="support-contact-heading">Contact heading</label>
                            <input id="support-contact-heading" name="support_contact_heading" class="form-control" value="<?php echo htmlspecialchars($support_content['support_contact_heading'], ENT_QUOTES, 'UTF-8'); ?>" maxlength="120">
                        </div>
                        <div class="form-group">
                            <label for="support-contact-description">Contact description</label>
                            <textarea id="support-contact-description" name="support_contact_description" class="form-control" rows="2"><?php echo htmlspecialchars($support_content['support_contact_description'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                    </div>

                    <hr class="panel-divider">
                    <div class="support-settings-heading"><div><h3 class="panel-subheading">Frequently Asked Questions</h3><p class="settings-section-note">Add, edit, or remove the questions shown on the public page.</p></div><button type="button" class="btn btn-outline" id="btn-add-support-faq">+ Add FAQ</button></div>
                    <div id="support-faq-list">
                        <?php foreach ($support_faq as $faq): ?><div class="support-faq-row"><div class="form-group"><label>Question</label><input type="text" class="form-control support-faq-question" placeholder="Question" value="<?php echo htmlspecialchars((string)($faq['question'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" maxlength="240"></div><div class="form-group"><label>Answer</label><textarea class="form-control support-faq-answer" placeholder="Answer" rows="3"><?php echo htmlspecialchars((string)($faq['answer'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea></div><button type="button" class="btn btn-danger btn-remove-support-faq">Remove</button></div><?php endforeach; ?>
                    </div>

                    <hr class="panel-divider">
                    <div class="form-grid">
                        <div class="form-group support-field-wide">
                            <label for="support-privacy">Privacy policy <span class="field-help">Use a blank line between paragraphs.</span></label>
                            <textarea id="support-privacy" name="support_privacy" class="form-control" rows="7"><?php echo htmlspecialchars($support_content['support_privacy'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        <div class="form-group support-field-wide">
                            <label for="support-terms">Terms and conditions <span class="field-help">Use one term per line.</span></label>
                            <textarea id="support-terms" name="support_terms" class="form-control" rows="7"><?php echo htmlspecialchars($support_content['support_terms'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                    </div>
                    <div class="panel-footer"><button type="button" id="btn-save-support" class="btn btn-primary save-btn">Save Support Content</button></div>
                </form>
            </div>

            <!-- PANEL 4: System Preferences -->
            <div class="settings-panel" id="panel-prefs">
                <h2 class="panel-heading">System Preferences</h2>

                <form id="form-prefs" class="settings-form" onsubmit="return false;">
                    <div class="preference-item">
                        <div class="preference-info">
                            <h4>Maintenance Mode</h4>
                            <p>Disable user access to the booking frontend while updating systems.</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="maintenance_mode" id="maintenance_mode"
                                <?php echo $maintenance_checked; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <hr class="panel-divider">

                    <div class="preference-item">
                        <div class="preference-info">
                            <h4>Allow Walk-ins</h4>
                            <p>Enable reception to accept walk-in bookings through the dashboard.</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="allow_walkins" id="allow_walkins"
                                <?php echo $walkins_checked; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <hr class="panel-divider">

                    <div class="preference-item settings-section-card">
                        <div class="preference-info">
                            <h4>Payment-processing fee</h4>
                            <p>This percentage is deducted from every paid customer cancellation/refund request and snapshotted when the request is submitted. Admin force cancellations remain 100% refunds.</p>
                        </div>
                        <div class="form-group settings-inline-field">
                            <label for="refund-fee-percent">Fee percentage</label>
                            <div class="input-with-suffix">
                                <input type="number" id="refund-fee-percent" name="refund_fee_percent" class="form-control" min="0" max="100" step="0.01" inputmode="decimal" value="<?php echo htmlspecialchars($refund_fee_percent, ENT_QUOTES, 'UTF-8'); ?>" required>
                                <span aria-hidden="true">%</span>
                            </div>
                        </div>
                    </div>

                    <!-- NEW: BUSINESS INFORMATION CONFIGURATION -->
                    <hr class="panel-divider">
                    <div class="preference-item settings-section-card">
                        <div class="preference-info settings-section-card-heading">
                            <h4 style="color: var(--color-gold);">Business Information</h4>
                            <p>Configure the public business details used in email receipts, invoices, and automated notifications.</p>
                        </div>

                        <div class="form-grid settings-form-grid">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label>Business Name</label>
                                <input type="text" name="biz_name" class="form-control"
                                    value="<?php echo htmlspecialchars($current_settings['biz_name'] ?? 'Sevilla360'); ?>">
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label>Tagline / Slogan</label>
                                <input type="text" name="biz_tagline" class="form-control"
                                    value="<?php echo htmlspecialchars($current_settings['biz_tagline'] ?? 'LUXURY RESORT & EVENTS'); ?>">
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label>Contact Email</label>
                                <input type="email" name="biz_email" class="form-control"
                                    value="<?php echo htmlspecialchars($current_settings['biz_email'] ?? 'reservations@sevilla360.com'); ?>">
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label>Contact Phone</label>
                                <input type="text" name="biz_phone" class="form-control"
                                    value="<?php echo htmlspecialchars($current_settings['biz_phone'] ?? '+63 912 345 6789'); ?>">
                            </div>
                            <div class="form-group settings-field-wide">
                                <label>Business Address</label>
                                <input type="text" name="biz_address" class="form-control"
                                    value="<?php echo htmlspecialchars($current_settings['biz_address'] ?? '123 Resort Drive, Paradise City'); ?>">
                            </div>
                            <div class="form-group settings-field-wide">
                                <label>Resort Policies (Shown at bottom of emails)</label>
                                <textarea name="biz_policies" class="form-control" rows="4" style="resize: vertical;"><?php echo htmlspecialchars($current_settings['biz_policies'] ?? "• Standard Check-in is at 2:00 PM. Check-out is at 12:00 PM (Unless booking Day Time Stay).\n• Please bring a valid Government ID matching the name on this itinerary.\n• Paid customer cancellation/refund requests are subject to the configurable payment-processing fee shown at request time; the fee percentage and refund amount are snapshotted when the request is submitted.\n• Admin-initiated force cancellations receive a 100% refund; the resort absorbs any processing fee."); ?></textarea>
                            </div>
                        </div>
                        <div class="social-settings-block">
                            <div class="social-settings-heading"><div><h4>Social Media Links</h4><p>Add only active profiles. Unconfigured platforms stay hidden from the public footer.</p></div><button type="button" class="btn btn-outline" id="btn-add-social">+ Add Social Media</button></div>
                            <div id="social-links-list">
                                <?php foreach ($social_links as $social): ?>
                                <div class="social-link-row"><input type="text" class="form-control social-label" placeholder="Platform (e.g. Facebook)" value="<?php echo htmlspecialchars((string)($social['label'] ?? '')); ?>" maxlength="40"><input type="url" class="form-control social-url" placeholder="https://..." value="<?php echo htmlspecialchars((string)($social['url'] ?? '')); ?>" maxlength="500"><button type="button" class="btn btn-danger btn-remove-social">Remove</button></div>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="social_links_json" id="social-links-json" value="<?php echo htmlspecialchars(json_encode($social_links), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>

                    <!-- GLOBAL EVENT PRICING CONFIGURATION -->
                    <hr class="panel-divider">
                    <div class="preference-item settings-section-card pricing-section-card">
                        <div class="preference-info settings-section-card-heading">
                            <h4 style="color: var(--color-gold);">Global Event Pricing Configuration</h4>
                            <p>Set the base prices for event modifiers, add-ons, and catering. These prices will
                                automatically apply to all new online and walk-in bookings.</p>
                        </div>

                        <div class="pricing-groups">
                            <div class="pricing-group">
                                <h5>Event Surcharges</h5>
                                <div class="pricing-fields">
                            <div class="form-group">
                                <label>Wedding Surcharge (₱)</label>
                                <input type="number" name="event_type_wedding" class="form-control"
                                    value="<?php echo $current_settings['event_type_wedding'] ?? 10000; ?>">
                            </div>
                            <div class="form-group">
                                <label>Birthday Surcharge (₱)</label>
                                <input type="number" name="event_type_birthday" class="form-control"
                                    value="<?php echo $current_settings['event_type_birthday'] ?? 5000; ?>">
                            </div>
                                </div>
                            </div>
                            <div class="pricing-group">
                                <h5>Catering Rates</h5>
                                <div class="pricing-fields">
                            <div class="form-group">
                                <label>Silver (₱/head)</label>
                                <input type="number" name="catering_silver" class="form-control"
                                    value="<?php echo $current_settings['catering_silver'] ?? 750; ?>">
                            </div>
                            <div class="form-group">
                                <label>Gold (₱/head)</label>
                                <input type="number" name="catering_gold" class="form-control"
                                    value="<?php echo $current_settings['catering_gold'] ?? 1200; ?>">
                            </div>
                            <div class="form-group">
                                <label>Platinum (₱/head)</label>
                                <input type="number" name="catering_platinum" class="form-control"
                                    value="<?php echo $current_settings['catering_platinum'] ?? 1800; ?>">
                            </div>
                                </div>
                            </div>
                            <div class="pricing-group">
                                <h5>Setup</h5>
                                <div class="pricing-fields pricing-fields-single">
                            <div class="form-group">
                                <label>Premium A/V Setup (₱)</label>
                                <input type="number" name="av_setup" class="form-control"
                                    value="<?php echo $current_settings['av_setup'] ?? 5000; ?>">
                            </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="panel-footer" style="margin-top: 25px;">
                        <button type="button" id="btn-save-prefs" class="btn btn-primary save-btn">Save Changes</button>
                    </div>
                </form>
            </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<!-- Toast Notification -->
<div id="settings-toast" class="toast-notification">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
        stroke-linecap="round" stroke-linejoin="round">
        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
        <polyline points="22 4 12 14.01 9 11.01"></polyline>
    </svg>
    <span>Settings Saved Successfully</span>
</div>

<!-- UNSAVED CHANGES MODAL -->
<div class="modal-overlay" id="unsaved-modal">
    <div class="modal-content unsaved-modal-content">
        <i class="fa-solid fa-triangle-exclamation unsaved-icon"></i>
        <h2 class="modal-title">Unsaved Changes</h2>
        <p class="modal-text unsaved-text">
            You have unsaved changes on this page. If you leave now, your changes will be lost.
        </p>
        <div class="unsaved-actions">
            <button class="btn btn-primary btn-unsaved-stay" id="btn-stay-save">Stay</button>
            <button class="btn btn-outline btn-unsaved-discard" id="btn-discard-leave">Discard & Leave</button>
        </div>
    </div>
</div>

<!-- ADD/EDIT VENUE MODAL -->
<div class="modal-overlay" id="venueModal">
    <div class="modal-content venue-modal-content">
        <h3 class="modal-title" id="vm-title">Add New Venue</h3>

        <form id="form-venue" onsubmit="return false;">
            <input type="hidden" id="vm-id" name="venue_id">

            <div class="form-grid venue-modal-grid" style="margin-bottom: 15px;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label>Venue Name</label>
                    <input type="text" id="vm-name" name="name" class="form-control" placeholder="e.g. Infinity Hall"
                        required>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label>Category</label>
                    <select id="vm-category" name="category" class="form-control" required>
                        <option value="" disabled selected>Select...</option>
                        <option value="Event Hall">Event Hall</option>
                        <option value="Hotel Room">Hotel Room</option>
                        <option value="Resort Villa">Resort Villa</option>
                    </select>
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 15px;">
                <label>Status</label>
                <select id="vm-status" name="status" class="form-control" required>
                    <option value="Available">Available</option>
                    <option value="Maintenance">Maintenance</option>
                    <option value="Inactive">Inactive (Hidden from users)</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 15px;">
                <label>Description (Showroom + Online/Admin booking)</label>
                <textarea id="vm-desc" name="description" class="form-control" rows="3"
                    placeholder="Experience ultimate luxury..."></textarea>
            </div>

            <div class="form-group" style="margin-bottom: 25px;">
                <label>Amenities (Online/Admin booking; comma or newline separated)</label>
                <textarea id="vm-amenities" name="amenities" class="form-control" rows="3"
                    placeholder="Free Wi-Fi, Pool, Smart TV"></textarea>
            </div>

            <!-- DYNAMIC SECTIONS: These hide/show based on category -->
            <div class="venue-pricing-section" style="padding: 15px; background: #faf9f7; border-radius: 8px; border: 1px solid #eee;">
                <h4 style="font-size: 1rem; margin-bottom: 15px; color: var(--color-dark);">Pricing & Capacities</h4>

                <div class="form-grid venue-pricing-grid">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label>Base Capacity (Pax)</label>
                        <input type="number" id="vm-base-cap" name="base_capacity" class="form-control" required>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label>Max Capacity (Pax)</label>
                        <input type="number" id="vm-max-cap" name="max_capacity" class="form-control" required>
                    </div>

                    <!-- Event Hall Specific -->
                    <div class="form-group vm-dynamic vm-event" style="display:none; margin-bottom: 0;">
                        <label>Base Rate (₱/Day)</label>
                        <input type="number" id="vm-eh-rate" name="base_rate" class="form-control" step="0.01">
                    </div>
                    <div class="form-group vm-dynamic vm-event" style="display:none; margin-bottom: 0;">
                        <label>Theater Capacity</label>
                        <input type="number" id="vm-eh-theater" name="capacity_theater" class="form-control">
                    </div>
                    <div class="form-group vm-dynamic vm-event" style="display:none; margin-bottom: 0;">
                        <label>Classroom Capacity</label>
                        <input type="number" id="vm-eh-classroom" name="capacity_classroom" class="form-control">
                    </div>
                    <div class="form-group vm-dynamic vm-event" style="display:none; margin-bottom: 0;">
                        <label>Banquet Capacity</label>
                        <input type="number" id="vm-eh-banquet" name="capacity_banquet" class="form-control">
                    </div>

                    <!-- Hotel Room Specific -->
                    <div class="form-group vm-dynamic vm-hotel" style="display:none; margin-bottom: 0;">
                        <label>Room Type</label>
                        <input type="text" id="vm-hr-type" name="room_type" class="form-control"
                            placeholder="e.g. Deluxe Room">
                    </div>
                    <div class="form-group vm-dynamic vm-hotel" style="display:none; margin-bottom: 0;">
                        <label>Nightly Rate (₱)</label>
                        <input type="number" id="vm-hr-rate" name="nightly_rate" class="form-control" step="0.01">
                    </div>
                    <div class="form-group vm-dynamic vm-hotel" style="display:none; margin-bottom: 0;">
                        <label>Room Number</label>
                        <input type="text" id="vm-hr-room-number" name="room_number" class="form-control"
                            placeholder="e.g. 101 or A-101">
                    </div>
                    <div class="form-group vm-dynamic vm-hotel" style="display:none; margin-bottom: 0;">
                        <label>Beds</label>
                        <input type="number" id="vm-hr-bed-count" name="bed_count" class="form-control" min="1" step="1" value="1">
                    </div>
                    <div class="form-group vm-dynamic vm-hotel" style="display:none; margin-bottom: 0;">
                        <label>Check-in</label>
                        <input type="time" id="vm-hr-check-in" name="check_in_time" class="form-control" value="14:00">
                    </div>
                    <div class="form-group vm-dynamic vm-hotel" style="display:none; margin-bottom: 0;">
                        <label>Check-out</label>
                        <input type="time" id="vm-hr-check-out" name="check_out_time" class="form-control" value="12:00">
                    </div>

                    <!-- BULK HOTEL ROOM CREATION (Only shown on add) -->
                    <div class="form-group vm-dynamic vm-hotel vm-bulk-section venue-bulk-section" style="display:none; margin-bottom: 0; padding: 10px; background: #eef2ff; border-radius: 6px; border: 1px dashed #a5b4fc;">
                        <div class="venue-bulk-header">
                            <div class="venue-bulk-copy">
                                <label style="margin: 0; color: #4338ca; font-weight: 600;">Bulk Create Rooms?</label>
                                <p style="margin: 0; font-size: 0.8rem; color: #4f46e5;">Creates multiple identical rooms sequentially (e.g. 101 to 105).</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" id="vm-hr-bulk-toggle" data-required="false">
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <div id="vm-hr-bulk-fields" class="venue-bulk-fields" style="display: none; margin-top: 15px;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label style="color: #4338ca;">Quantity to Create</label>
                                <input type="number" id="vm-hr-bulk-qty" name="bulk_quantity" class="form-control" min="1" max="100" placeholder="e.g. 5">
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label style="color: #4338ca;">Starting Room Number</label>
                                <input type="text" id="vm-hr-bulk-start" name="bulk_start_number" class="form-control" placeholder="e.g. 101 or A-101">
                            </div>
                        </div>
                    </div>

                    <!-- Villa Specific -->
                    <div class="form-group vm-dynamic vm-villa" style="display:none; margin-bottom: 0;">
                        <label>Day Rate (₱)</label>
                        <input type="number" id="vm-vi-day" name="day_rate" class="form-control" step="0.01">
                    </div>
                    <div class="form-group vm-dynamic vm-villa" style="display:none; margin-bottom: 0;">
                        <label>Overnight Rate (₱, total stay rate)</label>
                        <input type="number" id="vm-vi-night" name="overnight_rate" class="form-control" step="0.01">
                    </div>
                    <div class="form-group vm-dynamic vm-villa venue-pool-field" style="display:none; margin-bottom: 0;">
                        <label class="checkbox-field-label">
                            <input type="checkbox" id="vm-vi-pool" name="has_private_pool" value="1" data-required="false">
                            <span>Private pool available</span>
                        </label>
                    </div>
                    <div class="form-group vm-dynamic vm-villa" style="display:none; margin-bottom: 0;">
                        <label>Day stay check-in</label>
                        <input type="time" id="vm-vi-day-check-in" name="day_check_in_time" class="form-control" value="07:00">
                    </div>
                    <div class="form-group vm-dynamic vm-villa" style="display:none; margin-bottom: 0;">
                        <label>Day stay check-out</label>
                        <input type="time" id="vm-vi-day-check-out" name="day_check_out_time" class="form-control" value="17:00">
                    </div>
                    <div class="form-group vm-dynamic vm-villa" style="display:none; margin-bottom: 0;">
                        <label>Overnight check-in</label>
                        <input type="time" id="vm-vi-night-check-in" name="overnight_check_in_time" class="form-control" value="14:00">
                    </div>
                    <div class="form-group vm-dynamic vm-villa" style="display:none; margin-bottom: 0;">
                        <label>Overnight check-out</label>
                        <input type="time" id="vm-vi-night-check-out" name="overnight_check_out_time" class="form-control" value="12:00">
                    </div>
                    <div class="form-group vm-dynamic vm-villa venue-inclusions-field" style="display:none; margin-bottom: 0;">
                        <label>Day stay inclusions <span class="field-help">Comma or newline separated</span></label>
                        <textarea id="vm-vi-day-inclusions" name="day_stay_inclusions" class="form-control" rows="3" data-required="false" placeholder="TV, bed, air conditioner"></textarea>
                    </div>
                    <div class="form-group vm-dynamic vm-villa venue-inclusions-field" style="display:none; margin-bottom: 0;">
                        <label>Overnight inclusions <span class="field-help">Comma or newline separated</span></label>
                        <textarea id="vm-vi-night-inclusions" name="overnight_stay_inclusions" class="form-control" rows="3" data-required="false" placeholder="Complimentary breakfast for 4 persons"></textarea>
                    </div>

                    <!-- Shared Hotel/Villa -->
                    <div class="form-group vm-dynamic vm-hotel vm-villa venue-extra-pax"
                        style="display:none; margin-bottom: 0;">
                        <label>Extra Pax Rate (₱/head)</label>
                        <input type="number" id="vm-extra-pax" name="extra_pax_rate" class="form-control" step="0.01">
                    </div>
                </div>
            </div>

            <div class="modal-actions-center venue-modal-actions">
                <button type="button" class="btn btn-outline btn-modal-cancel" id="btn-close-vmodal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btn-save-venue">Save Venue</button>
            </div>
        </form>
    </div>
</div>
