<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../includes/database_backup.php';

const DATABASE_BACKUP_SETUP_CRON_PATH = '/etc/cron.d/sevilla360-database-backup';

function database_backup_setup_parse_options(array $argv): array
{
    $options = ['apply' => false, 'help' => false, 'service_user' => null];
    $modeWasSet = false;
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--apply' || $argument === '--check') {
            if ($modeWasSet) throw new InvalidArgumentException('Choose either --check or --apply.');
            $modeWasSet = true;
            $options['apply'] = $argument === '--apply';
        }
        elseif ($argument === '--help' || $argument === '-h') $options['help'] = true;
        elseif (str_starts_with($argument, '--service-user=')) {
            $value = substr($argument, strlen('--service-user='));
            if (!preg_match('/\A[a-z_][a-z0-9_-]{0,30}\$?\z/iD', $value)) throw new InvalidArgumentException('The service user name is invalid.');
            $options['service_user'] = $value;
        } else {
            throw new InvalidArgumentException('Unknown database backup setup option. Use --help for usage.');
        }
    }
    return $options;
}

function database_backup_setup_detect_service_user(): ?string
{
    $users = [];
    foreach (glob('/etc/php-fpm.d/*.conf') ?: [] as $path) {
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) continue;
        foreach ($lines as $line) {
            if (preg_match('/\A\s*#/', $line)) continue;
            if (preg_match('/\A\s*user\s*=\s*([a-z_][a-z0-9_-]*\$?)\s*\z/iD', $line, $match)) $users[$match[1]] = true;
        }
    }
    return count($users) === 1 ? (string)array_key_first($users) : null;
}

function database_backup_setup_env_value(string $contents, string $key): ?string
{
    $found = [];
    foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
        if (preg_match('/\A\s*#/', $line) || !str_contains($line, '=')) continue;
        [$name, $value] = explode('=', $line, 2);
        if (trim($name) !== $key) continue;
        $value = trim($value);
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }
        $found[] = $value;
    }
    if (count($found) > 1) throw new RuntimeException("The .env file has duplicate {$key} entries.");
    return $found[0] ?? null;
}

function database_backup_setup_update_env_text(string $contents, string $key, string $value): string
{
    if (!preg_match('/\A[A-Z][A-Z0-9_]*\z/D', $key) || str_contains($value, "\0") || str_contains($value, "\r") || str_contains($value, "\n") || str_contains($value, '"') || str_contains($value, "'")) {
        throw new RuntimeException('The environment value cannot be written safely.');
    }
    $newline = str_contains($contents, "\r\n") ? "\r\n" : "\n";
    $lines = preg_split('/(?<=\n)/', $contents, -1, PREG_SPLIT_NO_EMPTY);
    $matches = 0;
    foreach ($lines as &$line) {
        $ending = '';
        if (str_ends_with($line, "\r\n")) { $ending = "\r\n"; $body = substr($line, 0, -2); }
        elseif (str_ends_with($line, "\n")) { $ending = "\n"; $body = substr($line, 0, -1); }
        else { $body = $line; }
        if (preg_match('/\A([ \t]*' . preg_quote($key, '/') . '[ \t]*=).*/D', $body, $match)) {
            $line = $match[1] . $value . $ending;
            $matches++;
        }
    }
    unset($line);
    if ($matches > 1) throw new RuntimeException("The .env file has duplicate {$key} entries.");
    if ($matches === 0) {
        if ($contents !== '' && !str_ends_with($contents, "\n")) $lines[] = $newline;
        $lines[] = $key . '=' . $value . $newline;
    }
    return implode('', $lines);
}

function database_backup_setup_preloaded_overrides(): array
{
    $serviceFiles = [
        '/etc/php-fpm.conf', '/etc/httpd/conf/httpd.conf',
        '/usr/lib/systemd/system/php-fpm.service', '/etc/systemd/system/php-fpm.service',
    ];
    $unitFiles = array_merge(
        ['/usr/lib/systemd/system/php-fpm.service', '/etc/systemd/system/php-fpm.service'],
        glob('/etc/systemd/system/php-fpm.service.d/*.conf') ?: [],
        glob('/usr/lib/systemd/system/php-fpm.service.d/*.conf') ?: []
    );
    $paths = array_merge(
        $serviceFiles,
        glob('/etc/php-fpm.d/*.conf') ?: [],
        glob('/etc/httpd/conf.d/*.conf') ?: [],
        $unitFiles
    );
    $environmentFiles = [];
    foreach ($unitFiles as $path) {
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) continue;
        foreach ($lines as $line) {
            if (preg_match('/\A\s*#/', $line)) continue;
            if (preg_match('/\A\s*EnvironmentFile\s*=\s*-?([^\s"]+)/', $line, $match) && str_starts_with($match[1], '/')) {
                $environmentFiles[] = $match[1];
            }
        }
    }
    $paths = array_merge($paths, $environmentFiles);
    $overrides = [];
    foreach (array_unique($paths) as $path) {
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) continue;
        foreach ($lines as $number => $line) {
            if (preg_match('/\A\s*[#;]/', $line)) continue;
            $active = trim(preg_replace('/\s+#.*$/', '', $line) ?? '');
            if (database_backup_setup_line_has_backup_dir_override($active)) {
                $overrides[] = $path . ':' . ($number + 1);
            }
        }
    }
    return array_values(array_unique($overrides));
}

