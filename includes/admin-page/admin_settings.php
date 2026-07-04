<?php
require_once 'config/db_connect.php';

// fetch the current settings from the database
$settings_query = $conn->query("SELECT setting_key, setting_value FROM system_settings");
$current_settings = [];

if ($settings_query) {
    while($row = $settings_query->fetch_assoc()) {
        $current_settings[$row['setting_key']] = $row['setting_value'];
    }
}

// check if the settings exist, if not, set defaults
$maintenance_checked = (isset($current_settings['maintenance_mode']) && $current_settings['maintenance_mode'] === 'true') ? 'checked' : '';
$walkins_checked = (isset($current_settings['allow_walkins']) && $current_settings['allow_walkins'] === 'true') ? 'checked' : '';
?>
<div class="admin-settings-container">
    <div class="settings-header">
        <p class="settings-subtitle">Manage your account and system preferences.</p>
    </div>

    <div class="settings-layout">
        <!-- LEFT COLUMN: Navigation Tabs (25%) -->
        <div class="settings-sidebar">
            <button class="tab-link active" data-target="panel-profile">Profile & Security</button>

            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin'): ?>
            <button class="tab-link" data-target="panel-pricing">Resort Pricing</button>
            <button class="tab-link" data-target="panel-discounts">Discounts & Promos</button>
            <button class="tab-link" data-target="panel-prefs">System Preferences</button>
            <?php endif; ?>
        </div>

        <!-- RIGHT COLUMN: Content Panels (75%) -->
        <div class="settings-content">

            <!-- PANEL 1: Profile & Security (Visible to all) -->
            <div class="settings-panel active" id="panel-profile">
                <h2 class="panel-heading">Profile & Security</h2>
                <form class="settings-form" onsubmit="return false;">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Full Name</label>
                            <input type="text" class="form-control" placeholder="John Doe" value="Admin User">
                        </div>
                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" class="form-control" placeholder="admin@sevilla360.com">
                        </div>
                        <div class="form-group">
                            <label>Contact Number</label>
                            <input type="text" class="form-control" placeholder="+1 234 567 890">
                        </div>
                    </div>

                    <hr class="panel-divider">

                    <h3 class="panel-subheading">Update Password</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Current Password</label>
                            <input type="password" class="form-control" placeholder="Enter current password">
                        </div>
                        <div class="form-group">
                            <label>New Password</label>
                            <input type="password" class="form-control" placeholder="Enter new password">
                        </div>
                        <div class="form-group">
                            <label>Confirm Password</label>
                            <input type="password" class="form-control" placeholder="Confirm new password">
                        </div>
                    </div>

                    <div class="panel-footer">
                        <button type="button" class="btn btn-primary save-btn">Save Changes</button>
                    </div>
                </form>
            </div>

            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin'): ?>

            <!-- PANEL 2: Resort Pricing (Super Admin Only) -->
            <div class="settings-panel" id="panel-pricing">
                <h2 class="panel-heading">Resort Pricing</h2>
                <form class="settings-form" onsubmit="return false;">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Event Hall Base Price ($)</label>
                            <input type="number" class="form-control" placeholder="1500.00" value="1500">
                        </div>
                        <div class="form-group">
                            <label>Standard Room Rate ($ / night)</label>
                            <input type="number" class="form-control" placeholder="150.00" value="150">
                        </div>
                        <div class="form-group">
                            <label>Deluxe Room Rate ($ / night)</label>
                            <input type="number" class="form-control" placeholder="280.00" value="280">
                        </div>
                        <div class="form-group">
                            <label>Extra Pax Fee ($ / person)</label>
                            <input type="number" class="form-control" placeholder="50.00" value="50">
                        </div>
                    </div>

                    <div class="panel-footer">
                        <button type="button" class="btn btn-primary save-btn">Save Changes</button>
                    </div>
                </form>
            </div>

            <!-- PANEL 3: Discounts & Promotions -->
            <div class="settings-panel" id="panel-discounts">
                <h2 class="panel-heading">Discounts & Promotions</h2>
                <form class="settings-form" onsubmit="return false;">

                    <!-- Promo Code Toggle -->
                    <div class="preference-item">
                        <div class="preference-info">
                            <h4>Enable Promo Codes</h4>
                            <p>Allow guests to enter promotional discount codes at checkout.</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" checked>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <hr class="panel-divider">
                    <h3 class="panel-subheading">Automated Discounts</h3>

                    <div class="form-grid">
                        <!-- Global Off-Season Discount -->
                        <div class="form-group">
                            <label>Global / Seasonal Discount (%)</label>
                            <input type="number" class="form-control" value="0" min="0" max="100">
                        </div>

                        <!-- Space filler to keep the grid balanced -->
                        <div class="form-group"></div>

                        <!-- Early Bird Settings -->
                        <div class="form-group">
                            <label>Early Bird Discount (%)</label>
                            <input type="number" class="form-control" value="10" min="0" max="100">
                        </div>
                        <div class="form-group">
                            <label>Early Bird Advance (Days)</label>
                            <input type="number" class="form-control" value="30" min="1">
                        </div>

                        <!-- Long Stay Settings -->
                        <div class="form-group">
                            <label>Long Stay Discount (%)</label>
                            <input type="number" class="form-control" value="15" min="0" max="100">
                        </div>
                        <div class="form-group">
                            <label>Long Stay Minimum (Nights)</label>
                            <input type="number" class="form-control" value="7" min="2">
                        </div>
                    </div>

                    <div class="panel-footer">
                        <button type="button" class="btn btn-primary save-btn">Save Changes</button>
                    </div>
                </form>
            </div>

            <!-- PANEL 4: System Preferences -->
            <div class="settings-panel" id="panel-prefs">
                <h2 class="panel-heading">System Preferences</h2>

                <!-- Added id="form-prefs" -->
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

                    <div class="panel-footer">
                        <!-- Added id="btn-save-prefs" -->
                        <button type="button" id="btn-save-prefs" class="btn btn-primary save-btn">Save Changes</button>
                    </div>
                </form>
            </div>

            <!-- Super Admin Check Ends Here -->
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