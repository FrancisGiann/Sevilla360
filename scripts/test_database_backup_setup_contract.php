<?php
declare(strict_types=1);

require_once __DIR__ . '/database_backup_setup.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
    $assertions++;
};
$throws = static function (callable $operation, string $message) use ($assert): void {
    try { $operation(); }
    catch (Throwable $error) { $assert(true, $message); return; }
    $assert(false, $message);
};

$testRoot = sys_get_temp_dir() . '/sevilla360-backup-setup-test-' . bin2hex(random_bytes(8));
if (!mkdir($testRoot, 0700)) throw new RuntimeException('Unable to create isolated setup test directory.');
$knownFiles = [];
$knownDirs = [$testRoot];
try {
    $originalEnv = "APP_KEY=fixture-key-do-not-print\nBACKUP_DIR=/var/lib/sevilla360/backups\nDB_NAME=fixture\n";
    $updatedEnv = database_backup_setup_update_env_text($originalEnv, 'BACKUP_DIR', '/var/lib/sevilla360/sevilla360-database-backups');
    $assert(str_contains($updatedEnv, "APP_KEY=fixture-key-do-not-print\n"), 'the existing APP_KEY line is preserved byte-for-byte.');
    $assert(str_contains($updatedEnv, "BACKUP_DIR=/var/lib/sevilla360/sevilla360-database-backups\n"), 'only the BACKUP_DIR value is replaced.');
    $assert(str_contains($updatedEnv, "DB_NAME=fixture\n") && !str_contains($updatedEnv, 'BACKUP_DIR=/var/lib/sevilla360/backups'), 'unrelated environment settings remain unchanged.');
    $assert(database_backup_setup_env_value($updatedEnv, 'BACKUP_DIR') === '/var/lib/sevilla360/sevilla360-database-backups', 'the resulting BACKUP_DIR can be read from dotenv text.');
    $throws(static fn() => database_backup_setup_env_value("BACKUP_DIR=/one\nBACKUP_DIR=/two\n", 'BACKUP_DIR'), 'duplicate BACKUP_DIR entries are rejected.');
    $throws(static fn() => database_backup_setup_update_env_text($originalEnv, 'BACKUP_DIR', "/tmp/unsafe\nAPP_KEY=changed"), 'multiline environment values are rejected.');

    $parent = $testRoot . '/private-parent';
    mkdir($parent, 0700);
    $knownDirs[] = $parent;
    $target = $parent . '/sevilla360-database-backups';
    $assert(database_backup_setup_safe_target($target), 'a private target outside the project/document roots is accepted.');
    $selinuxTarget = $parent . '/backup.v1+private';
    $selinuxExpression = database_backup_setup_selinux_expression($selinuxTarget);
    $assert($selinuxExpression === preg_quote($selinuxTarget, '#') . '(/.*)?', 'the persistent SELinux expression scopes the exact literal directory and its descendants.');
    $assert(database_backup_setup_find_host_binary('ls') !== null && database_backup_setup_find_host_binary('../ls') === null, 'SELinux setup tools resolve only safe root-owned host binaries.');
    $restorecon = database_backup_setup_find_host_binary('restorecon');
    if ($restorecon !== null) {
        $assert(basename($restorecon) === 'restorecon', 'argv[0]-sensitive SELinux tools retain their trusted alias path.');
        $restoreconHelp = database_backup_setup_run([$restorecon, '-h']);
        $assert(str_contains(strtolower($restoreconHelp['stdout'] . $restoreconHelp['stderr']), 'usage:') && str_contains($restoreconHelp['stdout'] . $restoreconHelp['stderr'], 'restorecon'), 'the restorecon alias receives restorecon-compatible arguments.');
    }
    $assert(str_contains(database_backup_setup_restorecon_failure(1, 'restorecon: Permission denied'), 'process could not traverse or relabel'), 'restorecon permission failures are reported by safe category.');
    $assert(str_contains(database_backup_setup_restorecon_failure(1, 'invalid context'), 'does not define the required label'), 'unsupported SELinux labels receive an actionable safe diagnosis.');
    $genericRestoreconError = database_backup_setup_restorecon_failure(1, 'failure at /private/path/archive.sql');
    $assert(!str_contains($genericRestoreconError, '/private/path/archive.sql') && str_contains($genericRestoreconError, 'status 1'), 'unexpected restorecon output is reduced to status without leaking paths.');
    $selinuxListing = $selinuxExpression . "    all files    system_u:object_r:httpd_sys_rw_content_t:s0\n";
    $assert(database_backup_setup_selinux_mapping_type($selinuxListing, $selinuxExpression) === 'httpd_sys_rw_content_t', 'the exact dedicated SELinux mapping is recognized.');
    $assert(database_backup_setup_selinux_mapping_type('/var/lib/sevilla360(/.*)?    all files    system_u:object_r:httpd_sys_rw_content_t:s0', $selinuxExpression) === null, 'a broader SELinux mapping is not mistaken for the dedicated rule.');
    $wrongTypeListing = $selinuxExpression . "    all files    system_u:object_r:var_lib_t:s0\n";
    $assert(database_backup_setup_selinux_mapping_type($wrongTypeListing, $selinuxExpression) === 'var_lib_t', 'a conflicting exact SELinux mapping is detected for safe replacement.');
    $assert(database_backup_setup_selinux_context_type('unconfined_u:object_r:httpd_sys_rw_content_t:s0 /var/lib/private') === 'httpd_sys_rw_content_t', 'actual SELinux context output is reduced to its type.');
    $assert(database_backup_setup_selinux_check_passes('httpd_sys_rw_content_t', 'httpd_sys_rw_content_t', true, ''), 'read-only SELinux checks pass only when the directory and subtree match the persistent policy.');
    $assert(!database_backup_setup_selinux_check_passes('var_lib_t', 'httpd_sys_rw_content_t', true, 'pending relabel') && !database_backup_setup_selinux_check_passes(null, 'httpd_sys_rw_content_t', false, ''), 'a mismatched or unreadable SELinux subtree requires --apply.');
    $assert(database_backup_setup_directory_state($target, posix_geteuid()) === 'absent', 'a new target is reported as absent without creation.');
    $assert(!database_backup_setup_safe_target(dirname(__DIR__) . '/private-backup-test'), 'a target beneath the application root is rejected.');
    $assert(!database_backup_setup_safe_target('/var/www/html/sevilla360-backup-sibling'), 'a sibling backup folder beneath the known Apache document root is rejected in CLI checks.');
    $_SERVER['DOCUMENT_ROOT'] = $testRoot;
    $docrootChild = $testRoot . '/web-child';
    mkdir($docrootChild, 0700);
    $knownDirs[] = $docrootChild;
    $assert(!database_backup_setup_safe_target($docrootChild . '/private'), 'a target beneath the document root is rejected.');
    $assert(!database_backup_setup_safe_target($docrootChild . '/sibling-backup', [$testRoot]), 'a sibling directory below an explicit document root is rejected.');
    unset($_SERVER['DOCUMENT_ROOT']);

    $account = posix_getpwuid(posix_geteuid());
    if (!is_array($account)) throw new RuntimeException('Unable to resolve the test account.');
    $private = $testRoot . '/ready';
    mkdir($private, 0700);
    $knownDirs[] = $private;
    $assert(database_backup_setup_directory_state($private, posix_geteuid()) === 'ready', 'a service-owned 0700 directory passes readiness checks.');
    $nonempty = $testRoot . '/nonempty';
    mkdir($nonempty, 0700);
    $knownDirs[] = $nonempty;
    $pendingJob = $nonempty . '/job.json';
    file_put_contents($pendingJob, '{}');
    $knownFiles[] = $pendingJob;
    $assert(database_backup_setup_directory_state($nonempty, posix_geteuid(), $testRoot . '/different-backup-dir') === 'unsafe-nonempty', 'a nonempty service-owned target is not adopted when it differs from BACKUP_DIR.');
    $wrongMode = $testRoot . '/wrong-mode';
    mkdir($wrongMode, 0700);
    chmod($wrongMode, 0750);
    $knownDirs[] = $wrongMode;
    $assert(database_backup_setup_directory_state($wrongMode, posix_geteuid()) === 'unsafe-owner-or-mode', 'a group-readable directory is rejected.');
    $symlink = $testRoot . '/directory-link';
    symlink($private, $symlink);
    $assert(database_backup_setup_directory_state($symlink, posix_geteuid()) === 'unsafe-symlink', 'a symlinked target is rejected.');

    $created = $testRoot . '/created-private';
    $assert(database_backup_setup_create_private_directory($created, $account), 'private directory provisioning creates the requested directory.');
    $knownDirs[] = $created;
    $assert(database_backup_setup_directory_state($created, posix_geteuid()) === 'ready', 'provisioned directories retain owner-only mode.');
    $log = $created . '/worker.log';
    $knownFiles[] = $log;
    $assert(database_backup_setup_ensure_private_log($log, $account), 'the private worker log is created when absent.');
    clearstatcache(true, $log);
    $logStat = stat($log);
    $assert(is_array($logStat) && (int)$logStat['uid'] === posix_geteuid() && (((int)$logStat['mode'] & 07777) === 0600), 'the worker log is owner-only.');
    $assert(!database_backup_setup_ensure_private_log($log, $account), 'an already safe worker log is reused without replacement.');
    $cron = database_backup_setup_cron_contents('apache', '/usr/bin/php', '/var/www/html/Sevilla360/scripts/database_backup_worker.php', $created . '/worker.log');
    $assert(str_contains($cron, '* * * * * apache') === false && str_contains($cron, '*/5 * * * * apache'), 'the worker is scheduled every five minutes as the PHP service user.');
    $assert(str_contains($cron, 'umask 077') && str_contains($cron, $created . '/worker.log'), 'the cron log is created with a private umask inside the backup directory.');
    $throws(static fn() => database_backup_setup_cron_contents('apache', '/usr/bin/php%unsafe', '/worker.php', '/worker.log'), 'cron paths containing percent signs are rejected.');
    $throws(static fn() => database_backup_setup_cron_contents('apache', '/usr/bin/php', "/worker.php\n* * * * * root unsafe", '/worker.log'), 'cron paths containing line breaks are rejected.');
    $assert(database_backup_setup_cron_metadata_is_safe(0, 0644), 'a root-owned non-group-writable cron entry is accepted.');
    $assert(!database_backup_setup_cron_metadata_is_safe(1000, 0644), 'a non-root-owned cron entry is rejected.');
    $assert(!database_backup_setup_cron_metadata_is_safe(0, 0664), 'a group-writable cron entry is rejected.');
    $assert(database_backup_setup_service_user_allowed('apache') && !database_backup_setup_service_user_allowed('root') && !database_backup_setup_service_user_allowed('ROOT'), 'the backup worker identity cannot be root.');
    $assert(database_backup_setup_service_account_allowed(['name' => 'apache', 'uid' => 48, 'gid' => 48]), 'a non-root service account UID is accepted.');
    $assert(!database_backup_setup_service_account_allowed(['name' => 'backup-runner', 'uid' => 0, 'gid' => 0]), 'an alias with root UID is rejected even when its name is not root.');
    $assert(!database_backup_setup_line_has_backup_dir_override('# Environment=BACKUP_DIR=/example'), 'commented host environment overrides are ignored.');
    $assert(!database_backup_setup_line_has_backup_dir_override('; SetEnv BACKUP_DIR /example'), 'semicolon comments are ignored.');
    $assert(database_backup_setup_line_has_backup_dir_override('Environment="BACKUP_DIR=/example"'), 'active host environment overrides are detected.');

    $envPath = $testRoot . '/.env';
    $knownFiles[] = $envPath;
    file_put_contents($envPath, $originalEnv);
    chmod($envPath, 0640);
    $aclBinary = database_backup_find_binary('SETFACL_BIN', ['setfacl']);
    if ($aclBinary !== null && function_exists('posix_getpwnam') && posix_getpwnam('apache') !== false) {
        $setAcl = database_backup_setup_run([$aclBinary, '-m', 'u:apache:r--', '--', $envPath]);
        $assert($setAcl['status'] === 0, 'the ACL fixture is installed on an isolated environment file.');
        $beforeAcl = database_backup_setup_capture_acl($envPath);
        $apache = posix_getpwnam('apache');
        $assert(database_backup_setup_service_can_read_env($envPath, $apache, $beforeAcl), 'the configured service account ACL is recognized.');
        $envStat = stat($envPath);
        $newContents = database_backup_setup_update_env_text($originalEnv, 'BACKUP_DIR', '/var/lib/sevilla360/sevilla360-database-backups');
        database_backup_setup_write_atomic($envPath, $newContents, (int)$envStat['mode'] & 07777, (int)$envStat['uid'], (int)$envStat['gid'], $beforeAcl);
        $afterAcl = database_backup_setup_capture_acl($envPath);
        $assert($beforeAcl === $afterAcl, 'atomic .env replacement preserves named ACLs.');
        $assert(file_get_contents($envPath) === $newContents, 'atomic .env replacement writes only the requested setting.');
    }

    $options = database_backup_setup_parse_options(['setup.php', '--check', '--service-user=apache']);
    $assert(!$options['apply'] && $options['service_user'] === 'apache', 'check mode accepts an explicit service account.');
    $throws(static fn() => database_backup_setup_parse_options(['setup.php', '--apply', '--check']), 'conflicting setup modes are rejected.');
    $throws(static fn() => database_backup_setup_parse_options(['setup.php', '--service-user=apache;touch']), 'unsafe service account names are rejected.');
} finally {
    foreach ($knownFiles as $path) if (is_file($path) && !is_link($path)) unlink($path);
    if (is_link($symlink ?? '')) unlink($symlink);
    foreach (array_reverse($knownDirs) as $path) if (is_dir($path) && !is_link($path)) rmdir($path);
    if (is_dir($testRoot)) @rmdir($testRoot);
}

echo "Database backup setup contract checks passed ({$assertions} assertions).\n";