function database_backup_setup_line_has_backup_dir_override(string $line): bool
{
    if (preg_match('/\A\s*[#;]/', $line)) return false;
    $active = trim(preg_replace('/\s+#.*$/', '', $line) ?? '');
    if ($active === '') return false;
    return (bool)(preg_match('/\benv\s*\[\s*BACKUP_DIR\s*\]/i', $active)
        || preg_match('/\b(?:SetEnv|PassEnv|ProxyFCGISetEnvIf)\b[^\r\n]*\bBACKUP_DIR\b/i', $active)
        || preg_match('/\bEnvironment\s*=.*\bBACKUP_DIR\s*=/i', $active)
        || preg_match('/\A\s*BACKUP_DIR\s*=/', $active));
}

function database_backup_setup_run(array $command, ?string $stdin = null): array
{
    if (!function_exists('proc_open') || in_array('proc_open', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)) {
        throw new RuntimeException('PHP process execution is unavailable for preserving environment file ACLs.');
    }
    $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('A required setup utility could not be started.');
    if (is_string($stdin) && $stdin !== '') fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    return ['status' => $status, 'stdout' => is_string($stdout) ? $stdout : '', 'stderr' => is_string($stderr) ? $stderr : ''];
}

function database_backup_setup_capture_acl(string $path): string
{
    $binary = database_backup_find_binary('GETFACL_BIN', ['getfacl']);
    if ($binary === null) throw new RuntimeException('getfacl is required to preserve the .env file access rules.');
    $result = database_backup_setup_run([$binary, '-cp', '--', $path]);
    if ($result['status'] !== 0 || trim($result['stdout']) === '') throw new RuntimeException('The .env access rules could not be read safely.');
    return $result['stdout'];
}

function database_backup_setup_apply_acl(string $path, string $acl): void
{
    $binary = database_backup_find_binary('SETFACL_BIN', ['setfacl']);
    if ($binary === null) throw new RuntimeException('setfacl is required to preserve the .env file access rules.');
    $result = database_backup_setup_run([$binary, '--set-file=-', '--', $path], $acl);
    if ($result['status'] !== 0) throw new RuntimeException('The .env access rules could not be preserved.');
}

function database_backup_setup_service_can_read_env(string $envPath, array $serviceAccount, string $acl): bool
{
    $stat = @stat($envPath);
    if (!is_array($stat)) return false;
    if ((int)$stat['uid'] === (int)$serviceAccount['uid']) return (((int)$stat['mode'] & 0400) !== 0);
    $mask = null;
    $namedUser = null;
    foreach (preg_split('/\r?\n/', $acl) ?: [] as $entry) {
        if (preg_match('/\Amask::([rwx-]{3})\z/', trim($entry), $match)) $mask = $match[1];
        if (preg_match('/\Auser:' . preg_quote((string)$serviceAccount['name'], '/') . ':([rwx-]{3})\z/', trim($entry), $match)) $namedUser = $match[1];
    }
    if (!is_string($namedUser)) return false;
    return str_contains($namedUser, 'r') && ($mask === null || str_contains($mask, 'r'));
}

function database_backup_setup_document_roots(): array
{
    // The CLI has no DOCUMENT_ROOT. Include the common deployment root and
    // derive any explicitly configured Apache roots without relying on FPM env.
    $roots = ['/var/www/html'];
    foreach (array_merge(glob('/etc/httpd/conf.d/*.conf') ?: [], ['/etc/httpd/conf/httpd.conf'], glob('/etc/apache2/sites-enabled/*') ?: [], ['/etc/apache2/apache2.conf']) as $configPath) {
        $lines = @file($configPath, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) continue;
        foreach ($lines as $line) {
            if (preg_match('/\A\s*#/', $line)) continue;
            if (preg_match('/\A\s*DocumentRoot\s+["\']?([^"\'\s#]+)/i', $line, $match) && str_starts_with($match[1], '/')) $roots[] = $match[1];
        }
    }
    $documentRoot = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($documentRoot !== '') $roots[] = $documentRoot;
    return array_values(array_unique($roots));
}

