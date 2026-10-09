<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (($argv[1] ?? '') !== '--run') {
    fwrite(STDOUT, "Isolated restore integration test is opt-in. Run with --run to create and remove a temporary local MariaDB container.\n");
    exit(0);
}

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/database_backup_setup_staging.php';
require_once __DIR__ . '/../includes/database_backup.php';
ini_set('error_log', '/dev/null');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$run = static function (array $command, int $timeoutSeconds = 20): array {
    $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Unable to start an isolated test utility.');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + $timeoutSeconds;
    do {
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        $status = proc_get_status($process);
        if (!$status['running']) break;
        if (microtime(true) >= $deadline) {
            proc_terminate($process, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            throw new RuntimeException('An isolated test utility timed out.');
        }
        usleep(50000);
    } while (true);
    $stdout .= stream_get_contents($pipes[1]) ?: '';
    $stderr .= stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
};

$docker = database_backup_find_binary('DOCKER_BIN', ['docker']);
if ($docker === null || !extension_loaded('mysqli') || !function_exists('proc_open')) {
    fwrite(STDERR, "Isolated restore test requires Docker, mysqli, and PHP process execution.\n");
    exit(2);
}
$image = 'mariadb:10.11';
$imageCheck = $run([$docker, 'image', 'inspect', $image], 10);
if ($imageCheck['exit'] !== 0) {
    fwrite(STDERR, "The isolated restore test requires the cached mariadb:10.11 image; image pulls are disabled.\n");
    exit(2);
}

$testRoot = sys_get_temp_dir() . '/sevilla360-restore-e2e-' . bin2hex(random_bytes(12));
$backupDir = $testRoot . '/private';
$containerName = 's360-restore-e2e-' . bin2hex(random_bytes(8));
$rootPassword = bin2hex(random_bytes(24));
$sourcePassword = bin2hex(random_bytes(24));
$stagingPassword = bin2hex(random_bytes(24));
$testSuffix = bin2hex(random_bytes(6));
$sourceName = 's360testp' . $testSuffix;
$stagingName = 's360tests' . $testSuffix;
$sourceUser = 's360_source_' . $testSuffix;
$stagingUser = 's360_stage_' . $testSuffix;
$appKey = bin2hex(random_bytes(32));
$dockerEnvPath = null;
$containerStarted = false;

$setEnv = static function (string $key, string $value): void {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv($key . '=' . $value);
};
$quoteIdentifier = static fn(string $value): string => '`' . str_replace('`', '``', $value) . '`';
$connect = static function (string $db, string $user, string $password, int $port): mysqli {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    return @new mysqli('127.0.0.1', $user, $password, $db, $port);
};
$makeArchive = static function (string $sql, string $database, string $key, string $directory): string {
    $gzip = gzencode($sql, 6);
    if (!is_string($gzip)) throw new RuntimeException('Unable to build a signed fixture archive.');
    $metadata = [
        'format' => 1,
        'database' => $database,
        'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'kind' => 'manual',
        'compression' => 'gzip',
        'payload_bytes' => strlen($gzip),
        'payload_sha256' => hash('sha256', $gzip),
        'uncompressed_bytes' => strlen($sql),
    ];
    $derived = hash_hmac('sha256', 'sevilla360/database-backup/v1', $key, true);
    $metadata['signature'] = hash_hmac('sha256', database_backup_canonical_metadata($metadata), $derived);
    $header = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $id = 'db-' . bin2hex(random_bytes(16)) . '.s360db';
    $path = $directory . DIRECTORY_SEPARATOR . $id;
    if (file_put_contents($path, DATABASE_BACKUP_MAGIC . pack('N', strlen($header)) . $header . $gzip, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write a private signed fixture archive.');
    }
    chmod($path, 0600);
    return $id;
};
$insertRow = static function (mysqli $db, string $table, array $values) use ($quoteIdentifier): void {
    $columns = array_keys($values);
    $sql = 'INSERT INTO ' . $quoteIdentifier($table) . ' (' . implode(',', array_map($quoteIdentifier, $columns)) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')';
    $stmt = $db->prepare($sql);
    $types = str_repeat('s', count($values));
    $bound = [$types];
    foreach ($values as $key => &$value) $bound[] = &$value;
    unset($value);
    $stmt->bind_param(...$bound);
    try {
        $stmt->execute();
    } catch (Throwable $error) {
        $stmt->close();
        throw new RuntimeException('Synthetic fixture insert failed for table ' . $table . ' (database error ' . (int)$error->getCode() . ').', 0, $error);
    }
    $stmt->close();
};
$createSchemaSql = static function (array $required, bool $includeSeminarPayments = true, bool $addExtension = true) use ($quoteIdentifier): string {
    if (!$includeSeminarPayments) unset($required['seminar_payments']);
    $sql = "SET FOREIGN_KEY_CHECKS=0;\n";
    foreach ($required as $table => $columns) {
        $definitions = [];
        foreach (array_values(array_unique($columns)) as $column) {
            if ($column === 'id') $definitions[] = '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT';
            elseif ($column === 'created_at') $definitions[] = '`created_at` DATETIME NULL';
            elseif ($column === 'amount' || str_ends_with($column, '_price') || str_ends_with($column, '_amount')) $definitions[] = $quoteIdentifier($column) . ' DECIMAL(12,2) NULL';
            elseif ((str_ends_with($column, '_id') && $column !== 'transaction_id') || $column === 'user_id') $definitions[] = $quoteIdentifier($column) . ' BIGINT NULL';
            else $definitions[] = $quoteIdentifier($column) . ' TEXT NULL';
        }
        if (in_array('id', $columns, true)) $definitions[] = 'PRIMARY KEY (`id`)';
        elseif ($table === 'staff') $definitions[] = 'PRIMARY KEY (`user_id`)';
        if ($table === 'users') $definitions[] = 'UNIQUE KEY `uq_email` (`email`(190))';
        $sql .= 'CREATE TABLE ' . $quoteIdentifier($table) . " (\n  " . implode(",\n  ", $definitions) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n";
    }
    if ($addExtension) {
        $sql .= "CREATE TABLE `fixture_schema_extension` (`id` INT NOT NULL AUTO_INCREMENT, `preserve_me` VARCHAR(100) NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n";
    }
    $sql .= "ALTER TABLE `audit_logs` ADD COLUMN `event_type` VARCHAR(100) NULL, ADD COLUMN `entity_type` VARCHAR(100) NULL, ADD COLUMN `entity_id` BIGINT NULL, ADD COLUMN `details_json` TEXT NULL;\n";
    return $sql . "SET FOREIGN_KEY_CHECKS=1;\n";
};

try {
    // Loading .env above is for safe name comparison only. All connections below
    // explicitly point at the random loopback-published test container.
    $productionName = database_backup_env('DB_NAME');
    if ($productionName === '') {
        $envPath = dirname(__DIR__) . '/.env';
        $envText = is_file($envPath) ? (string)file_get_contents($envPath) : '';
        $productionName = database_backup_setup_env_value($envText, 'DB_NAME') ?? '';
    }
    if (in_array($sourceName, [$productionName, $stagingName], true) || $stagingName === $productionName) {
        throw new RuntimeException('Disposable database names collided with the configured production database; rerun the test.');
    }

    if (!mkdir($testRoot, 0700) || !mkdir($backupDir, 0700)) throw new RuntimeException('Unable to create private isolated test storage.');
    $dockerEnvPath = $backupDir . '/mariadb.env';
    if (file_put_contents($dockerEnvPath, "MARIADB_ROOT_PASSWORD={$rootPassword}\nMARIADB_ROOT_HOST=%\n", LOCK_EX) === false) throw new RuntimeException('Unable to create private container configuration.');
    chmod($dockerEnvPath, 0600);
    $start = $run([
        $docker, 'run', '--pull=never', '--rm', '--detach', '--name', $containerName,
        '--publish', '127.0.0.1::3306', '--tmpfs', '/var/lib/mysql:rw,size=512m',
        '--env-file', $dockerEnvPath,
        '--entrypoint', '/bin/sh', $image, '-c',
        "set -eu; openssl req -x509 -newkey rsa:2048 -nodes -keyout /var/lib/mysql/server-key.pem -out /var/lib/mysql/server-cert.pem -days 1 -subj '/CN=localhost' -addext 'subjectAltName=DNS:localhost,IP:127.0.0.1' >/dev/null 2>&1; chown mysql:mysql /var/lib/mysql/server-key.pem /var/lib/mysql/server-cert.pem; chmod 0600 /var/lib/mysql/server-key.pem; exec /usr/local/bin/docker-entrypoint.sh mariadbd --ssl-ca=/var/lib/mysql/server-cert.pem --ssl-cert=/var/lib/mysql/server-cert.pem --ssl-key=/var/lib/mysql/server-key.pem --skip-log-bin",
    ], 30);
    if ($start['exit'] !== 0) throw new RuntimeException('Could not start the cached MariaDB image in an isolated loopback-only container.');
    $containerStarted = true;
    @unlink($dockerEnvPath);
    $portResult = $run([$docker, 'port', $containerName, '3306/tcp'], 10);
    if ($portResult['exit'] !== 0 || !preg_match('/127\.0\.0\.1:(\d+)/', $portResult['stdout'], $portMatch)) {
        throw new RuntimeException('The temporary MariaDB container did not expose a loopback-only test port.');
    }
    $port = (int)$portMatch[1];

    $admin = null;
    $deadline = microtime(true) + 90;
    do {
        try {
            $admin = $connect('mysql', 'root', $rootPassword, $port);
            break;
        } catch (Throwable $error) {
            if (microtime(true) >= $deadline) throw new RuntimeException('The disposable MariaDB server did not become ready.');
            usleep(250000);
        }
    } while (true);

    foreach ([$sourceName, $stagingName] as $databaseName) {
        $stmt = $admin->prepare('SELECT COUNT(*) AS n FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $stmt->bind_param('s', $databaseName);
        $stmt->execute();
        $count = (int)$stmt->get_result()->fetch_assoc()['n'];
        $stmt->close();
        if ($count !== 0) throw new RuntimeException('A generated disposable database already exists; aborting without overwriting it.');
    }
    foreach ([$sourceUser, $stagingUser] as $accountName) {
        $stmt = $admin->prepare('SELECT COUNT(*) AS n FROM mysql.user WHERE User = ?');
        $stmt->bind_param('s', $accountName);
        $stmt->execute();
        $accountCount = (int)$stmt->get_result()->fetch_assoc()['n'];
        $stmt->close();
        if ($accountCount !== 0) throw new RuntimeException('A generated disposable account already exists; aborting without reusing it.');
    }

    $admin->query('CREATE DATABASE ' . $quoteIdentifier($sourceName) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $escapedSourceUser = $admin->real_escape_string($sourceUser);
    $escapedSourcePassword = $admin->real_escape_string($sourcePassword);
    $admin->query("CREATE USER '{$escapedSourceUser}'@'%' IDENTIFIED BY '{$escapedSourcePassword}'");
    $admin->query('GRANT ALL PRIVILEGES ON ' . $quoteIdentifier($sourceName) . ".* TO '{$escapedSourceUser}'@'%'");
    try {
        $stagingPlan = database_backup_setup_staging_sql_plan($admin, $stagingName, $stagingUser, '%', $stagingPassword, true);
    } catch (Throwable $error) {
        throw new RuntimeException('The reusable staging SQL builder rejected the disposable fixture: ' . $error->getMessage(), 0, $error);
    }
    $admin->query($stagingPlan['create_database']);
    $admin->query($stagingPlan['create_user']);
    $admin->query($stagingPlan['grant']);
    $assert(!str_contains($stagingPlan['grant'], $sourceName) && str_contains($stagingPlan['grant'], '`' . $stagingName . '`.*'), 'the isolated fixture reuses setup DDL with a grant limited to the generated staging schema.');

    foreach (['APP_KEY' => $appKey, 'BACKUP_DIR' => $backupDir, 'DB_HOST' => '127.0.0.1', 'DB_USER' => $sourceUser, 'DB_PASS' => $sourcePassword, 'DB_NAME' => $sourceName, 'DB_PORT' => (string)$port,
        'DB_STAGING_HOST' => '127.0.0.1', 'DB_STAGING_USER' => $stagingUser, 'DB_STAGING_PASS' => $stagingPassword, 'DB_STAGING_NAME' => $stagingName, 'DB_STAGING_PORT' => (string)$port] as $key => $value) $setEnv($key, $value);

    $app = $connect($sourceName, $sourceUser, $sourcePassword, $port);
    $required = database_backup_required_schema_columns();
    $schemaSql = $createSchemaSql($required);
    foreach (explode(";\n", $schemaSql) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') $app->query($statement);
    }
    // This column is absent from the static minimum and is required only by
    // the captured production schema fingerprint.
    $app->query('ALTER TABLE `bookings` ADD COLUMN `fixture_state` VARCHAR(100) NULL');

    $insertRow($app, 'users', ['id' => '1', 'email' => 'backup-admin@example.invalid', 'role' => 'admin', 'status' => 'active', 'password_hash' => 'synthetic-test-only', 'reset_token_hash' => null]);
    $insertRow($app, 'users', ['id' => '2', 'email' => 'backup-customer@example.invalid', 'role' => 'customer', 'status' => 'active', 'password_hash' => 'synthetic-test-only', 'reset_token_hash' => null]);
    $insertRow($app, 'staff', ['user_id' => '1', 'status' => 'active']);
    $insertRow($app, 'customers', ['id' => '1', 'user_id' => '2', 'first_name' => 'Synthetic', 'last_name' => 'Customer', 'email' => 'backup-customer@example.invalid', 'phone' => '0000000000']);
    $insertRow($app, 'venues', ['id' => '1', 'name' => 'Synthetic Venue', 'category' => 'hall', 'status' => 'active']);
    $insertRow($app, 'bookings', ['id' => '1', 'customer_id' => '1', 'venue_id' => '1', 'reference_no' => 'FIXTURE-001', 'start_date' => '2030-01-01', 'end_date' => '2030-01-02', 'guests_count' => '2', 'contact_phone' => '0000000000', 'booking_status' => 'confirmed', 'payment_status' => 'paid', 'amount_paid' => '10.50', 'payment_due_at' => null, 'policy_accepted_at' => null, 'policy_version' => null, 'fixture_state' => 'before']);
    $insertRow($app, 'payments', ['id' => '1', 'booking_id' => '1', 'transaction_id' => 'fixture-payment-001', 'payment_method' => 'Cash', 'amount' => '10.50', 'payment_date' => '2030-01-01', 'status' => 'completed']);
    $insertRow($app, 'seminars', ['id' => '1', 'name' => 'Synthetic Seminar', 'agreed_price' => '10.50', 'status' => 'finalized', 'hall_venue_id' => '1', 'hall_start_date' => '2030-02-01', 'hall_end_date' => '2030-02-02', 'hotel_check_in' => '2030-02-01', 'hotel_check_out' => '2030-02-03', 'created_by' => '1', 'finalized_at' => null]);
    $insertRow($app, 'seminar_attendees', ['id' => '1', 'seminar_id' => '1', 'full_name' => 'Synthetic Attendee', 'gender' => 'unknown', 'location' => 'Fixture', 'contact' => null, 'assigned_venue_id' => '1', 'solo_flag' => '0']);
    $insertRow($app, 'seminar_payments', ['id' => '1', 'seminar_id' => '1', 'amount' => '10.50', 'payment_method' => 'Cash', 'transaction_reference' => null, 'reference_fingerprint' => null, 'idempotency_key' => str_repeat('a', 64), 'status' => 'posted', 'created_by' => '1', 'created_at' => '2030-02-01 10:00:00', 'voided_by' => null, 'voided_at' => null, 'void_reason' => null]);
    $app->query("INSERT INTO fixture_schema_extension (preserve_me) VALUES ('extension-before')");
    $app->close();

    $stagingDeniedFromSource = false;
    try { $probe = $connect($stagingName, $sourceUser, $sourcePassword, $port); $probe->close(); }
    catch (Throwable $error) { $stagingDeniedFromSource = true; }
    $sourceDeniedFromStaging = false;
    try { $probe = $connect($sourceName, $stagingUser, $stagingPassword, $port); $probe->close(); }
    catch (Throwable $error) { $sourceDeniedFromStaging = true; }
    $assert($stagingDeniedFromSource, 'the source-only account cannot connect to the staging database.');
    $assert($sourceDeniedFromStaging, 'the staging-only account cannot access or modify the source database.');

    // A correctly signed archive with the current seminar-payment table omitted
    // must fail preflight before the source database enters maintenance or changes.
    $oldSql = $createSchemaSql($required, false);
    $oldArchive = $makeArchive($oldSql, $sourceName, $appKey, $backupDir);
    $oldJob = database_backup_queue_job('restore', 1, $oldArchive, true);
    $rejected = database_backup_process_job($oldJob['id']);
    $assert(($rejected['status'] ?? null) === 'failed', 'a signed archive without seminar_payments is rejected during staging preflight.');
    $assert(empty($rejected['safety_archive_id']), 'an incompatible archive is rejected before a safety archive or production restore starts.');
    $assert(database_backup_maintenance_state() === null, 'staging schema rejection leaves no production maintenance marker.');
    $verify = $connect($sourceName, $sourceUser, $sourcePassword, $port);
    $row = $verify->query("SELECT fixture_state FROM bookings WHERE id=1")->fetch_assoc();
    $payment = $verify->query("SELECT amount FROM seminar_payments WHERE id=1")->fetch_assoc();
    $assert(($row['fixture_state'] ?? null) === 'before' && (float)$payment['amount'] === 10.5, 'staging rejection leaves synthetic production booking/payment data unchanged.');
    $verify->close();

    // Create a real dump, mutate only disposable target rows, then exercise the
    // same staging/safety/import/verify/maintenance path used by the CLI worker.
    $archive = database_backup_run_dump('manual', 1);
    $mutate = $connect($sourceName, $sourceUser, $sourcePassword, $port);
    $mutate->query("UPDATE bookings SET fixture_state='mutated' WHERE id=1");
    $mutate->query("UPDATE seminar_payments SET amount='99.99' WHERE id=1");
    $mutate->query("UPDATE fixture_schema_extension SET preserve_me='extension-mutated' WHERE id=1");
    $mutate->close();

    $restoreJob = database_backup_queue_job('restore', 1, $archive['id'], true);
    $restored = database_backup_process_job($restoreJob['id']);
    $assert(($restored['status'] ?? null) === 'succeeded', 'the isolated signed backup restores successfully through the worker job processor.');
    $assert(is_string($restored['safety_archive_id'] ?? null) && preg_match('/\Adb-[a-f0-9]{32}\.s360db\z/D', $restored['safety_archive_id']), 'the restore creates a separately signed safety archive first.');
    $verify = $connect($sourceName, $sourceUser, $sourcePassword, $port);
    $restoredBooking = $verify->query("SELECT fixture_state FROM bookings WHERE id=1")->fetch_assoc();
    $restoredPayment = $verify->query("SELECT amount, seminar_id, created_by FROM seminar_payments WHERE id=1")->fetch_assoc();
    $restoredExtension = $verify->query("SELECT preserve_me FROM fixture_schema_extension WHERE id=1")->fetch_assoc();
    $assert(($restoredBooking['fixture_state'] ?? null) === 'before', 'booking fixture data returns to its backup value.');
    $assert((float)$restoredPayment['amount'] === 10.5 && (int)$restoredPayment['seminar_id'] === 1 && (int)$restoredPayment['created_by'] === 1, 'seminar payment data and parent references return to their backup values.');
    $assert(($restoredExtension['preserve_me'] ?? null) === 'extension-before', 'production-only table/column data is retained by the captured live schema reference.');
    $assert((int)$verify->query('SELECT COUNT(*) FROM customers')->fetch_row()[0] === 1 && (int)$verify->query('SELECT COUNT(*) FROM payments')->fetch_row()[0] === 1 && (int)$verify->query('SELECT COUNT(*) FROM seminar_attendees')->fetch_row()[0] === 1, 'synthetic customer, booking transaction, and seminar attendee rows survive restore.');
    $verify->close();
    $safetyPath = database_backup_archive_path((string)$restored['safety_archive_id'], true);
    $safetyMetadata = database_backup_validate_archive($safetyPath);
    $assert(($safetyMetadata['kind'] ?? null) === 'pre_restore', 'the retained safety artifact authenticates as a pre-restore archive.');
    database_backup_remove_validation_temp($safetyMetadata);
    $assert(database_backup_maintenance_state() === null, 'successful restore removes the maintenance marker.');

    $admin->close();
    fwrite(STDOUT, "Isolated database backup/restore integration passed ({$assertions} assertions); temporary MariaDB and fixture files will be removed.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Isolated database backup/restore integration failed: ' . get_class($error) . ' code ' . (int)$error->getCode() . ' at ' . basename($error->getFile()) . ':' . $error->getLine() . "\n");
    if (str_starts_with($error->getMessage(), 'Synthetic fixture insert failed for table ')) fwrite(STDERR, $error->getMessage() . "\n");
    if (str_starts_with($error->getMessage(), 'Disposable database dump probe failed (')) fwrite(STDERR, $error->getMessage() . "\n");
    if (str_starts_with($error->getMessage(), 'The reusable staging SQL builder rejected the disposable fixture: ')) fwrite(STDERR, $error->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($containerStarted) $run([$docker, 'rm', '--force', $containerName], 20);
    if (is_string($dockerEnvPath) && is_file($dockerEnvPath) && !is_link($dockerEnvPath)) @unlink($dockerEnvPath);
    if (isset($backupDir) && is_dir($backupDir)) {
        foreach (glob($backupDir . '/db-*.s360db') ?: [] as $file) if (!is_link($file) && is_file($file)) @unlink($file);
        foreach (glob($backupDir . '/job-*.json') ?: [] as $file) if (!is_link($file) && is_file($file)) @unlink($file);
        foreach (['maintenance.json', 'worker.lock', 'operation.lock'] as $file) {
            $path = $backupDir . '/' . $file;
            if (is_file($path) && !is_link($path)) @unlink($path);
        }
        $jobs = $backupDir . '/jobs';
        if (is_dir($jobs) && !is_link($jobs)) {
            foreach (glob($jobs . '/*') ?: [] as $file) if (is_file($file) && !is_link($file)) @unlink($file);
            @rmdir($jobs);
        }
        @rmdir($backupDir);
        @rmdir($testRoot);
    }
}

exit($exitCode ?? 0);
