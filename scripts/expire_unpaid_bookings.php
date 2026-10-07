<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../includes/database_backup.php';
$operationLock = null;
try {
    if (database_backup_env('BACKUP_DIR') !== '') {
        try {
            database_backup_directory(false);
            $operationLock = database_backup_lock(false, true);
        } catch (Throwable $lockError) {
            if (database_backup_maintenance_state() !== null) throw $lockError;
            // An unavailable backup directory disables backup/restore; it must
            // not silently disable the existing booking-expiry task.
        }
    }
    if (database_backup_maintenance_state() !== null) {
        fwrite(STDOUT, "Skipped unpaid booking expiry while database maintenance is active.\n");
        exit(0);
    }
    require_once __DIR__ . '/../config/db_connect.php';
    require_once __DIR__ . '/../includes/manual_payment.php';
    $expired = manual_payment_expire_due_bookings($conn, 500);
    fwrite(STDOUT, 'Expired ' . $expired . " unpaid booking(s).\n");
} catch (Throwable $e) {
    error_log('Unpaid booking expiry failed: ' . get_class($e));
    fwrite(STDERR, "Unpaid booking expiry failed.\n");
    exit(1);
} finally {
    database_backup_release_lock($operationLock);
}