function database_backup_setup_safe_target(string $path, ?array $documentRoots = null): bool
{
    if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || str_contains($path, '\\') || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path) || preg_match('/\s/', $path)) return false;
    $parts = explode('/', trim($path, '/'));
    if (count($parts) < 3 || in_array('', $parts, true)) return false;
    if (in_array($path, ['/', '/tmp', '/var', '/var/www', '/var/www/html', '/home', '/root', '/usr', '/opt', '/etc', '/mnt', '/media', '/run', '/dev', '/proc', '/sys'], true)) return false;
    $projectRoot = realpath(dirname(__DIR__));
    if (!is_string($projectRoot)) return false;
    $parent = dirname($path);
    $realParent = realpath($parent);
    if (!is_string($realParent) || !is_dir($realParent)) return false;
    $parentStat = @stat($realParent);
    if (!is_array($parentStat) || (((int)$parentStat['mode'] & 0022) !== 0)) return false;
    $resolved = rtrim($realParent, '/') . '/' . basename($path);
    foreach (array_merge([$projectRoot], $documentRoots ?? database_backup_setup_document_roots()) as $root) {
        if ($root === '' || $root === '/') continue;
        $realRoot = realpath($root);
        if ($realRoot !== false && ($resolved === rtrim($realRoot, '/') || str_starts_with($resolved, rtrim($realRoot, '/') . '/'))) return false;
    }
    $probe = $path;
    while ($probe !== '/') {
        if (is_link($probe)) return false;
        $probe = dirname($probe);
    }
    return true;
}

function database_backup_setup_directory_state(string $path, int $serviceUid, ?string $currentBackupDirectory = null): string
{
    if (is_link($path)) return 'unsafe-symlink';
    if (!file_exists($path)) return 'absent';
    if (!is_dir($path)) return 'unsafe-not-directory';
    $stat = @stat($path);
    if (!is_array($stat) || (int)$stat['uid'] !== $serviceUid || (((int)$stat['mode'] & 07777) !== 0700)) return 'unsafe-owner-or-mode';
    $entries = @scandir($path);
    $canonicalCurrent = is_string($currentBackupDirectory) ? realpath($currentBackupDirectory) : false;
    $canonicalTarget = realpath($path);
    if (is_array($entries) && count($entries) > 2 && (!is_string($canonicalCurrent) || $canonicalCurrent !== $canonicalTarget)) return 'unsafe-nonempty';
    return 'ready';
}

function database_backup_setup_cron_contents(string $serviceUser, string $phpBinary, string $workerPath, string $logPath): string
{
    foreach ([$phpBinary, $workerPath, $logPath] as $path) {
        if (str_contains($path, '%') || str_contains($path, "\r") || str_contains($path, "\n")) throw new RuntimeException('Cron command paths cannot contain percent signs or line breaks.');
    }
    $command = 'umask 077; ' . escapeshellarg($phpBinary) . ' ' . escapeshellarg($workerPath) . ' >> ' . escapeshellarg($logPath) . ' 2>&1';
    return "SHELL=/bin/sh\nPATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n*/5 * * * * {$serviceUser} /bin/sh -c " . escapeshellarg($command) . "\n";
}

function database_backup_setup_cron_file_is_safe(string $path): bool
{
    if (is_link($path) || !is_file($path)) return false;
    $stat = @stat($path);
    return is_array($stat) && database_backup_setup_cron_metadata_is_safe((int)$stat['uid'], (int)$stat['mode']);
}

function database_backup_setup_cron_metadata_is_safe(int $uid, int $mode): bool
{
    return $uid === 0 && (($mode & 0022) === 0);
}

function database_backup_setup_service_user_allowed(string $serviceUser): bool
{
    return $serviceUser !== '' && strtolower($serviceUser) !== 'root';
}

function database_backup_setup_service_account_allowed(array $serviceAccount): bool
{
    return isset($serviceAccount['uid']) && is_numeric($serviceAccount['uid']) && (int)$serviceAccount['uid'] > 0;
}

function database_backup_setup_write_atomic(string $path, string $contents, int $mode, int $uid, int $gid, ?string $acl = null): void
{
    $directory = dirname($path);
    $temp = tempnam($directory, '.database-backup-setup-');
    if ($temp === false) throw new RuntimeException('Unable to prepare a private setup file.');
    try {
        $stream = @fopen($temp, 'wb');
        if ($stream === false) throw new RuntimeException('Unable to write a private setup file.');
        try {
            $length = strlen($contents); $offset = 0;
            while ($offset < $length) {
                $written = fwrite($stream, substr($contents, $offset));
                if ($written === false || $written === 0) throw new RuntimeException('Unable to write a private setup file.');
                $offset += $written;
            }
            if (!fflush($stream)) throw new RuntimeException('Unable to flush a private setup file.');
            if (function_exists('fsync')) @fsync($stream);
        } finally {
            fclose($stream);
        }
        if (!@chown($temp, $uid) || !@chgrp($temp, $gid) || !@chmod($temp, $mode)) throw new RuntimeException('Unable to preserve the setup file owner and mode.');
        if ($acl !== null) database_backup_setup_apply_acl($temp, $acl);
        if (!@rename($temp, $path)) throw new RuntimeException('Unable to install the setup file atomically.');
    } finally {
        if (is_file($temp) && !is_link($temp)) @unlink($temp);
    }
}

