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

                <form id="form-login" action="actions/login_process.php" method="POST">
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

                <form id="form-register">
                    <div class="form-group">
                        <label>FULL NAME</label>
                        <input type="text" class="form-control" placeholder="Juan Dela Cruz" required>
                    </div>
                    <div class="form-group">
                        <label>EMAIL ADDRESS</label>
                        <input type="email" class="form-control" placeholder="you@example.com" required>
                    </div>
                    <div class="form-group">
                        <label>DATE OF BIRTH</label>
                        <input type="date" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>PASSWORD</label>
                        <div class="password-wrapper">
                            <input type="password" class="form-control" placeholder="Create a password" required>
                            <span class="password-toggle">SHOW</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>CONFIRM PASSWORD</label>
                        <div class="password-wrapper">
                            <input type="password" class="form-control" placeholder="Confirm your password" required>
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

    <!-- Link to the logic script -->
    <script src="assets/js/auth.js"></script>
</body>

</html>