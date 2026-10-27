<?php
require_once __DIR__ . '/includes/session_init.php';
require_once __DIR__ . '/includes/site_metadata.php';
header('X-Robots-Tag: noindex, nofollow', true);
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

require_once 'config/db_connect.php';
require_once __DIR__ . '/includes/customer_login_recovery.php';
require_once __DIR__ . '/includes/booking_intent.php';
require_once __DIR__ . '/includes/contact_verification.php';
booking_auth_capture_request();
$forgot_origin = in_array($_GET['origin'] ?? '', ['customer', 'admin'], true) ? $_GET['origin'] : 'customer';
$verify_phone_mode = false;
$verify_email_query = is_string($_GET['verify_email'] ?? null) ? trim($_GET['verify_email']) : '';
if (filter_var($verify_email_query, FILTER_VALIDATE_EMAIL)) {
    $mode_stmt = $conn->prepare('SELECT phone_signup_required FROM users WHERE email=? AND is_verified=0 LIMIT 1');
    $mode_stmt->bind_param('s', $verify_email_query); $mode_stmt->execute();
    $verify_phone_mode = (int)($mode_stmt->get_result()->fetch_assoc()['phone_signup_required'] ?? 0) === 1;
    $mode_stmt->close();
}


if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    if (in_array($_SESSION['role'] ?? '', ['admin', 'staff'])) {
        booking_auth_clear_destination();
        header("Location: admin_dashboard.php");
    } elseif (($_SESSION['role'] ?? '') === 'customer') {
        $resume_target = booking_auth_consume_destination('customer');
        header("Location: " . ($resume_target ?? 'user_dashboard.php'));
    } else {
        header("Location: index.php");
    }
    exit();
}

// Fetch CMS images so auth page can reuse the homepage hero background
$cms_query = $conn->query("SELECT slot_assignment, file_path FROM media_cms");
$cms_images = [];
if ($cms_query) {
    while ($row = $cms_query->fetch_assoc()) {
        $cms_images[$row['slot_assignment']] = $row['file_path'];
    }
}