function database_backup_setup_create_private_directory(string $path, array $serviceAccount): bool
{
    if (file_exists($path) || is_link($path)) return false;
    if (!@mkdir($path, 0700)) throw new RuntimeException('Unable to create the dedicated private backup directory. Run setup with sufficient filesystem privileges.');
    try {
        if (!@chown($path, (int)$serviceAccount['uid']) || !@chgrp($path, (int)$serviceAccount['gid']) || !@chmod($path, 0700)) {
            throw new RuntimeException('Unable to assign the private backup directory to the PHP service account.');
        }
        clearstatcache(true, $path);
        $stat = @stat($path);
        if (!is_array($stat) || (int)$stat['uid'] !== (int)$serviceAccount['uid'] || (((int)$stat['mode'] & 07777) !== 0700)) {
            throw new RuntimeException('The new backup directory did not pass its ownership and mode check.');
        }
        return true;
    } catch (Throwable $error) {
        $entries = @scandir($path);
        if (is_array($entries) && count($entries) === 2) @rmdir($path);
        throw $error;
    }
}

function database_backup_setup_ensure_private_log(string $path, array $serviceAccount): bool
{
    if (is_link($path)) throw new RuntimeException('The private worker log path is a symlink.');
    if (file_exists($path)) {
        $stat = @stat($path);
        if (!is_array($stat) || !is_file($path) || (int)$stat['uid'] !== (int)$serviceAccount['uid'] || (((int)$stat['mode'] & 07777) !== 0600)) {
            throw new RuntimeException('The existing worker log has unsafe ownership or permissions; inspect it before setup.');
        }
        return false;
    }
    $stream = @fopen($path, 'x+b');
    if ($stream === false) throw new RuntimeException('Unable to create the private worker log.');
    fclose($stream);
    if (!@chown($path, (int)$serviceAccount['uid']) || !@chgrp($path, (int)$serviceAccount['gid']) || !@chmod($path, 0600)) {
        @unlink($path);
        throw new RuntimeException('Unable to secure the private worker log.');
    }
    return true;
}

function database_backup_setup_systemd_active(string $unit): bool
{
    $binary = database_backup_find_binary('SYSTEMCTL_BIN', ['systemctl']);
    if ($binary === null) return false;
    $result = database_backup_setup_run([$binary, 'is-active', '--quiet', $unit]);
    return $result['status'] === 0;
}

function database_backup_setup_host_directory_chain_is_safe(string $directory): bool
{
    $current = realpath($directory);
    if ($current === false || !is_dir($current)) return false;
    while (true) {
        $stat = @stat($current);
        if (!is_array($stat) || (int)$stat['uid'] !== 0 || (((int)$stat['mode'] & 0022) !== 0)) return false;
        if ($current === DIRECTORY_SEPARATOR) return true;
        $parent = dirname($current);
        if ($parent === $current) return false;
        $current = $parent;
    }
}

function database_backup_setup_find_host_binary(string $name): ?string
{
    if (!preg_match('/\A[a-z0-9_-]+\z/iD', $name)) return null;
    foreach (['/usr/sbin', '/usr/bin', '/sbin', '/bin'] as $directory) {
        $entry = $directory . DIRECTORY_SEPARATOR . $name;
        $entryStat = @lstat($entry);
        $path = @realpath($entry);
        if (!is_array($entryStat) || $path === false || !is_file($path) || !is_executable($path)) continue;

        // Validate both the invoked alias and its canonical target, but keep
        // the alias path so argv[0]-sensitive symlinks such as restorecon work.
        $targetStat = @stat($path);
        if ((int)$entryStat['uid'] !== 0 || !is_array($targetStat) || (int)$targetStat['uid'] !== 0
            || (((int)$targetStat['mode'] & 0022) !== 0)
            || !database_backup_setup_host_directory_chain_is_safe(dirname($entry))
            || !database_backup_setup_host_directory_chain_is_safe(dirname($path))) continue;
        if (($entryStat['mode'] & 0170000) !== 0120000 && (((int)$entryStat['mode'] & 0022) !== 0)) continue;
        return $entry;
    }
    return null;
}

/** Return a safe, non-path-bearing explanation for restorecon failures. */
function database_backup_setup_restorecon_failure(int $status, string $output): string
{
    $output = strtolower($output);
    if (str_contains($output, 'permission denied')) return 'the process could not traverse or relabel the target';
    if (str_contains($output, 'operation not permitted')) return 'the process lacks permission to change the SELinux label';
    if (str_contains($output, 'read-only file system')) return 'the filesystem is mounted read-only';
    if (str_contains($output, 'invalid context') || str_contains($output, 'unknown type') || preg_match('/type .+ is not defined/', $output)) return 'the active SELinux policy does not define the required label';
    if (str_contains($output, 'no such file or directory')) return 'the target or required SELinux policy data is unavailable';
    if (str_contains($output, 'invalid option') || str_contains($output, 'unrecognized option') || str_contains($output, 'usage:')) return 'the restorecon command interface rejected its arguments';
    return 'restorecon exited with status ' . $status . '; inspect SELinux audit logs for the denial details';
}

