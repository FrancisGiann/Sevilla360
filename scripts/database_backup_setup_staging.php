<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Record true process-level overrides before the normal dotenv loader fills in
// $_ENV/$_SERVER. The web server may have its own authoritative environment.
$databaseBackupStagingKeys = ['DB_STAGING_HOST', 'DB_STAGING_USER', 'DB_STAGING_PASS', 'DB_STAGING_NAME', 'DB_STAGING_PORT'];
$databaseBackupStagingPreloaded = [];
foreach ($databaseBackupStagingKeys as $key) {
    if (getenv($key) !== false || array_key_exists($key, $_ENV) || array_key_exists($key, $_SERVER)) $databaseBackupStagingPreloaded[] = $key;
}

require_once __DIR__ . '/database_backup_setup.php';

function database_backup_setup_staging_parse_options(array $argv): array
{
    $mode = 'check';
    $modeWasSet = false;
    $help = false;
    $adminUser = null;
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--check' || $argument === '--apply') {
            if ($modeWasSet) throw new InvalidArgumentException('Choose either --check or --apply.');
            $modeWasSet = true;
            $mode = $argument === '--apply' ? 'apply' : 'check';
        } elseif ($argument === '--help' || $argument === '-h') {
            $help = true;
        } elseif (str_starts_with($argument, '--admin-user=')) {
            $adminUser = substr($argument, strlen('--admin-user='));
            if (!preg_match('/\A[a-z_][a-z0-9_$-]{0,31}\z/iD', $adminUser)) throw new InvalidArgumentException('The local database administrator account name is invalid.');
        } else {
            throw new InvalidArgumentException('Unknown staging setup option. Use --help for usage.');
        }
    }
    if ($adminUser !== null && $mode !== 'apply') throw new InvalidArgumentException('--admin-user can be used only with --apply.');
    return ['mode' => $mode, 'help' => $help, 'admin_user' => $adminUser];
}

function database_backup_setup_staging_host_allowed(string $host): bool
{
    if (strcasecmp($host, 'localhost') === 0) return true;
    $ip = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    return is_string($ip) && str_starts_with($ip, '127.');
}

function database_backup_setup_staging_quote_identifier(string $value): string
{
    // MariaDB treats underscores and percent signs in GRANT database names as
    // wildcard characters even when the identifier is quoted.
    if (!preg_match('/\A[a-z][a-z0-9]{0,62}\z/D', $value)) throw new RuntimeException('A generated staging identifier was invalid.');
    return '`' . $value . '`';
}

function database_backup_setup_staging_database_name(string $suffix): string
{
    if (!preg_match('/\A[a-f0-9]{16}\z/D', $suffix)) throw new RuntimeException('A generated staging suffix was invalid.');
    return 's360stage' . $suffix;
}

/**
 * Build the exact schema-scoped DDL used by setup. The wildcard host option is
 * reserved for the loopback-only disposable integration fixture.
 */
function database_backup_setup_staging_sql_plan(mysqli $admin, string $database, string $user, string $host, string $password, bool $allowWildcardHostForTest = false): array
{
    $quotedDatabase = database_backup_setup_staging_quote_identifier($database);
    if (!preg_match('/\As360_[a-z0-9_]{1,31}\z/D', $user)) throw new RuntimeException('A generated staging account name was invalid.');
    if (!database_backup_setup_staging_host_allowed($host) && !($allowWildcardHostForTest && $host === '%')) throw new RuntimeException('A staging account host was invalid.');
    if ($password === '' || str_contains($password, "\0") || str_contains($password, "\r") || str_contains($password, "\n")) throw new RuntimeException('A generated staging password was invalid.');
    $escapedUser = $admin->real_escape_string($user);
    $escapedHost = $admin->real_escape_string($host);
    $escapedPassword = $admin->real_escape_string($password);
    return [
        'create_database' => 'CREATE DATABASE ' . $quotedDatabase . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'create_user' => "CREATE USER '{$escapedUser}'@'{$escapedHost}' IDENTIFIED BY '{$escapedPassword}'",
        'grant' => 'GRANT ALL PRIVILEGES ON ' . $quotedDatabase . ".* TO '{$escapedUser}'@'{$escapedHost}'",
    ];
}

