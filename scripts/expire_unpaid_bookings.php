<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/manual_payment.php';
try {
    $expired = manual_payment_expire_due_bookings($conn, 500);
    fwrite(STDOUT, 'Expired ' . $expired . " unpaid booking(s).\n");
} catch (Throwable $e) {
    error_log('Unpaid booking expiry failed: ' . get_class($e));
    fwrite(STDERR, "Unpaid booking expiry failed.\n");
    exit(1);
}