function database_backup_setup_selinux_mode(): string
{
    $binary = database_backup_setup_find_host_binary('getenforce');
    if ($binary === null) {
        $enforceFile = '/sys/fs/selinux/enforce';
        if (!is_readable($enforceFile)) return 'not-detected';
        $value = trim((string)@file_get_contents($enforceFile));
        if ($value === '1') return 'Enforcing';
        if ($value === '0') return 'Permissive';
        throw new RuntimeException('The SELinux enforcement mode could not be determined safely.');
    }
    $result = database_backup_setup_run([$binary]);
    if ($result['status'] !== 0) throw new RuntimeException('The SELinux enforcement mode could not be determined safely.');
    $mode = trim($result['stdout']);
    if (!in_array($mode, ['Enforcing', 'Permissive', 'Disabled'], true)) throw new RuntimeException('The SELinux enforcement mode returned an unsupported value.');
    return $mode;
}

function database_backup_setup_selinux_expression(string $directory): string
{
    if (!database_backup_setup_safe_target($directory)) throw new RuntimeException('The SELinux backup label target is not a safe dedicated directory.');
    return preg_quote($directory, '#') . '(/.*)?';
}

function database_backup_setup_selinux_mapping_type(string $listing, string $expression): ?string
{
    foreach (preg_split('/\r?\n/', $listing) ?: [] as $line) {
        $columns = preg_split('/\s+/', trim($line)) ?: [];
        if (($columns[0] ?? '') !== $expression) continue;
        $context = (string)end($columns);
        if (preg_match('/\A[^:]+:object_r:([a-zA-Z0-9_]+):/', $context, $match)) return $match[1];
        return null;
    }
    return null;
}

function database_backup_setup_selinux_tools_available(): bool
{
    return database_backup_setup_find_host_binary('semanage') !== null
        && database_backup_setup_find_host_binary('restorecon') !== null
        && database_backup_setup_find_host_binary('matchpathcon') !== null
        && database_backup_setup_find_host_binary('ls') !== null;
}

function database_backup_setup_selinux_context_type(string $output): ?string
{
    return preg_match('/(?:^|\s)([^:\s]+):[^:\s]+:([a-zA-Z0-9_]+):[^:\s]+(?:\s|$)/', trim($output), $match) ? $match[2] : null;
}

function database_backup_setup_selinux_check_passes(?string $actualType, ?string $expectedType, bool $subtreeReadable, string $pendingRelabelOutput): bool
{
    $requiredType = 'httpd_sys_rw_content_t';
    return $actualType === $requiredType && $expectedType === $requiredType && $subtreeReadable && trim($pendingRelabelOutput) === '';
}

/** Read actual and policy-expected labels without changing any path. */
function database_backup_setup_selinux_check(string $directory): array
{
    $type = 'httpd_sys_rw_content_t';
    $ls = database_backup_setup_find_host_binary('ls');
    $matchpathcon = database_backup_setup_find_host_binary('matchpathcon');
    $restorecon = database_backup_setup_find_host_binary('restorecon');
    if ($ls === null || $matchpathcon === null || $restorecon === null) {
        return ['ready' => false, 'expected_type' => $type, 'actual_type' => null, 'details_readable' => false];
    }
    $actual = database_backup_setup_run([$ls, '-Zd', '--', $directory]);
    $expected = database_backup_setup_run([$matchpathcon, '-n', $directory]);
    $dryRun = database_backup_setup_run([$restorecon, '-nRv', $directory]);
    $actualType = $actual['status'] === 0 ? database_backup_setup_selinux_context_type($actual['stdout']) : null;
    $expectedType = $expected['status'] === 0 ? database_backup_setup_selinux_context_type($expected['stdout']) : null;
    $detailsReadable = $dryRun['status'] === 0;
    return [
        'ready' => database_backup_setup_selinux_check_passes($actualType, $expectedType, $detailsReadable, $dryRun['stdout']),
        'expected_type' => $expectedType,
        'actual_type' => $actualType,
        'details_readable' => $detailsReadable,
    ];
}