function database_backup_setup_staging_global_authority(array $grants): bool
{
    $privileges = [];
    $grantOption = false;
    foreach ($grants as $grant) {
        $grant = (string)$grant;
        if (!preg_match('/\bGRANT\s+(.+?)\s+ON\s+\*\.\*/i', $grant, $match)) continue;
        $privilegeText = strtoupper(trim($match[1]));
        if ($privilegeText === 'ALL PRIVILEGES' || $privilegeText === 'ALL') return stripos($grant, 'WITH GRANT OPTION') !== false;
        foreach (preg_split('/\s*,\s*/', $privilegeText) ?: [] as $privilege) $privileges[$privilege] = true;
        if (stripos($grant, 'WITH GRANT OPTION') !== false) $grantOption = true;
    }
    return $grantOption && isset($privileges['CREATE'], $privileges['DROP'], $privileges['CREATE USER']);
}

function database_backup_setup_staging_prompt_password(): string
{
    if (!function_exists('stream_isatty') || !stream_isatty(STDIN)) throw new RuntimeException('A terminal is required for a hidden local database administrator password prompt.');
    $tty = @fopen('/dev/tty', 'r');
    if ($tty === false) throw new RuntimeException('A controlling terminal is required for a hidden local database administrator password prompt.');
    $stty = database_backup_find_binary('STTY_BIN', ['stty']);
    if ($stty === null) { fclose($tty); throw new RuntimeException('The stty utility is required for a hidden local database administrator password prompt.'); }
    $setEcho = static function (bool $enabled) use ($stty, $tty): void {
        $process = @proc_open([$stty, $enabled ? 'echo' : '-echo'], [0 => $tty, 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process) || proc_close($process) !== 0) throw new RuntimeException('Terminal echo could not be changed safely for the password prompt.');
    };
    fwrite(STDOUT, 'Local database administrator password (input hidden): ');
    fflush(STDOUT);
    $password = '';
    $echoDisabled = false;
    try {
        $setEcho(false);
        $echoDisabled = true;
        $line = fgets($tty, 1026);
        if (!is_string($line) || strlen($line) > 1024) throw new RuntimeException('The local database administrator password input is invalid.');
        $password = rtrim($line, "\r\n");
        if ($password === '') throw new RuntimeException('The local database administrator password cannot be empty.');
    } finally {
        if ($echoDisabled) $setEcho(true);
        fwrite(STDOUT, "\n");
        fclose($tty);
    }
    return $password;
}

function database_backup_setup_staging_host_overrides(array $keys): array
{
    $unitFiles = array_merge(
        ['/usr/lib/systemd/system/php-fpm.service', '/etc/systemd/system/php-fpm.service'],
        glob('/etc/systemd/system/php-fpm.service.d/*.conf') ?: [],
        glob('/usr/lib/systemd/system/php-fpm.service.d/*.conf') ?: []
    );
    $paths = array_merge(
        ['/etc/php-fpm.conf', '/etc/httpd/conf/httpd.conf'],
        glob('/etc/php-fpm.d/*.conf') ?: [], glob('/etc/httpd/conf.d/*.conf') ?: [],
        glob('/etc/apache2/sites-enabled/*') ?: [],
        $unitFiles
    );
    $paths = array_merge($paths, database_backup_setup_staging_environment_files($unitFiles));
    $found = [];
    foreach (array_unique($paths) as $path) {
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) continue;
        foreach ($lines as $number => $line) {
            if (database_backup_setup_staging_line_has_override($line, $keys)) $found[] = $path . ':' . ($number + 1);
        }
    }
    return array_values(array_unique($found));
}

