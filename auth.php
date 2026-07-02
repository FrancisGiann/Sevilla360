<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authentication - SEVILLA360</title>

    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/auth.css">
</head>

<body class="bg-beige">

    <div class="auth-page">

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
                <h2 class="auth-title">Welcome Back</h2>
                <p class="auth-subtitle">Sign in to manage your bookings</p>

                <form id="form-login" action="actions/auth/login_process.php" method="POST">
                    <div class="form-group">
                        <label>EMAIL ADDRESS</label>
                        <input type="email" name="email" class="form-control" placeholder="Enter your email" required>
                    </div>
                    <div class="form-group">
                        <label>PASSWORD</label>
                        <div class="password-wrapper">
                            <input type="password" name="password" class="form-control"
                                placeholder="Enter your password" required>
                            <span class="password-toggle">SHOW</span>
                        </div>
                        <a href="#" class="forgot-link">Forgot password?</a>
                    </div>

                    <button type="submit" class="btn btn-primary btn-full">SIGN IN &rarr;</button>
                    <button type="button" class="btn btn-secondary btn-full" id="btn-goto-admin">ADMIN LOGIN</button>
                </form>

                <div class="auth-footer">
                    New customer? <a id="link-goto-register">Create Account</a>
                </div>
            </div>

            <!-- VIEW 2: USER REGISTRATION -->
            <div id="view-user-register" class="auth-view">
                <h2 class="auth-title">Create Account</h2>
                <p class="auth-subtitle">Book venues & manage reservations online</p>

                <form id="form-register" action="actions/auth/register_process.php" method="POST">

                    <div class="form-group">
                        <label>FIRST NAME</label>
                        <input type="text" name="first_name" class="form-control" placeholder="Juan" required>
                    </div>

                    <div class="form-group">
                        <label>LAST NAME</label>
                        <input type="text" name="last_name" class="form-control" placeholder="Dela Cruz" required>
                    </div>

                    <div class="form-group">
                        <label>EMAIL ADDRESS</label>
                        <!-- MUST HAVE name="email" -->
                        <input type="email" name="email" class="form-control" placeholder="you@example.com" required>
                    </div>

                    <div class="form-group">
                        <label>DATE OF BIRTH</label>
                        <!-- MUST HAVE name="dob" -->
                        <input type="date" name="dob" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label>PASSWORD</label>
                        <div class="password-wrapper">
                            <!-- MUST HAVE name="password" -->
                            <input type="password" name="password" class="form-control" placeholder="Create a password"
                                required>
                            <span class="password-toggle">SHOW</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>CONFIRM PASSWORD</label>
                        <div class="password-wrapper">
                            <!-- MUST HAVE name="confirm_password" -->
                            <input type="password" name="confirm_password" class="form-control"
                                placeholder="Confirm your password" required>
                            <span class="password-toggle">SHOW</span>
                        </div>
                    </div>

                    <div class="terms-checkbox-group">
                        <input type="checkbox" id="agree-checkbox" required>
                        <label for="agree-checkbox">
                            I agree to the <span class="terms-link" id="link-goto-terms">Terms of Service</span> and
                            Privacy Policy.
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary btn-full">CREATE ACCOUNT</button>
                </form>

                <div class="auth-footer">
                    Already have an account? <a id="link-goto-login">Log in</a>
                </div>
            </div>

            <!-- VIEW 3: TERMS AND CONDITION -->
            <div id="view-terms" class="auth-view">
                <h2 class="auth-title">Terms And Condition</h2>
                <p class="auth-subtitle">Please read before creating an account</p>

                <div class="terms-content">
                    <ol>
                        <li><strong>Booking & Payments:</strong> All reservations require a valid payment method. A
                            non-refundable deposit may apply depending on the venue.</li>
                        <li><strong>Cancellation & Refunds:</strong> Cancellations made 72 hours prior to the event are
                            eligible for a partial refund. Late cancellations are non-refundable.</li>
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

            <!-- VIEW 4: ADMIN LOGIN -->
            <div id="view-admin-login" class="auth-view">
                <h2 class="auth-title">Administrator Portal</h2>
                <p class="auth-subtitle">Secure system access</p>

                <form id="form-admin">
                    <div class="form-group">
                        <label>ADMIN EMAIL</label>
                        <input type="email" class="form-control" placeholder="admin@sevilla360.com" required>
                    </div>
                    <div class="form-group">
                        <label>PASSWORD</label>
                        <div class="password-wrapper">
                            <input type="password" class="form-control" placeholder="Enter admin password" required>
                            <span class="password-toggle">SHOW</span>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-full">LOGIN AS ADMIN</button>
                </form>

                <div class="auth-footer">
                    <a id="link-back-login">&larr; Back to User Login</a>
                </div>
            </div>


        </div>
    </div>
    <!-- EMAIL VERIFICATION MODAL -->
    <div id="verification-modal"
        style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.7); z-index: 9999; justify-content: center; align-items: center;">

        <div class="auth-card"
            style="position: relative; max-width: 450px; width: 100%; text-align: center; background: #fff5e8; border: 1px solid var(--color-gold); padding: 30px; border-radius: 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.3);">
            <a href="auth.php"
                style="position: absolute; top: 15px; right: 20px; font-size: 1.5rem; text-decoration: none; color: var(--color-dark-light); font-weight: bold;">&times;</a>
            <h2 class="auth-title" style="margin-bottom: 10px; font-family: var(--font-heading);">Verify Your Email</h2>

            <p class="auth-subtitle" style="font-size: 0.95rem; line-height: 1.5; color: var(--color-dark);">
                A 6-digit verification code has been sent to <br>
                <strong id="verify-email-display" style="color: var(--color-gold);">you@email.com</strong>.<br>
                Please check your inbox and enter the code below.
            </p>

            <form id="form-verify" action="actions/auth/verify_process.php" method="POST" style="margin-top: 20px;">
                <!-- Hidden input to pass the email to PHP -->
                <input type="hidden" name="email" id="verify-email-input">

                <div class="form-group">
                    <input type="text" name="verification_code" class="form-control" placeholder="000000" maxlength="6"
                        style="text-align: center; font-size: 2rem; letter-spacing: 8px; font-weight: bold; padding: 15px;"
                        required>
                </div>

                <button type="submit" class="btn btn-primary btn-full" style="margin-top: 15px;">VERIFY ACCOUNT</button>
            </form>

            <div class="auth-footer" style="margin-top: 15px;">
                <a href="#" id="resend-code-btn"
                    style="color: var(--color-dark-light); text-decoration: underline;">Resend verification email</a>
            </div>
        </div>
    </div>

    <!-- Link to the logic script -->
    <script src="assets/js/auth.js?v=1.1"></script>
</body>

</html>