function get_cms_image($slot_name, $default_url, $cms_images) {
    return isset($cms_images[$slot_name]) ? htmlspecialchars($cms_images[$slot_name]) : $default_url;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <meta name="csrf-token" content="<?php echo $_SESSION['csrf_token']; ?>">
    <title>Authentication - SEVILLA360</title>

    <link rel="stylesheet" href="<?= htmlspecialchars(site_metadata_asset_url('assets/css/style.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(site_metadata_asset_url('assets/css/auth.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="assets/css/ui-refinement.css?v=<?= filemtime(__DIR__ . '/assets/css/ui-refinement.css'); ?>">
    <!-- FontAwesome for global alerts -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body data-phone-signup="<?php echo  $verify_phone_mode ? '1' : '0'; ?>">

    <div class="auth-page"
        style="background: linear-gradient(rgba(42,37,34,0.6), rgba(42,37,34,0.6)), url('<?php echo get_cms_image('home-hero', 'https://images.unsplash.com/photo-1519225421980-715cb0215aed?ixlib=rb-4.0.3&auto=format&fit=crop&w=1920&q=80', $cms_images); ?>') center/cover no-repeat;">

        <!-- BACK BUTTON TO HOMEPAGE -->
        <a href="index.php" class="back-home-btn">&larr; Back to Home</a>

        <!-- Header outside the card -->
        <div class="auth-header">
            <div class="auth-logo">Sevilla360</div>
            <div class="auth-tagline">M.I. Sevilla Resort & Events Place</div>
        </div>

        <!-- Main Auth Card -->
        <div class="auth-card">

            <!-- VIEW 1: USER LOGIN -->
            <div id="view-user-login" class="auth-view active">
                <h2 class="auth-title">Sign in to continue</h2>
                <p class="auth-subtitle"><?php echo booking_auth_resume_requested() ? 'Sign in to continue your venue selection' : 'Sign in to manage your bookings'; ?></p>

                <form id="form-login" action="actions/auth/login_process.php" method="POST">
                    <!-- CSRF TOKEN INJECTED HERE -->
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="login_type" value="customer">
                    
                    <!-- HONEYPOT FIELD -->
                    <div style="display:none; position:absolute; left:-9999px;">
                        <label for="website_url_login">Leave this empty:</label>
                        <input type="text" name="website_url_honeypot" id="website_url_login" value="" autocomplete="off">
                    </div>

                    <div class="form-group">
                        <label for="login-email">EMAIL ADDRESS</label>
                        <input type="email" id="login-email" name="email" class="form-control" placeholder="Enter your email" autocomplete="email" required>
                    </div>
                    <div class="form-group">
                        <label for="login-password">PASSWORD</label>
                        <div class="password-wrapper">
                            <input type="password" id="login-password" name="password" class="form-control" autocomplete="current-password"
                                placeholder="Enter your password" required>
                            <span class="password-toggle">SHOW</span>
                        </div>
                        <button type="button" class="forgot-link" data-forgot-password data-origin="customer">Forgot password?</button>
                    </div>

                    <?php if (customer_login_recovery_nudge_eligible()): ?>
                    <div class="login-recovery-nudge" role="status" aria-live="polite" aria-atomic="true">
                        <span>Having trouble signing in?</span>
                        <button type="button" class="recovery-nudge-action" data-forgot-password data-origin="customer">Reset your password.</button>
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary btn-full">SIGN IN &rarr;</button>
                </form>

                <div class="auth-footer">
                    New customer? <a id="link-goto-register" style="cursor: pointer;">Create Account</a>
                </div>
            </div>

            <!-- VIEW 2: USER REGISTRATION -->
            <div id="view-user-register" class="auth-view">
                <h2 class="auth-title">Create Account</h2>
                <p class="auth-subtitle" id="register-subtitle">Book venues & manage reservations online</p>

                <div class="step-indicator" id="register-step-indicator">
                    <div class="step-segment active" id="step-seg-1"></div>
                    <div class="step-segment" id="step-seg-2"></div>
                </div>

                <form id="form-register" action="actions/auth/register_process.php" method="POST">
                    <!-- CSRF TOKEN INJECTED HERE -->
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <!-- HONEYPOT FIELD -->
                    <div style="display:none; position:absolute; left:-9999px;">
                        <label for="website_url_register">Leave this empty:</label>
                        <input type="text" name="website_url_honeypot" id="website_url_register" value="" autocomplete="off">
                    </div>

                    <!-- STEP 1: Account Info -->
                    <div id="register-step-1" class="register-step active">
                        <div class="form-group">
                            <label for="reg-email">EMAIL ADDRESS</label>
                            <input type="email" id="reg-email" name="email" class="form-control" placeholder="you@example.com" autocomplete="email" aria-describedby="err-email" aria-errormessage="err-email" aria-invalid="false" required>
                            <div class="email-feedback" id="err-email" role="status" aria-live="polite" aria-atomic="true" hidden></div>
                        </div>

                        <div class="form-group">
                            <label for="reg-password">PASSWORD</label>
                            <div class="password-wrapper">
                                <input type="password" id="reg-password" name="password" class="form-control" placeholder="Create a password" autocomplete="new-password" required aria-describedby="reg-password-help">
                                <span class="password-toggle">SHOW</span>
                            </div>
                            <small id="reg-password-help">Use 8–72 characters with a capital letter, lowercase letter, number, and symbol (like ! or @).</small>
                            <div class="error-msg" id="err-password" style="display: none; color: #ef4444; font-size: 0.8rem; margin-top: 5px;"></div>
                        </div>

                        <div class="form-group">
                            <label for="reg-confirm-password">CONFIRM PASSWORD</label>
                            <div class="password-wrapper">
                                <input type="password" id="reg-confirm-password" name="confirm_password" class="form-control" placeholder="Confirm your password" autocomplete="new-password" required>
                                <span class="password-toggle">SHOW</span>
                            </div>
                            <div class="error-msg" id="err-confirm-password" style="display: none; color: #ef4444; font-size: 0.8rem; margin-top: 5px;"></div>
                        </div>

                        <button type="button" class="btn btn-primary btn-full" id="btn-next-step">CONTINUE &rarr;</button>
                    </div>

                    <!-- STEP 2: Personal Info -->
                    <div id="register-step-2" class="register-step">
                        <div class="form-group">
                            <label for="reg-first-name">FIRST NAME</label>
                            <input type="text" id="reg-first-name" name="first_name" class="form-control" placeholder="Juan" autocomplete="given-name" required>
                        </div>

                        <div class="form-group">
                            <label for="reg-last-name">LAST NAME</label>
                            <input type="text" id="reg-last-name" name="last_name" class="form-control" placeholder="Dela Cruz" autocomplete="family-name" required>
                        </div>

                        <?php require_once __DIR__ . '/includes/contact_verification.php'; if (phone_signup_enabled()): ?>
                        <div class="form-group"><label for="reg-phone">PHILIPPINE MOBILE NUMBER</label><input type="tel" id="reg-phone" name="phone" class="form-control" autocomplete="tel" placeholder="0917 123 4567" required></div>
                        <?php endif; ?>
                        <div class="terms-checkbox-group">
                            <input type="checkbox" id="agree-checkbox" name="consent" value="1" required>
                            <label for="agree-checkbox">
                                I agree to the <span class="terms-link" id="link-goto-terms" style="cursor: pointer;">Terms
                                    of Service</span> and
                                Privacy Policy.
                            </label>
                        </div>

                        <button type="submit" class="btn btn-primary btn-full">CREATE ACCOUNT</button>
                        
                        <div style="text-align: center; margin-top: 15px;">
                            <a id="btn-prev-step" style="cursor: pointer; color: #666; font-size: 0.9rem; font-weight: 500;">&larr; Back to previous step</a>
                        </div>
                    </div>
                </form>

                <div class="auth-footer">
                    Already have an account? <a id="link-goto-login" style="cursor: pointer;">Log in</a>
                </div>
            </div>

            <!-- VIEW 3: TERMS AND CONDITION -->
            <div id="view-terms" class="auth-view">
                <h2 class="auth-title">Terms And Condition</h2>
                <p class="auth-subtitle">Please read before creating an account</p>

                <div class="terms-content">
                    <ol>
                        <li><strong>Booking & Payments:</strong> Online Hotel Room and Resort Villa bookings have a 24-hour payment window. Submit your transfer reference and receipt image from your dashboard; the window pauses while staff review the proof, and a rejection starts a fresh 24-hour window. An Event Hall inquiry has no payment deadline until the resort finalizes its quotation. A booking is confirmed when the required payment is verified.</li>
                        <li><strong>Cancellation & Refunds:</strong> Paid customer cancellation/refund requests return the full amount paid. The refund amount is recorded when the request is submitted; resort-initiated cancellation decisions follow the existing refund workflow.</li>
                        <li><strong>Virtual Showroom Disclaimer:</strong> The Sevilla360 virtual tours are for
                            illustrative purposes. Actual arrangements and lighting may slightly vary.</li>
                        <li><strong>Resort Rules:</strong> Guests are expected to maintain the property. Damages
                            incurred during the stay or event will be billed to the account holder.</li>
                        <li><strong>Data Privacy:</strong> We collect and process your personal data in accordance with
                            our Privacy Policy to manage bookings effectively.</li>
                    </ol>
                </div>

                <button type="button" class="btn btn-primary btn-full" id="btn-agree-terms">I AGREE</button>
            </div>



            <!-- VIEW 5: FORGOT PASSWORD -->
            <div id="view-forgot-password" class="auth-view">
                <h2 class="auth-title">Reset Password</h2>
                <p class="auth-subtitle">Enter your email to receive a reset link</p>

                <form id="form-forgot" action="actions/auth/forgot_password_process.php" method="POST">
                    <!-- CSRF TOKEN INJECTED HERE -->
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="origin" id="forgot-origin" value="<?php echo htmlspecialchars($forgot_origin, ENT_QUOTES, 'UTF-8'); ?>">

                    <!-- HONEYPOT FIELD -->
                    <div style="display:none; position:absolute; left:-9999px;">
                        <label for="website_url_forgot">Leave this empty:</label>
                        <input type="text" name="website_url_honeypot" id="website_url_forgot" value="" autocomplete="off">
                    </div>

                    <div class="form-group">
                        <label for="forgot-email">EMAIL ADDRESS</label>
                        <input type="email" id="forgot-email" name="email" class="form-control" placeholder="Enter your email" autocomplete="email" required>
                    </div>

                    <button type="submit" class="btn btn-primary btn-full">SEND RESET LINK</button>
                </form>

                <div class="auth-footer">
                    <a id="link-back-login-from-forgot" style="cursor: pointer;">&larr; Back to Login</a>
                </div>
            </div>

        </div>
    </div>

    <!-- ACCOUNT VERIFICATION MODAL -->
    <div id="verification-modal"
        style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.7); z-index: 9999; justify-content: center; align-items: center;">

        <!-- CHANGED border-radius from 8px to 0 here -->
        <div class="auth-card"
            style="position: relative; max-width: 450px; width: 100%; text-align: center; background: #fff5e8; border: 1px solid var(--color-gold); padding: 30px; border-radius: 0; box-shadow: 0 10px 30px rgba(0,0,0,0.3);">
            <a href="auth.php"
                style="position: absolute; top: 15px; right: 20px; font-size: 1.5rem; text-decoration: none; color: var(--color-dark-light); font-weight: bold;">&times;</a>
            <h2 class="auth-title" style="margin-bottom: 10px; font-family: var(--font-heading);" id="verification-title"><?php echo $verify_phone_mode ? 'Verify Your Phone' : 'Verify Your Email'; ?></h2>

            <p class="auth-subtitle" style="font-size: 0.95rem; line-height: 1.5; color: var(--color-dark);" id="verification-description">
                <?php if ($verify_phone_mode): ?>
                    Enter the 6-digit code sent to the phone on your account. If it did not arrive, request a new code or contact support.
                <?php else: ?>
                    A 6-digit verification code has been sent to <br>
                    <strong id="verify-email-display" style="color: var(--color-gold);">you@email.com</strong>.<br>
                    Please check your inbox and enter the code below.
                <?php endif; ?>
            </p>

            <form id="form-verify" action="actions/auth/verify_process.php" method="POST" style="margin-top: 20px;">
                <!-- CSRF TOKEN INJECTED HERE -->
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                <!-- Hidden input to pass the email to PHP -->
                <input type="hidden" name="email" id="verify-email-input">

                <div class="form-group">
                    <label for="verification-code">VERIFICATION CODE</label>
                    <input type="text" id="verification-code" name="verification_code" class="form-control" placeholder="000000" maxlength="6" autocomplete="one-time-code" inputmode="numeric"
                        style="text-align: center; font-size: 2rem; letter-spacing: 8px; font-weight: bold; padding: 15px;"
                        required>
                </div>

                <button type="submit" class="btn btn-primary btn-full" style="margin-top: 15px;">VERIFY ACCOUNT</button>
            </form>

            <?php if ($verify_phone_mode): ?>
            <div class="form-group" style="margin-top: 16px;">
                <label for="pending-new-phone">Can't receive SMS? Sign in with your password, then enter another number.</label>
                <input type="tel" id="pending-new-phone" class="form-control" autocomplete="tel" placeholder="0917 123 4567">
                <button type="button" id="pending-change-phone" class="btn btn-primary btn-full" style="margin-top: 8px;">Send code to new number</button>
            </div>
            <?php endif; ?>
            <div class="auth-footer" style="margin-top: 15px;">
                <a href="#" id="resend-code-btn"
                    style="color: var(--color-dark-light); text-decoration: underline;"><?php echo $verify_phone_mode ? 'Resend SMS code' : 'Resend verification email'; ?></a>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="<?= htmlspecialchars(site_metadata_asset_url('assets/js/global_modals.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
    <script src="<?= htmlspecialchars(site_metadata_asset_url('assets/js/password_policy.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
    <script src="<?= htmlspecialchars(site_metadata_asset_url('assets/js/auth.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
    
    <?php if (isset($_SESSION['auth_alert'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                showAlert(
                    "<?php echo addslashes($_SESSION['auth_alert']['title']); ?>",
                    "<?php echo addslashes($_SESSION['auth_alert']['message']); ?>",
                    "<?php echo addslashes($_SESSION['auth_alert']['type']); ?>"
                );
            });
        </script>
        <?php unset($_SESSION['auth_alert']); ?>
    <?php endif; ?>
</body>

</html>
