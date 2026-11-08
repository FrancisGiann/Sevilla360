<?php
require_once __DIR__ . '/../auth_guard.php';
if (($_SESSION['role'] ?? '') !== 'staff') {
    http_response_code(403);
    exit('Unauthorized access.');
}
require_once __DIR__ . '/../profile_fields.php';

$staff_settings_user_id = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$staff_settings_user = null;
if ($staff_settings_user_id !== false) {
    $staff_settings_query = $conn->prepare('SELECT u.email, s.full_name, s.phone FROM users u INNER JOIN staff s ON s.user_id = u.id WHERE u.id = ? AND u.role = \'staff\' AND s.status = \'active\' LIMIT 1');
    if ($staff_settings_query) {
        $staff_settings_query->bind_param('i', $staff_settings_user_id);
        if ($staff_settings_query->execute()) $staff_settings_user = $staff_settings_query->get_result()->fetch_assoc();
        $staff_settings_query->close();
    }
}
$staff_settings_escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
if (!is_array($staff_settings_user)) {
    echo '<div class="unauthorized-access"><h3>Account settings unavailable</h3></div>';
    return;
}
?>

<div class="admin-settings-container">
    <div class="settings-header">
        <p class="settings-subtitle">Update your staff account information and password.</p>
    </div>
    <div class="settings-content">
        <section class="settings-panel active" id="panel-profile">
            <h2 class="panel-heading">Profile &amp; Security</h2>
            <form class="settings-form" id="form-profile-security" onsubmit="return false;">
                <section class="profile-section-card">
                    <div class="profile-section-heading">
                        <h3>Profile information</h3>
                        <p>Update the name and contact number shown on your staff account.</p>
                    </div>
                    <div class="form-grid profile-form-grid">
                        <div class="form-group">
                            <label for="prof-name">Full name</label>
                            <input type="text" class="form-control" id="prof-name" name="name" maxlength="150" required autocomplete="name" value="<?= $staff_settings_escape($staff_settings_user['full_name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="prof-email">Work email</label>
                            <input type="email" class="form-control" id="prof-email" name="email" autocomplete="email" value="<?= $staff_settings_escape($staff_settings_user['email'] ?? '') ?>" readonly aria-describedby="prof-email-help">
                            <small class="field-help" id="prof-email-help">Email changes are managed by an administrator.</small>
                        </div>
                        <div class="form-group">
                            <label for="prof-contact">Contact number</label>
                            <input type="tel" class="form-control" id="prof-contact" name="phone" maxlength="20" autocomplete="tel" value="<?= $staff_settings_escape($staff_settings_user['phone'] ?? '') ?>">
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
                            <label for="prof-curr-pass">Current password</label>
                            <input data-password-field type="password" class="form-control" id="prof-curr-pass" autocomplete="current-password" placeholder="Enter current password">
                        </div>
                        <div class="form-group">
                            <label for="prof-new-pass">New password</label>
                            <input data-password-field type="password" class="form-control" id="prof-new-pass" autocomplete="new-password" placeholder="Enter new password" aria-describedby="password-help">
                        </div>
                        <div class="form-group">
                            <label for="prof-conf-pass">Confirm new password</label>
                            <input data-password-field type="password" class="form-control" id="prof-conf-pass" autocomplete="new-password" placeholder="Confirm new password">
                        </div>
                    </div>
                    <small class="field-help" id="password-help">Use 8–72 characters with a capital letter, lowercase letter, number, and symbol.</small>
                </section>
                <div class="panel-footer">
                    <p id="staff-profile-save-status" role="status" aria-live="polite"></p>
                    <button type="button" class="btn btn-primary" id="btn-save-profile">Save changes</button>
                </div>
            </form>
        </section>
    </div>
</div>