/** Apply a persistent, exact-path SELinux label for private web/CLI backup files. */
function database_backup_setup_selinux_label(string $directory): string
{
    $mode = database_backup_setup_selinux_mode();
    if (in_array($mode, ['Disabled', 'not-detected'], true)) return $mode;
    if (!database_backup_setup_selinux_tools_available()) throw new RuntimeException('SELinux is enabled, but semanage, restorecon, and ls are required to label only the dedicated backup directory. Install no packages automatically; provide these existing host tools, then rerun setup.');

    $expression = database_backup_setup_selinux_expression($directory);
    $type = 'httpd_sys_rw_content_t';
    $semanage = database_backup_setup_find_host_binary('semanage');
    $restorecon = database_backup_setup_find_host_binary('restorecon');
    $ls = database_backup_setup_find_host_binary('ls');
    if ($semanage === null || $restorecon === null || $ls === null) throw new RuntimeException('Required SELinux backup labeling tools are unavailable.');

    $add = database_backup_setup_run([$semanage, 'fcontext', '--add', '--type', $type, $expression]);
    if ($add['status'] !== 0) {
        $listing = database_backup_setup_run([$semanage, 'fcontext', '--list', '--locallist']);
        if ($listing['status'] !== 0) throw new RuntimeException('The local SELinux file-context rules could not be read safely.');
        $existingType = database_backup_setup_selinux_mapping_type($listing['stdout'], $expression);
        if ($existingType === null) throw new RuntimeException('The dedicated SELinux backup file-context rule could not be added; inspect SELinux policy state before retrying.');
        if ($existingType !== $type) {
            $modify = database_backup_setup_run([$semanage, 'fcontext', '--modify', '--type', $type, $expression]);
            if ($modify['status'] !== 0) throw new RuntimeException('The exact dedicated SELinux backup file-context rule could not be updated.');
        }
    }

    $listing = database_backup_setup_run([$semanage, 'fcontext', '--list', '--locallist']);
    if ($listing['status'] !== 0 || database_backup_setup_selinux_mapping_type($listing['stdout'], $expression) !== $type) {
        throw new RuntimeException('The persistent SELinux backup file-context rule did not pass verification.');
    }
    $restore = database_backup_setup_run([$restorecon, '-R', $directory]);
    if ($restore['status'] !== 0) {
        $failure = database_backup_setup_restorecon_failure((int)$restore['status'], $restore['stdout'] . "\n" . $restore['stderr']);
        throw new RuntimeException('SELinux could not apply the dedicated backup-directory label because ' . $failure . '. The persistent exact-path rule may already be installed; backup contents and Unix permissions are unchanged. Resolve the reported cause, then rerun setup.');
    }
    $actual = database_backup_setup_run([$ls, '-Zd', '--', $directory]);
    if ($actual['status'] !== 0 || !preg_match('/(?:^|:)object_r:' . preg_quote($type, '/') . ':/', $actual['stdout'])) {
        throw new RuntimeException('The dedicated backup directory did not receive the expected SELinux label.');
    }
    $verification = database_backup_setup_selinux_check($directory);
    if (!$verification['ready']) throw new RuntimeException('One or more backup-storage entries still have an incorrect or unreadable SELinux label after restorecon.');
    return $mode;
}

function database_backup_setup_usage(): string
{
    return "Usage: php scripts/database_backup_setup.php [--check|--apply] [--service-user=NAME]\n" .
        "  --check  Read configuration and show required setup without changing files (default).\n" .
        "  --apply  Provision a dedicated private directory and same-user cron worker; requires root.\n";
}

