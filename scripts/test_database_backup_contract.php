<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/database_backup.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$throws = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
    } catch (Throwable $error) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};
$setEnv = static function (string $name, string $value): void {
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
};

$testRoot = sys_get_temp_dir() . '/sevilla360-db-backup-test-' . bin2hex(random_bytes(8));
$backupDir = $testRoot . '/private/backups';
if (!mkdir($backupDir, 0700, true)) throw new RuntimeException('Unable to create isolated backup test directory.');
@chmod($testRoot, 0700);
@chmod(dirname($backupDir), 0700);
@chmod($backupDir, 0700);
$setEnv('BACKUP_DIR', $backupDir);
$setEnv('APP_KEY', str_repeat('test-signing-key-', 3));
$setEnv('DB_HOST', 'localhost');
$setEnv('DB_USER', 'backup-test');
$setEnv('DB_PASS', 'test-only-password');
$setEnv('DB_NAME', 'sevilla360_test');
$setEnv('DB_PORT', '');
foreach (['DB_STAGING_HOST', 'DB_STAGING_USER', 'DB_STAGING_PASS', 'DB_STAGING_NAME', 'DB_STAGING_PORT'] as $key) $setEnv($key, '');

$createArchive = static function (string $kind, DateTimeImmutable $created, string $sql = "CREATE TABLE users(id INT);\n", ?string $database = null, ?string $signingAppKey = null) use ($backupDir, $setEnv): string {
    if ($signingAppKey !== null) $setEnv('APP_KEY', $signingAppKey);
    $database ??= database_backup_env('DB_NAME');
    $gzip = gzencode($sql, 6);
    if (!is_string($gzip)) throw new RuntimeException('Unable to construct compressed test archive.');
    $metadata = [
        'format' => 1,
        'database' => $database,
        'created_at' => $created->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        'kind' => $kind,
        'compression' => 'gzip',
        'payload_bytes' => strlen($gzip),
        'payload_sha256' => hash('sha256', $gzip),
        'uncompressed_bytes' => strlen($sql),
    ];
    $key = database_backup_app_key();
    if ($key === null) throw new RuntimeException('Test key did not derive.');
    $metadata['signature'] = hash_hmac('sha256', database_backup_canonical_metadata($metadata), $key);
    $header = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $id = 'db-' . bin2hex(random_bytes(16)) . '.s360db';
    $path = $backupDir . DIRECTORY_SEPARATOR . $id;
    if (file_put_contents($path, DATABASE_BACKUP_MAGIC . pack('N', strlen($header)) . $header . $gzip) === false) throw new RuntimeException('Unable to create test archive.');
    chmod($path, 0600);
    return $id;
};

