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

// 2. Fetch all Venues and their specific child-table data
$venues_query = $conn->query("
    SELECT 
        v.*, 
        hr.room_type, hr.base_capacity as hr_base, hr.max_capacity as hr_max, hr.nightly_rate, hr.extra_pax_rate as hr_extra,
        eh.base_capacity as eh_base, eh.max_capacity as eh_max, eh.base_rate, eh.capacity_theater, eh.capacity_classroom, eh.capacity_banquet,
        vi.base_capacity as vi_base, vi.max_capacity as vi_max, vi.day_rate, vi.overnight_rate, vi.extra_pax_rate as vi_extra
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
window.allVenuesData = <?php echo json_encode($all_venues); ?>;
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
            <button class="tab-link" data-target="panel-prefs">System Preferences</button>
            <?php endif; ?>
        </div>

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
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Full Name</label>
                            <input type="text" class="form-control" id="prof-name" placeholder="John Doe" value="<?php echo $admin_name; ?>">
                        </div>
                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" class="form-control" id="prof-email" placeholder="admin@sevilla360.com" value="<?php echo $admin_email; ?>" readonly style="background-color: #f1f1f1; cursor: not-allowed;">
                        </div>
                        <div class="form-group">
                            <label>Contact Number</label>
                            <input type="text" class="form-control" id="prof-contact" placeholder="+63 912 345 6789" value="<?php echo $admin_phone; ?>">
                        </div>
                    </div>

                    <hr class="panel-divider">

                    <h3 class="panel-subheading">Update Password</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Current Password</label>
                            <input type="password" class="form-control" id="prof-curr-pass" placeholder="Enter current password">
                        </div>
                        <div class="form-group">
                            <label>New Password</label>
                            <input type="password" class="form-control" id="prof-new-pass" placeholder="Enter new password">
                        </div>
                        <div class="form-group">
                            <label>Confirm Password</label>
                            <input type="password" class="form-control" id="prof-conf-pass" placeholder="Confirm new password">
                        </div>
                    </div>

                    <div class="panel-footer">
                        <button type="button" class="btn btn-primary" id="btn-save-profile">Save Changes</button>
                    </div>
                </form>
            </div>

            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>

            <!-- PANEL 2: Manage Venues (Super Admin Only) -->
            <!-- PANEL 2: Manage Venues (Super Admin Only) -->
            <div class="settings-panel" id="panel-venues">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h2 class="panel-heading" style="border: none; padding: 0; margin: 0;">Manage Venues</h2>
                    <button class="btn btn-primary" id="btn-add-venue">+ Add New Venue</button>
                </div>

                <!-- CATEGORY FILTER TABS -->
                <div class="venue-filters" id="venueFilters">
                    <button class="venue-filter-btn active" data-filter="all">All</button>
                    <button class="venue-filter-btn" data-filter="Event Hall">Event Halls</button>
                    <button class="venue-filter-btn" data-filter="Hotel Room">Hotel Rooms</button>
                    <button class="venue-filter-btn" data-filter="Resort Villa">Resort Villas</button>
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
                            <?php foreach($all_venues as $v): ?>

                            <tr class="venue-row" data-category="<?php echo $v['category']; ?>">

                                <?php 
                                    $display_name = htmlspecialchars($v['name']);
                                    if ($v['category'] === 'Hotel Room' && !empty($v['room_type'])) {
                                        $display_name .= ' (' . htmlspecialchars($v['room_type']) . ')';
                                    }
                                    
                                    // Determine Badge Class
                                    $badge_class = 'v-badge-inactive';
                                    if ($v['status'] === 'Available') $badge_class = 'v-badge-available';
                                    if ($v['status'] === 'Maintenance') $badge_class = 'v-badge-maintenance';
                                ?>

                                <td style="font-weight: 500;">
                                    <?php echo $display_name; ?>
                                    <span class="venue-id-text">ID: #<?php echo $v['id']; ?></span>
                                </td>

                                <td style="color: var(--color-dark-light);"><?php echo $v['category']; ?></td>

                                <td>
                                    <span class="v-badge <?php echo $badge_class; ?>">
                                        <?php echo $v['status']; ?>
                                    </span>
                                </td>

                                <td>
                                    <button class="btn-edit-venue" data-id="<?php echo $v['id']; ?>">Edit</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- PANEL 3: System Preferences -->
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

                    <!-- NEW: BUSINESS INFORMATION CONFIGURATION -->
                    <hr class="panel-divider">
                    <div class="preference-item" style="display: block;">
                        <div class="preference-info" style="margin-bottom: 20px;">
                            <h4 style="color: var(--color-gold);">Business Information</h4>
                            <p>Configure the public business details used in email receipts, invoices, and automated notifications.</p>
                        </div>

                        <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 15px;">
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
                            <div class="form-group" style="margin-bottom: 0; grid-column: span 2;">
                                <label>Business Address</label>
                                <input type="text" name="biz_address" class="form-control"
                                    value="<?php echo htmlspecialchars($current_settings['biz_address'] ?? '123 Resort Drive, Paradise City'); ?>">
                            </div>
                            <div class="form-group" style="margin-bottom: 0; grid-column: span 2;">
                                <label>Resort Policies (Shown at bottom of emails)</label>
                                <textarea name="biz_policies" class="form-control" rows="4" style="resize: vertical;"><?php echo htmlspecialchars($current_settings['biz_policies'] ?? "• Standard Check-in is at 2:00 PM. Check-out is at 12:00 PM (Unless booking Day Time Stay).\n• Please bring a valid Government ID matching the name on this itinerary.\n• Cancellations made less than 7 days before arrival are subject to fees."); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- GLOBAL EVENT PRICING CONFIGURATION -->
                    <hr class="panel-divider">
                    <div class="preference-item" style="display: block;">
                        <div class="preference-info" style="margin-bottom: 20px;">
                            <h4 style="color: var(--color-gold);">Global Event Pricing Configuration</h4>
                            <p>Set the base prices for event modifiers, add-ons, and catering. These prices will
                                automatically apply to all new online and walk-in bookings.</p>
                        </div>

                        <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 15px;">

                            <div class="form-group" style="margin-bottom: 0;">
                                <label>Premium A/V Setup (₱)</label>
                                <input type="number" name="av_setup" class="form-control"
                                    value="<?php echo $current_settings['av_setup'] ?? 5000; ?>">
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label>Type: Wedding Surcharge (₱)</label>
                                <input type="number" name="event_type_wedding" class="form-control"
                                    value="<?php echo $current_settings['event_type_wedding'] ?? 10000; ?>">
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label>Type: Birthday Surcharge (₱)</label>
                                <input type="number" name="event_type_birthday" class="form-control"
                                    value="<?php echo $current_settings['event_type_birthday'] ?? 5000; ?>">
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label>Catering: Silver (₱/head)</label>
                                <input type="number" name="catering_silver" class="form-control"
                                    value="<?php echo $current_settings['catering_silver'] ?? 750; ?>">
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label>Catering: Gold (₱/head)</label>
                                <input type="number" name="catering_gold" class="form-control"
                                    value="<?php echo $current_settings['catering_gold'] ?? 1200; ?>">
                            </div>
                            <div class="form-group" style="margin-bottom: 0; grid-column: span 2;">
                                <label>Catering: Platinum (₱/head)</label>
                                <input type="number" name="catering_platinum" class="form-control"
                                    value="<?php echo $current_settings['catering_platinum'] ?? 1800; ?>">
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
    <div class="modal-content" style="max-width: 600px; max-height: 85vh; overflow-y: auto;">
        <h3 class="modal-title" id="vm-title">Add New Venue</h3>

        <form id="form-venue" onsubmit="return false;">
            <input type="hidden" id="vm-id" name="venue_id">

            <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
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
                <label>Description (Used in Showroom)</label>
                <textarea id="vm-desc" name="description" class="form-control" rows="3"
                    placeholder="Experience ultimate luxury..."></textarea>
            </div>

            <div class="form-group" style="margin-bottom: 25px;">
                <label>Amenities (Comma-separated)</label>
                <input type="text" id="vm-amenities" name="amenities" class="form-control"
                    placeholder="Free Wi-Fi, Pool, Smart TV">
            </div>

            <!-- DYNAMIC SECTIONS: These hide/show based on category -->
            <div style="padding: 15px; background: #faf9f7; border-radius: 8px; border: 1px solid #eee;">
                <h4 style="font-size: 1rem; margin-bottom: 15px; color: var(--color-dark);">Pricing & Capacities</h4>

                <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 15px;">
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

                    <!-- Villa Specific -->
                    <div class="form-group vm-dynamic vm-villa" style="display:none; margin-bottom: 0;">
                        <label>Day Rate (₱)</label>
                        <input type="number" id="vm-vi-day" name="day_rate" class="form-control" step="0.01">
                    </div>
                    <div class="form-group vm-dynamic vm-villa" style="display:none; margin-bottom: 0;">
                        <label>Overnight Rate (₱)</label>
                        <input type="number" id="vm-vi-night" name="overnight_rate" class="form-control" step="0.01">
                    </div>

                    <!-- Shared Hotel/Villa -->
                    <div class="form-group vm-dynamic vm-hotel vm-villa"
                        style="display:none; margin-bottom: 0; grid-column: span 2;">
                        <label>Extra Pax Rate (₱/head)</label>
                        <input type="number" id="vm-extra-pax" name="extra_pax_rate" class="form-control" step="0.01">
                    </div>
                </div>
            </div>

            <div class="modal-actions-center" style="margin-top: 25px;">
                <button type="button" class="btn btn-outline btn-modal-cancel" id="btn-close-vmodal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btn-save-venue">Save Venue</button>
            </div>
        </form>
    </div>
</div>