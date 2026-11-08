<?php
require_once __DIR__ . '/includes/session_init.php';
require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/includes/staff_invitation.php';
header('X-Robots-Tag: noindex, nofollow', true);
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, max-age=0');

$tokenInput = $_GET['token'] ?? '';
$token = is_string($tokenInput) ? $tokenInput : '';
$invitation = null;
try {
    $invitation = staff_invitation_lookup($conn, $token);
} catch (Throwable $error) {
    error_log('Staff invitation lookup failed: ' . get_class($error));
}
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= $escape($_SESSION['csrf_token'] ?? '') ?>">
    <title>Set up staff account | Sevilla360</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/auth.css">
    <style>
        .staff-invite-card { max-width: 520px; }
        .staff-invite-card .auth-subtitle { line-height: 1.6; }
        .staff-invite-status { margin: 12px 0; color: var(--color-dark); }
        .staff-invite-status[data-state="error"] { color: #a83232; }
        .staff-invite-status[data-state="success"] { color: #397149; }
    </style>
</head>
<body>
    <main class="auth-page">
        <div class="auth-header">
            <div class="auth-logo">Sevilla360</div>
            <div class="auth-tagline">M.I. Sevilla Resort &amp; Events Place</div>
        </div>
        <section class="auth-card staff-invite-card" aria-labelledby="staff-invite-title">
            <?php if ($invitation): ?>
                <h1 class="auth-title" id="staff-invite-title">Set up your staff account</h1>
                <p class="auth-subtitle">Hello <?= $escape($invitation['full_name']) ?>. Choose a password to activate your staff sign-in. This invitation can be used once and expires 24 hours after it was sent.</p>
                <p class="staff-invite-status" id="staff-invite-status" role="status" aria-live="polite"></p>
                <form id="staff-invite-form" novalidate>
                    <input type="hidden" id="staff-invite-token" value="<?= $escape($token) ?>">
                    <div class="form-group">
                        <label for="staff-invite-password">Choose a password</label>
                        <input data-password-field data-password-policy-input type="password" class="form-control" id="staff-invite-password" autocomplete="new-password" required aria-describedby="staff-invite-password-help">
                    </div>
                    <div class="form-group">
                        <label for="staff-invite-confirm">Confirm password</label>
                        <input data-password-field type="password" class="form-control" id="staff-invite-confirm" autocomplete="new-password" required>
                    </div>
                    <small class="field-help" id="staff-invite-password-help">Use 8–72 characters with a capital letter, lowercase letter, number, and symbol.</small>
                    <button type="submit" class="btn btn-primary btn-full" id="staff-invite-submit">Activate account</button>
                </form>
            <?php else: ?>
                <h1 class="auth-title" id="staff-invite-title">Invitation unavailable</h1>
                <p class="auth-subtitle">This staff setup link is invalid, expired, or already used. Ask your administrator to send a new invitation.</p>
                <a href="auth.php" class="btn btn-primary btn-full">Go to sign in</a>
            <?php endif; ?>
        </section>
    </main>
    <script src="assets/js/password_policy.js"></script>
    <script src="assets/js/staff_invite.js"></script>
</body>
</html>