function database_backup_setup_staging_environment_files(array $unitFiles): array
{
    $environmentFiles = [];
    foreach ($unitFiles as $path) {
        $lines = is_string($path) ? @file($path, FILE_IGNORE_NEW_LINES) : false;
        if (!is_array($lines)) continue;
        foreach ($lines as $line) {
            if (preg_match('/\A\s*#/', $line)) continue;
            if (preg_match('/\A\s*EnvironmentFile\s*=\s*-?([^\s"]+)/', $line, $match) && str_starts_with($match[1], '/')) $environmentFiles[] = $match[1];
        }
    }
    return array_values(array_unique($environmentFiles));
}

function database_backup_setup_staging_line_has_override(string $line, array $keys): bool
{
    if (preg_match('/\A\s*[#;]/', $line)) return false;
    foreach ($keys as $key) {
        if (!is_string($key) || !preg_match('/\ADB_STAGING_(?:HOST|USER|PASS|NAME|PORT)\z/D', $key)) continue;
        if (preg_match('/\benv\s*\[\s*' . preg_quote($key, '/') . '\s*\]/i', $line)
            || preg_match('/\b(?:SetEnv|PassEnv|ProxyFCGISetEnvIf|Environment)\b[^\r\n]*\b' . preg_quote($key, '/') . '\b/i', $line)
            || preg_match('/\A\s*' . preg_quote($key, '/') . '\s*=/', $line)) return true;
    }
    return false;
}

function database_backup_setup_staging_env_text(string $contents, array $values): string
{
    foreach ($values as $key => $value) {
        if (!in_array($key, ['DB_STAGING_HOST', 'DB_STAGING_USER', 'DB_STAGING_PASS', 'DB_STAGING_NAME', 'DB_STAGING_PORT'], true) || !is_string($value)) {
            throw new RuntimeException('Only staging database settings can be written by this helper.');
        }
        $contents = database_backup_setup_update_env_text($contents, $key, $value);
    }
    return $contents;
}

function database_backup_setup_staging_cleanup(mysqli $admin, ?string $database, ?string $user, string $host, bool $databaseCreated, bool $userCreated): bool
{
    $clean = true;
    try {
        if ($userCreated && is_string($user)) {
            $escapedUser = $admin->real_escape_string($user);
            $escapedHost = $admin->real_escape_string($host);
            $admin->query("DROP USER '{$escapedUser}'@'{$escapedHost}'");
        }
    } catch (Throwable $error) { $clean = false; }
    try {
        if ($databaseCreated && is_string($database)) $admin->query('DROP DATABASE ' . database_backup_setup_staging_quote_identifier($database));
    } catch (Throwable $error) { $clean = false; }
    return $clean;
}

function database_backup_setup_staging_usage(): string
{
    return "Usage: php scripts/database_backup_setup_staging.php [--check|--apply]\n" .
        "  --check  Read configuration and local database-admin readiness without changing state (default).\n" .
        "  --apply  Create a new dedicated staging database/account and append DB_STAGING_* to .env; requires root and local DB-admin authority.\n" .
        "  --admin-user=NAME  Prompt without echo for an existing local database administrator when root socket auth is unavailable.\n";
}