function database_backup_setup_main(array $argv): int
{
    $newDirectoryCreated = false;
    $logCreated = false;
    $envCommitted = false;
    try {
        $options = database_backup_setup_parse_options($argv);
        if ($options['help']) { fwrite(STDOUT, database_backup_setup_usage()); return 0; }
        $serviceUser = $options['service_user'] ?? database_backup_setup_detect_service_user();
        if ($serviceUser === null) throw new RuntimeException('PHP-FPM service user could not be detected; pass --service-user=NAME.');
        if (!database_backup_setup_service_user_allowed($serviceUser)) throw new RuntimeException('The PHP-FPM service account cannot be root for database backup scheduling.');
        $serviceAccount = function_exists('posix_getpwnam') ? posix_getpwnam($serviceUser) : false;
        if (!is_array($serviceAccount) || !isset($serviceAccount['uid'], $serviceAccount['gid'])) throw new RuntimeException('The PHP-FPM service user does not exist on this host.');
        if (!database_backup_setup_service_account_allowed($serviceAccount)) throw new RuntimeException('The PHP-FPM service account must have a non-root UID.');
        if ($options['apply'] && (!function_exists('posix_geteuid') || posix_geteuid() !== 0)) {
            throw new RuntimeException('Apply requires root to create the service-owned directory and install /etc/cron.d. Run: sudo php scripts/database_backup_setup.php --apply --service-user=' . $serviceUser);
        }

        $envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
        if (is_link($envPath) || !is_file($envPath) || !is_readable($envPath)) throw new RuntimeException('The project .env file must be a readable regular file; setup will not create or replace it.');
        $envContents = file_get_contents($envPath);
        if (!is_string($envContents)) throw new RuntimeException('The project .env file could not be read.');
        $configuredDirectory = database_backup_setup_env_value($envContents, 'BACKUP_DIR');
        if (!is_string($configuredDirectory) || $configuredDirectory === '') throw new RuntimeException('BACKUP_DIR must be set in the project .env file before setup.');
        $overrides = database_backup_setup_preloaded_overrides();
        if ($overrides !== []) throw new RuntimeException('A host-level BACKUP_DIR override exists in ' . implode(', ', $overrides) . '; update that authoritative source instead of changing only .env.');
        $envAcl = database_backup_setup_capture_acl($envPath);
        if (!database_backup_setup_service_can_read_env($envPath, $serviceAccount, $envAcl)) throw new RuntimeException('The PHP-FPM service account cannot read .env. Grant only that account the required read access before setup.');
        if ($options['apply'] && database_backup_find_binary('SETFACL_BIN', ['setfacl']) === null) throw new RuntimeException('setfacl is required to preserve the .env file access rules.');

        if ($configuredDirectory[0] !== '/' || str_contains($configuredDirectory, '\\') || str_contains($configuredDirectory, "\0") || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $configuredDirectory)) {
            throw new RuntimeException('BACKUP_DIR must be a safe absolute path before setup.');
        }
        $targetDirectory = rtrim(dirname(rtrim($configuredDirectory, '/')), '/') . '/sevilla360-database-backups';
        if (!database_backup_setup_safe_target($targetDirectory)) throw new RuntimeException('The dedicated backup path must be outside the project and document roots, with no symlinked path components.');
        $directoryState = database_backup_setup_directory_state($targetDirectory, (int)$serviceAccount['uid'], $configuredDirectory);
        if (!in_array($directoryState, ['absent', 'ready'], true)) throw new RuntimeException('The dedicated backup path already exists with unsafe ownership or permissions; setup will not change it.');
        $selinuxMode = database_backup_setup_selinux_mode();
        $selinuxToolsReady = !in_array($selinuxMode, ['Enforcing', 'Permissive'], true) || database_backup_setup_selinux_tools_available();
        $selinuxCheck = in_array($selinuxMode, ['Enforcing', 'Permissive'], true)
            ? ($selinuxToolsReady ? database_backup_setup_selinux_check($targetDirectory) : ['ready' => false, 'expected_type' => 'httpd_sys_rw_content_t', 'actual_type' => null, 'details_readable' => false])
            : ['ready' => true, 'expected_type' => null, 'actual_type' => null, 'details_readable' => true];

        $cronDirectory = dirname(DATABASE_BACKUP_SETUP_CRON_PATH);
        if (!is_dir($cronDirectory) || is_link($cronDirectory)) throw new RuntimeException('This host has no safe /etc/cron.d directory; configure its hosting scheduler to run the worker as the PHP-FPM account.');
        if (!database_backup_setup_systemd_active('crond')) throw new RuntimeException('The crond service is not active; enable the host scheduler before installing a worker entry.');
        $phpBinary = realpath(PHP_BINARY);
        $workerPath = realpath(__DIR__ . '/database_backup_worker.php');
        if (!is_string($phpBinary) || !is_executable($phpBinary) || !is_string($workerPath) || !is_file($workerPath)) throw new RuntimeException('The PHP executable or CLI worker script could not be resolved safely.');
        $logPath = $targetDirectory . '/worker.log';
        $expectedCron = database_backup_setup_cron_contents($serviceUser, $phpBinary, $workerPath, $logPath);
        $cronExists = file_exists(DATABASE_BACKUP_SETUP_CRON_PATH) || is_link(DATABASE_BACKUP_SETUP_CRON_PATH);
        if (is_link(DATABASE_BACKUP_SETUP_CRON_PATH) || ($cronExists && (!database_backup_setup_cron_file_is_safe(DATABASE_BACKUP_SETUP_CRON_PATH) || file_get_contents(DATABASE_BACKUP_SETUP_CRON_PATH) !== $expectedCron))) {
            throw new RuntimeException('The existing database backup cron entry differs from the expected setup; setup will not overwrite it.');
        }

        $newEnvContents = database_backup_setup_update_env_text($envContents, 'BACKUP_DIR', $targetDirectory);
        $envStat = stat($envPath);
        if (!is_array($envStat)) throw new RuntimeException('The project .env file metadata could not be read.');
        $currentCliUser = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown') : 'unknown';
        $capabilities = database_backup_capabilities();

        fwrite(STDOUT, "Configuration source: project .env via config/env.php; no host-level BACKUP_DIR override was found.\n");
        fwrite(STDOUT, 'PHP-FPM/worker identity: ' . $serviceUser . '; current CLI identity: ' . $currentCliUser . ".\n");
        fwrite(STDOUT, 'Current BACKUP_DIR: ' . $configuredDirectory . "\nDedicated BACKUP_DIR: " . $targetDirectory . ' (' . $directoryState . ").\n");
        fwrite(STDOUT, 'Worker schedule: ' . ($cronExists ? 'already installed' : 'not installed') . "; cron service: active.\n");
        if (in_array($selinuxMode, ['Enforcing', 'Permissive'], true)) {
            $actualType = $selinuxCheck['actual_type'] ?? 'unreadable';
            $expectedType = $selinuxCheck['expected_type'] ?? 'unavailable';
            fwrite(STDOUT, 'SELinux mode: ' . $selinuxMode . '; backup directory label: actual ' . $actualType . ', policy expects ' . $expectedType . ', required httpd_sys_rw_content_t; subtree label check: ' . ($selinuxCheck['ready'] ? 'ready' : 'requires --apply') . ".\n");
        } else {
            fwrite(STDOUT, 'SELinux mode: ' . $selinuxMode . '; backup storage label: not required.\n');
        }
        fwrite(STDOUT, 'Current CLI backup readiness: ' . ($capabilities['enabled'] ? 'ready' : implode(' ', $capabilities['messages'])) . "\n");
        if ($currentCliUser !== $serviceUser) {
            $setupPath = realpath(__FILE__) ?: __FILE__;
            fwrite(STDOUT, 'Check backup readiness as the PHP-FPM/worker user with: sudo -u ' . escapeshellarg($serviceUser) . ' ' . escapeshellarg($phpBinary) . ' ' . escapeshellarg($setupPath) . ' --check --service-user=' . escapeshellarg($serviceUser) . "\n");
        }
        if (!empty($capabilities['restore_message'])) fwrite(STDOUT, 'Restore-only readiness: ' . $capabilities['restore_message'] . "\n");
        if (!$options['apply']) {
            fwrite(STDOUT, 'Check mode made no changes.' . "\n");
            if (!$capabilities['enabled'] || $directoryState !== 'ready' || !$cronExists || !$selinuxToolsReady || !$selinuxCheck['ready']) {
                if (!$selinuxToolsReady) fwrite(STDERR, "SELinux is enabled; --apply requires the existing semanage, restorecon, matchpathcon, and ls utilities.\n");
                fwrite(STDOUT, 'Apply with: sudo php scripts/database_backup_setup.php --apply --service-user=' . $serviceUser . "\n");
                return 1;
            }
            return 0;
        }

        if (!is_writable($cronDirectory)) throw new RuntimeException('Apply requires permission to install the worker entry in /etc/cron.d.');

        if ($directoryState === 'absent') $newDirectoryCreated = database_backup_setup_create_private_directory($targetDirectory, $serviceAccount);
        $logCreated = database_backup_setup_ensure_private_log($logPath, $serviceAccount);
        if (!$selinuxToolsReady) throw new RuntimeException('SELinux is enabled, but semanage, restorecon, and ls are required to label only the dedicated backup directory.');
        $appliedSelinuxMode = database_backup_setup_selinux_label($targetDirectory);
        $_ENV['BACKUP_DIR'] = $targetDirectory;
        $_SERVER['BACKUP_DIR'] = $targetDirectory;
        putenv('BACKUP_DIR=' . $targetDirectory);
        $ready = database_backup_capabilities();
        if (!$ready['enabled']) throw new RuntimeException('The dedicated path is prepared, but backup capability checks still fail: ' . implode(' ', $ready['messages']));

        $envChanged = $newEnvContents !== $envContents;
        if ($envChanged) {
            database_backup_setup_write_atomic($envPath, $newEnvContents, (int)$envStat['mode'] & 07777, (int)$envStat['uid'], (int)$envStat['gid'], $envAcl);
            $envCommitted = true;
        }
        try {
            if (!$cronExists) database_backup_setup_write_atomic(DATABASE_BACKUP_SETUP_CRON_PATH, $expectedCron, 0644, 0, 0);
        } catch (Throwable $error) {
            if ($envCommitted) {
                try {
                    database_backup_setup_write_atomic($envPath, $envContents, (int)$envStat['mode'] & 07777, (int)$envStat['uid'], (int)$envStat['gid'], $envAcl);
                    $envCommitted = false;
                } catch (Throwable $rollbackError) {
                    throw new RuntimeException('Cron installation failed and .env rollback was unsuccessful. The dedicated private directory was retained; inspect configuration and rerun setup.', 0, $rollbackError);
                }
            }
            throw $error;
        }

        fwrite(STDOUT, "Provisioned the dedicated private backup directory and same-user CLI worker schedule.\n");
        if (in_array($appliedSelinuxMode, ['Enforcing', 'Permissive'], true)) fwrite(STDOUT, "Applied the persistent httpd_sys_rw_content_t label only to the dedicated backup directory and its contents; owner-only Unix permissions remain unchanged.\n");
        fwrite(STDOUT, "The next worker run is within five minutes. Verify status from the admin dashboard.\n");
        if (!empty($ready['restore_message'])) fwrite(STDOUT, 'Restore remains disabled until a dedicated staging database is configured: ' . $ready['restore_message'] . "\n");
        return 0;
    } catch (Throwable $error) {
        if (!$envCommitted) {
            if ($logCreated && isset($logPath) && is_file($logPath)) @unlink($logPath);
            if ($newDirectoryCreated && isset($targetDirectory) && is_dir($targetDirectory)) {
                $entries = @scandir($targetDirectory);
                if (is_array($entries) && count($entries) === 2) @rmdir($targetDirectory);
            }
        }
        fwrite(STDERR, 'Database backup setup failed: ' . $error->getMessage() . "\n");
        return 1;
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(database_backup_setup_main($argv));
}