try {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $validId = $createArchive('manual', $now);
    $validPath = database_backup_archive_path($validId, true);
    $metadata = database_backup_validate_archive($validPath);
    $assert($metadata['database'] === 'sevilla360_test' && $metadata['compression'] === 'gzip', 'signed archive metadata verifies against the configured database.');
    database_backup_remove_validation_temp($metadata);

    $wrongKeyId = $createArchive('manual', $now, "CREATE TABLE users(id INT);\n", null, str_repeat('different-test-key-', 3));
    $setEnv('APP_KEY', str_repeat('test-signing-key-', 3));
    $throws(static fn() => database_backup_validate_archive(database_backup_archive_path($wrongKeyId, true)), 'wrong-signature archives are rejected.');

    $wrongDbId = $createArchive('manual', $now, "CREATE TABLE users(id INT);\n", 'other_database');
    $throws(static fn() => database_backup_validate_archive(database_backup_archive_path($wrongDbId, true)), 'archives for a different configured database are rejected.');

    $alteredId = $createArchive('manual', $now);
    $alteredPath = database_backup_archive_path($alteredId, true);
    $contents = file_get_contents($alteredPath);
    $contents[strlen($contents) - 1] = chr(ord($contents[strlen($contents) - 1]) ^ 1);
    file_put_contents($alteredPath, $contents);
    $listedArchives = database_backup_list_archives();
    $alteredListing = array_values(array_filter($listedArchives, static fn(array $archive): bool => $archive['id'] === $alteredId))[0] ?? [];
    $assert(($alteredListing['header_valid'] ?? false) === true && ($alteredListing['payload_checked'] ?? true) === false && array_key_exists('payload_valid', $alteredListing) && $alteredListing['payload_valid'] === null, 'status listing authenticates bounded metadata without reading or decompressing the payload.');
    $fullyListedArchives = database_backup_list_archives(true);
    $alteredFullListing = array_values(array_filter($fullyListedArchives, static fn(array $archive): bool => $archive['id'] === $alteredId))[0] ?? [];
    $assert(($alteredFullListing['payload_checked'] ?? false) === true && ($alteredFullListing['payload_valid'] ?? null) === false, 'explicit full archive listing still detects a modified payload.');
    $throws(static fn() => database_backup_validate_archive($alteredPath), 'modified compressed payloads are rejected.');

    $truncatedId = $createArchive('manual', $now);
    $truncatedPath = database_backup_archive_path($truncatedId, true);
    $truncated = file_get_contents($truncatedPath);
    file_put_contents($truncatedPath, substr($truncated, 0, -1));
    $throws(static fn() => database_backup_validate_archive($truncatedPath), 'truncated archives are rejected.');

    $throws(static fn() => database_backup_archive_path('../backup.sql', true), 'archive IDs cannot escape private storage.');
    $setEnv('BACKUP_DIR', dirname(__DIR__) . '/private-backups');
    $throws(static fn() => database_backup_directory(false), 'backup storage resolver rejects storage beneath the project tree.');
    $setEnv('BACKUP_DIR', $backupDir);

    $unsafeBackupDir = $testRoot . '/unsafe-backups';
    mkdir($unsafeBackupDir, 0755);
    chmod($unsafeBackupDir, 0755);
    $setEnv('BACKUP_DIR', $unsafeBackupDir);
    $assert(database_backup_maintenance_state() === null, 'unsafe backup storage with no maintenance marker disables backups without pausing normal application writes.');
    file_put_contents($unsafeBackupDir . '/maintenance.json', '{invalid');
    $assert(database_backup_maintenance_state() !== null, 'an unreadable maintenance marker keeps application writes fail-closed.');
    unlink($unsafeBackupDir . '/maintenance.json');
    rmdir($unsafeBackupDir);
    $setEnv('BACKUP_DIR', $backupDir);

    $capabilities = database_backup_capabilities();
    $assert($capabilities['enabled'], 'backup creation remains available when dump, signing, and private storage capabilities exist.');
    $assert(!$capabilities['restore_enabled'] && str_contains((string)$capabilities['restore_message'], 'DB_STAGING_HOST'), 'restore is explicitly disabled until a dedicated staging database is configured.');
    $throws(static fn() => database_backup_preflight($validPath), 'restore preflight fails closed when staging is unavailable.');

    $requiredSchema = database_backup_required_schema_columns();
    $baseTables = array_fill_keys(array_keys($requiredSchema), true);
    $assert(database_backup_schema_compatibility_errors($requiredSchema, $baseTables) === [], 'the current migration-backed schema fingerprint passes compatibility validation.');
    $olderSchema = $requiredSchema;
    $olderSchema['bookings'] = array_values(array_diff($olderSchema['bookings'], ['payment_due_at']));
    $schemaErrors = database_backup_schema_compatibility_errors($olderSchema, $baseTables);
    $assert(in_array('bookings.payment_due_at', $schemaErrors, true), 'archives missing the current payment-deadline column are rejected even when core tables exist.');
    $olderTables = $baseTables;
    unset($olderTables['manual_payment_submissions']);
    $assert(in_array('manual_payment_submissions (base table)', database_backup_schema_compatibility_errors($requiredSchema, $olderTables), true), 'archives missing the current manual-payment table are rejected.');
    $olderSchema = $requiredSchema;
    $olderSchema['cancellations'] = array_values(array_diff($olderSchema['cancellations'], ['refund_transaction_id']));
    $assert(in_array('cancellations.refund_transaction_id', database_backup_schema_compatibility_errors($olderSchema, $baseTables), true), 'archives missing a field used by the current refund path are rejected.');
    $assert(!in_array('google_subject', $requiredSchema['users'], true) && !isset($requiredSchema['notification_outbox']) && !isset($requiredSchema['hotel_room_groups']), 'optional Google, realtime, and room-group migrations do not block compatible restores.');

    $assert(database_backup_admin_account_state(null) === 'unverifiable', 'a temporarily missing admin row is distinguished from a confirmed inactive account.');
    $assert(database_backup_admin_account_state(['role' => 'admin', 'staff_status' => null]) === 'unverifiable', 'a missing staff row during restore remains unverifiable, not confirmed inactive.');
    $assert(database_backup_admin_account_state(['role' => 'admin', 'staff_status' => 'active']) === 'active', 'a current active admin row passes status revalidation.');
    $assert(database_backup_admin_account_state(['role' => 'admin', 'staff_status' => '']) === 'inactive', 'a present but non-active staff status remains denied during maintenance.');
    $assert(database_backup_admin_account_state(['role' => 'admin', 'staff_status' => 'Suspended']) === 'inactive', 'a confirmed suspended admin remains denied during maintenance.');
    $maintenanceState = ['active' => true, 'phase' => 'restoring'];
    $assert(database_backup_admin_status_fallback_allowed($maintenanceState, 'The administrator account could not be verified.'), 'status polling may use the authenticated session while active maintenance temporarily prevents DB revalidation.');
    $assert(!database_backup_admin_status_fallback_allowed($maintenanceState, 'The administrator account is no longer active.'), 'status polling still denies a confirmed inactive admin during maintenance.');
    $assert(!database_backup_admin_status_fallback_allowed(null, 'The administrator account could not be verified.'), 'status polling requires strict DB revalidation when maintenance is inactive.');
    $setEnv('DB_STAGING_HOST', '127.0.0.1');
    $setEnv('DB_STAGING_USER', 'backup-staging-test');
    $setEnv('DB_STAGING_NAME', 'sevilla360_test');
    $setEnv('DB_STAGING_PORT', '3306');
    $throws(static fn() => database_backup_staging_config(), 'staging aliases cannot point at the production schema even when host/port spellings differ.');
    $setEnv('DB_STAGING_NAME', 'SEVILLA360_TEST');
    $throws(static fn() => database_backup_staging_config(), 'database-name casing cannot make a staging alias collide with production.');
    foreach (['DB_STAGING_HOST', 'DB_STAGING_USER', 'DB_STAGING_PASS', 'DB_STAGING_NAME', 'DB_STAGING_PORT'] as $key) $setEnv($key, '');

    $_SESSION = ['logged_in' => true, 'role' => 'admin', 'user_id' => 17, 'csrf_token' => 'test-csrf-token'];
    $_SERVER['HTTP_X_CSRF_TOKEN'] = 'test-csrf-token';
    $assert(database_backup_is_admin_session() && database_backup_csrf_valid(), 'an authenticated admin session with the matching CSRF token passes the local request checks.');
    $_SESSION['role'] = 'staff';
    $assert(!database_backup_is_admin_session(), 'staff sessions do not pass the admin authorization check.');
    $_SESSION['role'] = 'admin';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = 'wrong-token';
    $assert(!database_backup_csrf_valid(), 'a missing or altered CSRF token fails validation.');
    $assert(database_backup_route_uses_gate('/actions/bookings/submit_online.php', 'GET'), 'GET action routes are gated, covering any legacy state-changing GET endpoint.');
    $assert(database_backup_route_uses_gate('/actions/admin/get_audit_logs.php', 'POST'), 'POST read/action routes are gated during maintenance.');
    $assert(!database_backup_route_uses_gate('/actions/admin/get_database_backup_status.php', 'GET'), 'the minimal backup status route remains available during maintenance.');
    $assert(!database_backup_route_uses_gate('/actions/admin/download_database_backup.php', 'POST'), 'the protected backup download action remains available for recovery.');
    $assert(database_backup_route_uses_gate('/admin_dashboard.php', 'POST'), 'unsafe dashboard requests are gated.');
    $assert(!database_backup_route_uses_gate('/admin_dashboard.php', 'GET'), 'ordinary dashboard navigation remains a safe read route.');

    $exclusive = database_backup_lock(true, false);
    $assert(is_resource($exclusive), 'exclusive database operation lock can be acquired.');
    $assert(database_backup_lock(false, false) === false, 'concurrent web writes cannot enter during an exclusive restore lock.');
    database_backup_release_lock($exclusive);
    $shared = database_backup_lock(false, false);
    $assert(is_resource($shared), 'shared web write lock can be acquired outside a restore.');
    $assert(database_backup_lock(true, false) === false, 'a restore cannot start while an application write holds the shared lock.');
    database_backup_release_lock($shared);

    $maintenanceJob = 'job-' . bin2hex(random_bytes(16)) . '.json';
    $otherMaintenanceJob = 'job-' . bin2hex(random_bytes(16)) . '.json';
    database_backup_write_maintenance($maintenanceJob, 'Test maintenance state');
    $throws(static fn() => database_backup_clear_maintenance($otherMaintenanceJob), 'a job cannot clear another job’s maintenance marker.');
    $assert((database_backup_maintenance_state()['job_id'] ?? null) === $maintenanceJob, 'a mismatched maintenance clear preserves the active marker.');
    database_backup_clear_maintenance($maintenanceJob);
    $assert(database_backup_maintenance_state() === null, 'maintenance clear verifies the marker is gone.');

    for ($days = 0; $days < 9; $days++) $createArchive('scheduled', $now->sub(new DateInterval('P' . $days . 'D')));
    $removed = database_backup_apply_retention();
    $archives = database_backup_list_archives();
    $scheduled = array_values(array_filter($archives, static fn(array $archive): bool => $archive['kind'] === 'scheduled'));
    $assert($removed >= 2 && count($scheduled) === 7, 'retention removes snapshots older than seven days and keeps the newest seven scheduled archives.');

    $statusSource = file_get_contents(__DIR__ . '/../actions/admin/get_database_backup_status.php');
    $downloadSource = file_get_contents(__DIR__ . '/../actions/admin/download_database_backup.php');
    $uploadSource = file_get_contents(__DIR__ . '/../actions/admin/upload_database_backup.php');
    $restoreSource = file_get_contents(__DIR__ . '/../actions/admin/restore_database_backup.php');
    $workerSource = file_get_contents(__DIR__ . '/database_backup_worker.php');
    $expirySource = file_get_contents(__DIR__ . '/expire_unpaid_bookings.php');
    $helperSource = file_get_contents(__DIR__ . '/../includes/database_backup.php');
    $assert(str_contains((string)$statusSource, 'database_backup_verify_request_admin(true)') && str_contains((string)$statusSource, 'database_backup_require_admin(false)'), 'status requires admin session and revalidates the current account with a maintenance fallback.');
    $assert(str_contains((string)$downloadSource, "REQUEST_METHOD'] ?? '') !== 'POST'") && str_contains((string)$downloadSource, 'database_backup_require_admin()') && !str_contains((string)$downloadSource, 'database_backup_verify_request_admin(true)'), 'downloads require current admin revalidation and an authenticated admin POST with CSRF.');
    $assert(str_contains((string)$downloadSource, "'database.backup_downloaded'") && str_contains((string)$downloadSource, '$bytesSent === $archiveSize') && str_contains((string)$helperSource, "'database.backup_downloaded'"), 'successful archive downloads are audited best-effort after streaming.');
    $assert(str_contains((string)$statusSource, "'archives' => database_backup_list_archives()") && str_contains((string)$helperSource, 'database_backup_read_archive_header($path)') && str_contains((string)$helperSource, 'database_backup_list_archives(bool $verify = false)'), 'status archive listing uses a bounded header inspection; full payload checks remain opt-in.');
    $assert(str_contains((string)$uploadSource, 'database_backup_require_admin()') && str_contains((string)$uploadSource, 'database_backup_verify_request_admin()') && str_contains((string)$uploadSource, 'database_backup_validate_archive'), 'uploads require admin/CSRF, current account status, and signed archive validation.');
    $assert(str_contains((string)$restoreSource, 'password_verify') || str_contains((string)$helperSource, 'password_verify($password') && str_contains((string)$restoreSource, "hash_equals('RESTORE'"), 'restore requires password reauthentication and the explicit confirmation phrase.');
    $assert(str_contains((string)$helperSource, 'database_backup_preflight($archivePath)') && str_contains((string)$helperSource, "database_backup_clear_database_objects(database_backup_connection_config('DB_'))"), 'web restore preflights staging before reconciling production objects.');
    $assert(str_contains((string)$helperSource, 'database_backup_schema_compatibility_errors($availableColumns, $baseTables)') && str_contains((string)$helperSource, 'information_schema.COLUMNS'), 'staging and production verification check migration-backed schema columns, not just table names.');
    $assert(str_contains((string)$helperSource, 'database_backup_admin_status_fallback_allowed(database_backup_maintenance_state()') && str_contains((string)$helperSource, 'The administrator account is no longer active.'), 'status fallback is limited to maintenance and never masks a confirmed inactive account.');
    $assert(str_contains((string)$helperSource, 'Recovering from safety backup') && str_contains((string)$helperSource, 'recovery failed. The maintenance gate remains active') && str_contains((string)$workerSource, '--recover'), 'failed restore recovery preserves maintenance and exposes a CLI recovery path.');
    $assert(!str_contains((string)$workerSource, 'database_backup_preflight($archivePath)') && str_contains((string)$workerSource, 'database_backup_validate_archive($archivePath)'), 'operator recovery validates the signed artifact without depending on staging availability.');
    $assert(str_contains((string)$expirySource, 'database_backup_lock(false, true)') && str_contains((string)$expirySource, 'database_backup_maintenance_state() !== null'), 'the database-mutating booking-expiry cron shares the restore gate.');
    $assert(str_contains((string)$helperSource, 'if (!unlink($path)) throw new RuntimeException(\'Unable to clear the database maintenance marker.\')') && str_contains((string)$helperSource, "'succeeded_maintenance'"), 'maintenance clear failures remain visible instead of silently reporting restore success.');
    $assert(str_contains((string)$helperSource, 'flock($lock, LOCK_UN)') && str_contains((string)$workerSource, 'LOCK_EX | LOCK_NB'), 'operation and worker locks serialize restores and staging preflight.');

    fwrite(STDOUT, "Database backup contract checks passed ({$assertions} assertions).\n");
} finally {
    $removeTree = static function (string $path) use (&$removeTree): void {
        if (is_link($path) || is_file($path)) { @unlink($path); return; }
        if (!is_dir($path)) return;
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $removeTree($path . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
    };
    $removeTree($testRoot);
}
