<?php
require_once __DIR__ . '/includes/session_init.php';
require_once 'config/db_connect.php';

$token = $_GET['token'] ?? '';
$is_valid = false;
$error_msg = '';
$user_id = 0;

if (empty($token)) {
    $error_msg = 'Invalid password reset link.';
} else {
    // Check if token exists and is not expired
    $stmt = $conn->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_expires_at > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($res->num_rows === 1) {
        $is_valid = true;
        $user_id = $res->fetch_assoc()['id'];
        $_SESSION['reset_user_id'] = $user_id; // Store securely in session for processing
    } else {
        $error_msg = 'This password reset link is invalid or has expired.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
    <title>Reset Password - SEVILLA360</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/auth.css">
    <link rel="stylesheet" href="assets/css/ui-refinement.css?v=<?= filemtime(__DIR__ . '/assets/css/ui-refinement.css'); ?>">
    <!-- FontAwesome for global alerts -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="auth-page" style="background: var(--color-dark);">
        
        <div class="auth-header">
            <div class="auth-logo">Sevilla360</div>
        </div>

        <div class="auth-card">
            <?php if ($is_valid): ?>
                <div class="auth-view active">
                    <h2 class="auth-title">Create New Password</h2>
                    <p class="auth-subtitle">Please enter your new password below.</p>

                    <form action="actions/auth/reset_password_process.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                        <div class="form-group">
                            <label>NEW PASSWORD</label>
                    <input type="password" name="new_password" class="form-control" required minlength="8">
                        </div>
                        
                        <div class="form-group">
                            <label>CONFIRM PASSWORD</label>
                    <input type="password" name="confirm_password" class="form-control" required minlength="8">
                        </div>

                        <button type="submit" class="btn btn-primary btn-full">RESET PASSWORD</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="auth-view active" style="text-align: center;">
                    <h2 class="auth-title" style="color: #d93025;">Error</h2>
                    <p class="auth-subtitle"><?php echo htmlspecialchars($error_msg); ?></p>
                    <br>
                    <a href="auth.php" class="btn btn-primary btn-full" style="text-decoration: none;">GO TO LOGIN</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Scripts -->
    <script src="assets/js/global_modals.js?v=<?= time(); ?>"></script>
    
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