function database_backup_setup_staging_main(array $argv, array $preloadedOverrides): int
{
    $admin = null;
    $productionConnection = null;
    $serverIdentityLock = null;
    $database = null;
    $user = null;
    $host = 'localhost';
    $databaseCreated = false;
    $userCreated = false;
    $databaseCreationAttempted = false;
    $userCreationAttempted = false;
    $envCommitted = false;
    try {
        $options = database_backup_setup_staging_parse_options($argv);
        if ($options['help']) { fwrite(STDOUT, database_backup_setup_staging_usage()); return 0; }
        $serviceUser = database_backup_setup_detect_service_user();
        if ($serviceUser === null && function_exists('posix_getpwnam') && posix_getpwnam('apache') !== false) $serviceUser = 'apache';
        if (!is_string($serviceUser) || !database_backup_setup_service_user_allowed($serviceUser)) throw new RuntimeException('The PHP-FPM service identity could not be determined safely.');
        $serviceAccount = function_exists('posix_getpwnam') ? posix_getpwnam($serviceUser) : false;
        if (!is_array($serviceAccount) || !database_backup_setup_service_account_allowed($serviceAccount)) throw new RuntimeException('The PHP-FPM service account must exist and have a non-root UID.');

        $envPath = dirname(__DIR__) . '/.env';
        if (is_link($envPath) || !is_file($envPath) || !is_readable($envPath)) throw new RuntimeException('The project .env file must be a readable regular file.');
        $envContents = file_get_contents($envPath);
        if (!is_string($envContents)) throw new RuntimeException('The project .env file could not be read.');
        $envValues = [];
        foreach (['DB_STAGING_HOST', 'DB_STAGING_USER', 'DB_STAGING_PASS', 'DB_STAGING_NAME', 'DB_STAGING_PORT'] as $key) {
            $envValues[$key] = database_backup_setup_env_value($envContents, $key);
            if (is_string($envValues[$key]) && $envValues[$key] !== '') throw new RuntimeException('A DB_STAGING_* setting is already configured; inspect it before provisioning another staging database.');
        }
        if ($preloadedOverrides !== []) throw new RuntimeException('Host process environment overrides staging settings (' . implode(', ', $preloadedOverrides) . '); setup will not write a conflicting .env configuration.');
        $hostOverrides = database_backup_setup_staging_host_overrides(['DB_STAGING_HOST', 'DB_STAGING_USER', 'DB_STAGING_PASS', 'DB_STAGING_NAME', 'DB_STAGING_PORT']);
        if ($hostOverrides !== []) throw new RuntimeException('A host-level DB_STAGING_* override exists in ' . implode(', ', $hostOverrides) . '; update that authoritative source instead of .env.');
        $production = database_backup_connection_config('DB_');
        if (!database_backup_setup_staging_host_allowed($production['host'])) throw new RuntimeException('Staging provisioning requires the production database server to be local (localhost or 127.0.0.0/8).');
        $host = $production['host'];
        $envAcl = database_backup_setup_capture_acl($envPath);
        if (!database_backup_setup_service_can_read_env($envPath, $serviceAccount, $envAcl)) throw new RuntimeException('The PHP-FPM service account cannot read .env; preserve its existing access rules when granting read access.');
        if (database_backup_find_binary('SETFACL_BIN', ['setfacl']) === null) throw new RuntimeException('setfacl is required to preserve the .env file access rules.');

        if ($options['mode'] === 'apply' && (!function_exists('posix_geteuid') || posix_geteuid() !== 0)) {
            throw new RuntimeException('Apply requires root for local database socket authentication and ACL-preserving .env update. Run: sudo ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --apply');
        }
        if (database_backup_app_key() === null) throw new RuntimeException('APP_KEY must already be configured with at least 32 characters; setup will not create or replace it.');

        try {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            if (is_string($options['admin_user'])) {
                $adminPassword = database_backup_setup_staging_prompt_password();
                try {
                    $admin = @new mysqli($host, $options['admin_user'], $adminPassword, 'mysql', $production['port'] ?? 3306);
                } finally {
                    $adminPassword = str_repeat("\0", strlen($adminPassword));
                }
            } else {
                $admin = @new mysqli($host, 'root', '', 'mysql', $production['port'] ?? 3306);
            }
        } catch (Throwable $error) {
            if (is_string($options['admin_user'])) throw new RuntimeException('The supplied local database administrator authentication failed; no database changes were attempted.');
            throw new RuntimeException('DB_STAGING_* settings are not configured. Local MariaDB root socket authentication is unavailable to this process; no database changes were attempted. Run under the host DB administrator identity with its existing socket authentication or provide an existing local database administrator with --admin-user.');
        }
        try {
            $productionConnection = @new mysqli($host, $production['user'], $production['password'], $production['name'], $production['port'] ?? 3306);
            $adminIdentity = $admin->query('SELECT @@server_id AS server_id, @@hostname AS hostname')->fetch_assoc();
            $productionIdentity = $productionConnection->query('SELECT @@server_id AS server_id, @@hostname AS hostname')->fetch_assoc();
            if (!is_array($adminIdentity) || !is_array($productionIdentity)
                || (string)$adminIdentity['server_id'] !== (string)$productionIdentity['server_id']
                || strcasecmp((string)$adminIdentity['hostname'], (string)$productionIdentity['hostname']) !== 0) {
                throw new RuntimeException('The database administrator connection does not match the configured production database server.');
            }
            $serverIdentityLock = 's360_setup_' . bin2hex(random_bytes(16));
            $lockStatement = $productionConnection->prepare('SELECT GET_LOCK(?, 0)');
            $lockStatement->bind_param('s', $serverIdentityLock);
            $lockStatement->execute();
            $lockAcquired = $lockStatement->get_result()->fetch_row()[0] ?? null;
            $lockStatement->close();
            if ((string)$lockAcquired !== '1') throw new RuntimeException('A same-server validation lock could not be acquired.');
            $productionConnectionId = (int)$productionConnection->query('SELECT CONNECTION_ID()')->fetch_row()[0];
            $adminLockStatement = $admin->prepare('SELECT IS_USED_LOCK(?)');
            $adminLockStatement->bind_param('s', $serverIdentityLock);
            $adminLockStatement->execute();
            $adminLockConnectionId = $adminLockStatement->get_result()->fetch_row()[0] ?? null;
            $adminLockStatement->close();
            if ((int)$adminLockConnectionId !== $productionConnectionId) throw new RuntimeException('The database administrator connection does not share the configured production server.');
        } catch (Throwable $error) {
            throw new RuntimeException('The local database administrator connection could not be confirmed against the configured production server; no database changes were attempted.');
        }
        $grants = [];
        $grantRows = $admin->query('SHOW GRANTS FOR CURRENT_USER()');
        while ($row = $grantRows->fetch_row()) $grants[] = (string)($row[0] ?? '');
        if (!database_backup_setup_staging_global_authority($grants)) throw new RuntimeException('The local database account lacks global CREATE, DROP, CREATE USER, and GRANT OPTION needed to provision and safely roll back a new staging database.');

        $database = database_backup_setup_staging_database_name(bin2hex(random_bytes(8)));
        $user = 's360_stage_' . bin2hex(random_bytes(8));
        $nameCheck = $admin->prepare('SELECT COUNT(*) AS n FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $nameCheck->bind_param('s', $database);
        $nameCheck->execute();
        $databaseExists = (int)$nameCheck->get_result()->fetch_assoc()['n'] !== 0;
        $nameCheck->close();
        if ($databaseExists || strcasecmp($database, $production['name']) === 0) throw new RuntimeException('The generated staging database name already exists or collides with production; rerun setup.');
        $accountCheck = $admin->prepare('SELECT COUNT(*) AS n FROM mysql.user WHERE User = ?');
        $accountCheck->bind_param('s', $user);
        $accountCheck->execute();
        $userExists = (int)$accountCheck->get_result()->fetch_assoc()['n'] !== 0;
        $accountCheck->close();
        if ($userExists) throw new RuntimeException('The generated staging account already exists; setup will not reuse it.');

        $envStat = stat($envPath);
        if (!is_array($envStat)) throw new RuntimeException('The .env file metadata could not be read safely.');
        $portText = $production['port'] === null ? '' : (string)$production['port'];
        $values = [
            'DB_STAGING_HOST' => $host,
            'DB_STAGING_USER' => $user,
            'DB_STAGING_PASS' => bin2hex(random_bytes(32)),
            'DB_STAGING_NAME' => $database,
            'DB_STAGING_PORT' => $portText,
        ];
        $newEnvContents = database_backup_setup_staging_env_text($envContents, $values);
        if ($options['mode'] === 'check') {
            fwrite(STDOUT, "Local root socket authentication and required global database privileges are available.\n");
            fwrite(STDOUT, "Restore staging settings are absent and .env ACLs permit the PHP-FPM identity to read configuration.\n");
            fwrite(STDOUT, "Check mode made no changes. Apply with: sudo " . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " --apply\n");
            return 0;
        }

        $sqlPlan = database_backup_setup_staging_sql_plan($admin, $database, $user, $host, $values['DB_STAGING_PASS']);
        $databaseCreationAttempted = true;
        $admin->query($sqlPlan['create_database']);
        $databaseCreated = true;
        $userCreationAttempted = true;
        $admin->query($sqlPlan['create_user']);
        $userCreated = true;
        $admin->query($sqlPlan['grant']);
        $stagingConnection = @new mysqli($host, $user, $values['DB_STAGING_PASS'], $database, $production['port'] ?? 3306);
        try {
            $selectedDatabase = (string)$stagingConnection->query('SELECT DATABASE()')->fetch_row()[0];
            if (strcasecmp($selectedDatabase, $database) !== 0) throw new RuntimeException('The new staging account did not connect to its dedicated database.');
        } finally {
            $stagingConnection->close();
        }
        database_backup_setup_write_atomic($envPath, $newEnvContents, (int)$envStat['mode'] & 07777, (int)$envStat['uid'], (int)$envStat['gid'], $envAcl);
        $envCommitted = true;
        fwrite(STDOUT, "Created a new private restore staging database and schema-scoped account; DB_STAGING_* was added to .env with its existing owner, mode, and ACLs preserved. APP_KEY, BACKUP_DIR, and the worker schedule were left unchanged.\n");
        fwrite(STDOUT, "Run the isolated restore test separately with: php scripts/test_database_backup_restore_isolated.php --run\n");
        return 0;
    } catch (Throwable $error) {
        if ($admin instanceof mysqli && !$envCommitted && ($databaseCreated || $userCreated) && !database_backup_setup_staging_cleanup($admin, $database, $user, $host, $databaseCreated, $userCreated)) {
            fwrite(STDERR, "Rollback of a newly created staging object was incomplete; the private .env file was not committed. Ask the database administrator to inspect the new s360stage* schema or s360_stage_* account.\n");
        } elseif (!$envCommitted && ($databaseCreationAttempted || $userCreationAttempted) && (!$databaseCreated || !$userCreated)) {
            fwrite(STDERR, "A staging DDL request may have reached MariaDB before setup stopped. The .env file was not committed; ask the database administrator to inspect the new s360stage* schema or s360_stage_* account.\n");
        }
        $message = $error instanceof mysqli_sql_exception
            ? 'Local MariaDB staging setup failed; database details and generated account names were not written to output.'
            : $error->getMessage();
        fwrite(STDERR, 'Database backup staging setup failed: ' . $message . "\n");
        return 1;
    } finally {
        if ($productionConnection instanceof mysqli) {
            if (is_string($serverIdentityLock)) {
                try {
                    $releaseStatement = $productionConnection->prepare('SELECT RELEASE_LOCK(?)');
                    $releaseStatement->bind_param('s', $serverIdentityLock);
                    $releaseStatement->execute();
                    $releaseStatement->close();
                } catch (Throwable $error) {}
            }
            $productionConnection->close();
        }
        if ($admin instanceof mysqli) $admin->close();
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(database_backup_setup_staging_main($argv, $databaseBackupStagingPreloaded));
}
