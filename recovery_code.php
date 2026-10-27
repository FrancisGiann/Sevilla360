<?php
require_once __DIR__.'/includes/session_init.php';
require_once __DIR__.'/includes/site_metadata.php';
header('X-Robots-Tag: noindex, nofollow',true);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>Text code - Sevilla360</title>
<link rel="stylesheet" href="<?= htmlspecialchars(site_metadata_asset_url('assets/css/style.css'),ENT_QUOTES,'UTF-8') ?>"><link rel="stylesheet" href="<?= htmlspecialchars(site_metadata_asset_url('assets/css/auth.css'),ENT_QUOTES,'UTF-8') ?>"><link rel="stylesheet" href="assets/css/ui-refinement.css?v=<?= filemtime(__DIR__.'/assets/css/ui-refinement.css') ?>"></head>
<body><div class="auth-page" style="background: var(--color-dark)"><div class="auth-header"><div class="auth-logo">Sevilla360</div></div><div class="auth-card"><div class="auth-view active"><h2 class="auth-title">Enter your text code</h2><p class="auth-subtitle">Use the code sent to the phone already verified on your account. It expires in 5 minutes.</p>
<form method="post" action="actions/auth/verify_recovery_code.php"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'],ENT_QUOTES,'UTF-8') ?>"><div class="form-group"><label for="recovery-email">ACCOUNT EMAIL</label><input id="recovery-email" class="form-control" type="email" name="email" autocomplete="username" required></div><div class="form-group"><label for="recovery-code">SIX DIGIT CODE</label><input id="recovery-code" class="form-control" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></div><button class="btn btn-primary btn-full" type="submit">CONTINUE</button></form><div class="auth-footer"><a href="auth.php">Back to sign in</a></div>
<?php if (isset($_SESSION['auth_alert'])): ?><p role="alert"><?= htmlspecialchars((string)$_SESSION['auth_alert']['message'],ENT_QUOTES,'UTF-8') ?></p><?php unset($_SESSION['auth_alert']); endif; ?>
</div></div></div></body></html>
