<?php
declare(strict_types=1);

const DATABASE_BACKUP_MAGIC = "S360DBA1";
const DATABASE_BACKUP_HEADER_MAX_BYTES = 4096;
const DATABASE_BACKUP_DEFAULT_MAX_BYTES = 52428800;
const DATABASE_BACKUP_DEFAULT_MAX_SQL_BYTES = 536870912;
const DATABASE_BACKUP_RETENTION_DAYS = 7;

/** Read an environment value consistently in web, CLI, and test contexts. */
function database_backup_env(string $key, string $default = ''): string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return is_string($value) ? $value : $default;
}

function database_backup_max_bytes(): int
{
    $value = database_backup_env('BACKUP_MAX_BYTES', (string)DATABASE_BACKUP_DEFAULT_MAX_BYTES);
    return ctype_digit($value) ? max(1024, min(536870912, (int)$value)) : DATABASE_BACKUP_DEFAULT_MAX_BYTES;
}

function database_backup_max_sql_bytes(): int
{
    $value = database_backup_env('BACKUP_MAX_UNCOMPRESSED_BYTES', (string)DATABASE_BACKUP_DEFAULT_MAX_SQL_BYTES);
    return ctype_digit($value) ? max(1024, min(2147483648, (int)$value)) : DATABASE_BACKUP_DEFAULT_MAX_SQL_BYTES;
}

function database_backup_app_key(): ?string
{
    $key = database_backup_env('APP_KEY');
    return strlen($key) >= 32 ? hash_hmac('sha256', 'sevilla360/database-backup/v1', $key, true) : null;
}

/**
 * Resolve private database backup storage. The final directory must be outside
 * the application/document roots and private to the service account.
 */
function database_backup_directory(bool $create = false): string
{
    $configured = database_backup_env('BACKUP_DIR');
    if ($configured === '' || $configured[0] !== '/' || str_contains($configured, "\0") || str_contains($configured, '\\') || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $configured)) {
        throw new RuntimeException('Private database backup storage is not configured with a safe absolute path.');
    }
    $parts = explode('/', trim($configured, '/'));
    if (count($parts) < 3 || in_array('', $parts, true)) throw new RuntimeException('Private database backup storage path is too broad.');
    $candidate = '/' . implode('/', $parts);
    $broadPaths = ['/', '/tmp', '/var', '/var/www', '/var/www/html', '/home', '/root', '/usr', '/opt', '/etc', '/mnt', '/media', '/run', '/dev', '/proc', '/sys'];
    if (in_array($candidate, $broadPaths, true)) throw new RuntimeException('Private database backup storage path is too broad.');

    $projectRoot = realpath(dirname(__DIR__));
    if ($projectRoot === false) throw new RuntimeException('Application root could not be resolved.');
    $documentRoots = [$projectRoot];
    $documentRoot = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($documentRoot !== '' && $documentRoot[0] === '/') {
        $realDocumentRoot = realpath($documentRoot);
        if ($realDocumentRoot !== false && $realDocumentRoot !== '/') $documentRoots[] = rtrim($realDocumentRoot, '/');
    }

    $probe = $candidate;
    $missing = [];
    while (!file_exists($probe) && !is_link($probe)) {
        $parent = dirname($probe);
        if ($parent === $probe) break;
        $missing[] = basename($probe);
        $probe = $parent;
    }
    $realParent = realpath($probe);
    if ($realParent === false || !is_dir($realParent)) throw new RuntimeException('Private database backup storage parent is unavailable.');
    $resolvedCandidate = rtrim($realParent, '/') . ($missing ? '/' . implode('/', array_reverse($missing)) : '');
    $resolvedCandidate = $resolvedCandidate === '' ? '/' : $resolvedCandidate;
    foreach ([$candidate, $resolvedCandidate] as $path) {
        foreach ($documentRoots as $root) {
            $root = rtrim($root, '/');
            if ($root !== '' && ($path === $root || str_starts_with($path, $root . '/'))) {
                throw new RuntimeException('Database backups must be stored outside the application and document roots.');
            }
        }
    }

    if (file_exists($candidate) && !is_dir($candidate)) throw new RuntimeException('Private database backup path is not a directory.');
    if (is_link($candidate)) throw new RuntimeException('Private database backup storage cannot be a symlink.');
    if (!is_dir($candidate)) {
        if (!$create || !mkdir($candidate, 0700, true)) {
            if (!$create || !is_dir($candidate)) throw new RuntimeException('Private database backup storage is unavailable.');
        }
        @chmod($candidate, 0700);
    }
    $realDirectory = realpath($candidate);
    if ($realDirectory === false || !is_dir($realDirectory) || $realDirectory !== $resolvedCandidate || is_link($candidate) || !is_readable($realDirectory)) {
        throw new RuntimeException('Private database backup storage is unavailable or unsafe.');
    }
    $permissions = fileperms($realDirectory);
    if ($permissions === false || ($permissions & 0077) !== 0) throw new RuntimeException('Private database backup storage must not be accessible to group or other users.');
    if ($create && !is_writable($realDirectory)) throw new RuntimeException('Private database backup storage is not writable.');
    return $realDirectory;
}

function database_backup_subdirectory(string $name, bool $create = false): string
{
    if (!in_array($name, ['jobs'], true)) throw new RuntimeException('Invalid private backup subdirectory.');
    $root = database_backup_directory($create);
    $path = $root . DIRECTORY_SEPARATOR . $name;
    if (is_link($path) || (file_exists($path) && !is_dir($path))) throw new RuntimeException('Private backup subdirectory is invalid.');
    if (!is_dir($path)) {
        if (!$create || !mkdir($path, 0700)) throw new RuntimeException('Private backup subdirectory is unavailable.');
        @chmod($path, 0700);
    }
    $real = realpath($path);
    $permissions = $real === false ? false : fileperms($real);
    if ($real !== $path || !is_dir($real) || $permissions === false || ($permissions & 0077) !== 0) throw new RuntimeException('Private backup subdirectory is unsafe.');
    return $real;
}

function database_backup_disabled_functions(): array
{
    $disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
    return array_values(array_filter(['proc_open', 'proc_close'], static fn(string $name): bool => !function_exists($name) || in_array($name, $disabled, true)));
}

/** Find a configured executable without invoking a shell. */
function database_backup_find_binary(string $envName, array $candidates): ?string
{
    $configured = database_backup_env($envName);
    $names = $configured !== '' ? [$configured] : $candidates;
    foreach ($names as $name) {
        if (str_contains($name, '/') || str_contains($name, '\\')) {
            $path = realpath($name);
            if ($path !== false && is_file($path) && is_executable($path)) return $path;
            continue;
        }
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $directory) {
            if ($directory === '' || $directory[0] !== '/') continue;
            $path = realpath(rtrim($directory, '/') . '/' . $name);
            if ($path !== false && is_file($path) && is_executable($path)) return $path;
        }
    }
    return null;
}

function database_backup_database_name(string $value): bool
{
    return preg_match('/\A[A-Za-z0-9_$-]{1,64}\z/D', $value) === 1;
}

function database_backup_connection_config(string $prefix = 'DB_'): array
{
    $host = database_backup_env($prefix . 'HOST', 'localhost');
    $user = database_backup_env($prefix . 'USER');
    $password = database_backup_env($prefix . 'PASS');
    $name = database_backup_env($prefix . 'NAME');
    $port = database_backup_env($prefix . 'PORT');
    foreach ([$host, $user, $password, $name] as $value) {
        if (str_contains($value, "\0") || str_contains($value, "\r") || str_contains($value, "\n")) throw new RuntimeException('Database backup connection settings contain unsupported characters.');
    }
    if ($host === '' || $user === '' || !database_backup_database_name($name)) throw new RuntimeException('Database backup connection settings are incomplete or invalid.');
    if ($port !== '' && (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535)) throw new RuntimeException('Database backup port is invalid.');
    return ['host' => $host, 'user' => $user, 'password' => $password, 'name' => $name, 'port' => $port === '' ? null : (int)$port];
}

function database_backup_staging_config(): array
{
    if (database_backup_env('DB_STAGING_HOST') === '' || database_backup_env('DB_STAGING_USER') === '' || database_backup_env('DB_STAGING_NAME') === '') {
        throw new RuntimeException('Restore disabled until DB_STAGING_HOST, DB_STAGING_USER, and DB_STAGING_NAME identify a dedicated pre-provisioned database.');
    }
    $config = database_backup_connection_config('DB_STAGING_');
    $production = database_backup_connection_config('DB_');
    if (strcasecmp($config['name'], $production['name']) === 0) {
        throw new RuntimeException('The staging database must be separate from the production database.');
    }
    return $config;
}

/** Fail closed when directory entries are visible but PHP cannot inspect them. */
function database_backup_storage_visibility_issue(string $directory, ?callable $inspect = null): ?string
{
    $entries = @scandir($directory);
    if (!is_array($entries)) return 'Private database backup storage cannot be inspected by PHP.';
    $inspect ??= static fn(string $path) => @lstat($path);
    foreach ($entries as $name) {
        if (!preg_match('/\A(?:db-[a-f0-9]{32}\.s360db|scheduled-\d{8}\.done|maintenance\.json|worker\.lock|operation\.lock|jobs)\z/D', $name)) continue;
        clearstatcache(true, $directory . DIRECTORY_SEPARATOR . $name);
        if ($inspect($directory . DIRECTORY_SEPARATOR . $name) === false) {
            return 'A private backup entry is visible but cannot be inspected by PHP. Rerun the database backup storage setup with administrator privileges to repair its host security label.';
        }
    }
    return null;
}

/** Feature readiness is reported rather than taking the dashboard down. */
function database_backup_capabilities(): array
{
    $disabled = database_backup_disabled_functions();
    $dumpBinary = $disabled === [] ? database_backup_find_binary('MYSQLDUMP_BIN', ['mysqldump', 'mariadb-dump']) : null;
    $mysqlBinary = $disabled === [] ? database_backup_find_binary('MYSQL_BIN', ['mysql', 'mariadb']) : null;
    $reasons = [];
    if ($disabled !== []) $reasons[] = 'PHP process execution is disabled (' . implode(', ', $disabled) . ').';
    if (database_backup_app_key() === null) $reasons[] = 'APP_KEY must contain at least 32 characters.';
    try {
        $backupDirectory = database_backup_directory(false);
        $visibilityIssue = database_backup_storage_visibility_issue($backupDirectory);
        if ($visibilityIssue !== null) $reasons[] = $visibilityIssue;
    } catch (Throwable $error) {
        $reasons[] = $error->getMessage();
    }
    if ($dumpBinary === null) $reasons[] = 'mysqldump or mariadb-dump was not found or is not executable.';
    try {
        database_backup_connection_config('DB_');
    } catch (Throwable $error) {
        $reasons[] = $error->getMessage();
    }
    $stagingReason = null;
    try {
        database_backup_staging_config();
    } catch (Throwable $error) {
        $stagingReason = $error->getMessage();
    }
    $restoreReasons = [];
    if ($mysqlBinary === null) $restoreReasons[] = 'mysql or mariadb was not found or is not executable.';
    if ($stagingReason !== null) $restoreReasons[] = $stagingReason;
    return [
        'enabled' => $reasons === [],
        'restore_enabled' => $reasons === [] && $restoreReasons === [],
        'messages' => array_values(array_unique($reasons)),
        'restore_message' => $restoreReasons === [] ? null : implode(' ', $restoreReasons),
        'mysqldump' => $dumpBinary !== null,
        'mysql' => $mysqlBinary !== null,
        'process_execution' => $disabled === [],
    ];
}

function database_backup_archive_path(string $id, bool $mustExist = true): string
{
    if (!preg_match('/\Adb-[a-f0-9]{32}\.s360db\z/D', $id)) throw new RuntimeException('Invalid database backup ID.');
    $directory = database_backup_directory(false);
    $path = $directory . DIRECTORY_SEPARATOR . $id;
    if (!$mustExist) {
        if (is_link($path) || (file_exists($path) && (!is_file($path) || dirname((string)realpath($path)) !== $directory))) throw new RuntimeException('Database backup path is invalid.');
        return $path;
    }
    $real = realpath($path);
    if ($real === false || !is_file($real) || dirname($real) !== $directory || is_link($path)) throw new RuntimeException('Database backup file is unavailable.');
    return $real;
}

function database_backup_canonical_metadata(array $metadata): string
{
    $unsigned = [
        'format' => (int)$metadata['format'],
        'database' => (string)$metadata['database'],
        'created_at' => (string)$metadata['created_at'],
        'kind' => (string)$metadata['kind'],
        'compression' => (string)$metadata['compression'],
        'payload_bytes' => (int)$metadata['payload_bytes'],
        'payload_sha256' => (string)$metadata['payload_sha256'],
        'uncompressed_bytes' => (int)$metadata['uncompressed_bytes'],
    ];
    return json_encode($unsigned, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function database_backup_write_all($stream, string $data): void
{
    $length = strlen($data);
    $offset = 0;
    while ($offset < $length) {
        $written = fwrite($stream, substr($data, $offset));
        if ($written === false || $written === 0) throw new RuntimeException('Unable to write a private database backup file.');
        $offset += $written;
    }
}

/** Parse and authenticate only the bounded metadata header; this does not read payload bytes. */
function database_backup_archive_header_from_stream($stream, int $fileSize): array
{
    $maxBytes = database_backup_max_bytes();
    if ($fileSize === false || $fileSize < 12 || $fileSize > $maxBytes) throw new RuntimeException('The database archive size is invalid or exceeds BACKUP_MAX_BYTES.');
    $prefix = fread($stream, 12);
    if (!is_string($prefix) || strlen($prefix) !== 12 || substr($prefix, 0, 8) !== DATABASE_BACKUP_MAGIC) throw new RuntimeException('This is not a supported Sevilla360 database archive.');
    $headerLength = unpack('Nlength', substr($prefix, 8, 4))['length'] ?? 0;
    if ($headerLength < 2 || $headerLength > DATABASE_BACKUP_HEADER_MAX_BYTES) throw new RuntimeException('The database archive header is invalid.');
    $headerBytes = fread($stream, $headerLength);
    if (!is_string($headerBytes) || strlen($headerBytes) !== $headerLength) throw new RuntimeException('The database archive header is incomplete.');
    try {
        $header = json_decode($headerBytes, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('The database archive header is invalid.');
    }
    $expectedKeys = ['format', 'database', 'created_at', 'kind', 'compression', 'payload_bytes', 'payload_sha256', 'uncompressed_bytes', 'signature'];
    if (!is_array($header) || array_keys($header) !== $expectedKeys) throw new RuntimeException('The database archive metadata is incomplete or unsupported.');
    if (($header['format'] ?? null) !== 1 || ($header['compression'] ?? null) !== 'gzip' || !in_array($header['kind'] ?? null, ['scheduled', 'manual', 'pre_restore', 'uploaded'], true)) throw new RuntimeException('The database archive format is incompatible.');
    $config = database_backup_connection_config('DB_');
    if (!is_string($header['database'] ?? null) || !hash_equals($config['name'], $header['database'])) throw new RuntimeException('This archive belongs to a different database.');
    if (!is_string($header['created_at'] ?? null) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $header['created_at'])) throw new RuntimeException('The database archive timestamp is invalid.');
    if (!is_int($header['payload_bytes'] ?? null) || $header['payload_bytes'] < 20 || $header['payload_bytes'] > $maxBytes) throw new RuntimeException('The database archive payload length is invalid.');
    if (!is_int($header['uncompressed_bytes'] ?? null) || $header['uncompressed_bytes'] < 1 || $header['uncompressed_bytes'] > database_backup_max_sql_bytes()) throw new RuntimeException('The database archive SQL payload exceeds the configured safety limit.');
    if (!is_string($header['payload_sha256'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $header['payload_sha256'])) throw new RuntimeException('The database archive checksum is invalid.');
    if (!is_string($header['signature'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $header['signature'])) throw new RuntimeException('The database archive signature is invalid.');
    $key = database_backup_app_key();
    if ($key === null) throw new RuntimeException('APP_KEY must contain at least 32 characters before database archives can be used.');
    $unsigned = database_backup_canonical_metadata($header);
    $expectedSignature = hash_hmac('sha256', $unsigned, $key);
    if (!hash_equals($expectedSignature, $header['signature'])) throw new RuntimeException('Database archive signature verification failed.');
    if ($fileSize !== 12 + $headerLength + $header['payload_bytes']) throw new RuntimeException('The database archive payload length does not match its file.');
    return $header;
}

/** Read authenticated metadata without hashing or decompressing the payload. */
function database_backup_read_archive_header(string $path): array
{
    if (is_link($path) || !is_file($path) || !is_readable($path)) throw new RuntimeException('The selected database archive is unavailable.');
    $realPath = realpath($path);
    if ($realPath === false) throw new RuntimeException('The selected database archive is unavailable.');
    $fileSize = filesize($realPath);
    if ($fileSize === false) throw new RuntimeException('The database archive size is invalid.');
    $stream = fopen($realPath, 'rb');
    if ($stream === false) throw new RuntimeException('The database archive could not be opened.');
    try {
        return database_backup_archive_header_from_stream($stream, $fileSize);
    } finally {
        fclose($stream);
    }
}

/** Parse, authenticate, hash, and fully decompress an archive without executing its SQL. */
function database_backup_validate_archive(string $path): array
{
    if (is_link($path) || !is_file($path) || !is_readable($path)) throw new RuntimeException('The selected database archive is unavailable.');
    $realPath = realpath($path);
    if ($realPath === false) throw new RuntimeException('The selected database archive is unavailable.');
    $fileSize = filesize($realPath);
    if ($fileSize === false) throw new RuntimeException('The database archive size is invalid.');
    $stream = fopen($realPath, 'rb');
    if ($stream === false) throw new RuntimeException('The database archive could not be opened.');
    $payloadTemp = null;
    try {
        $header = database_backup_archive_header_from_stream($stream, $fileSize);

        $directory = database_backup_directory(true);
        $payloadTemp = tempnam($directory, '.verify-');
        if ($payloadTemp === false) throw new RuntimeException('Unable to prepare a private archive verification file.');
        @chmod($payloadTemp, 0600);
        $payloadOut = fopen($payloadTemp, 'wb');
        if ($payloadOut === false) throw new RuntimeException('Unable to prepare a private archive verification file.');
        $hash = hash_init('sha256');
        $remaining = $header['payload_bytes'];
        while ($remaining > 0) {
            $chunk = fread($stream, min(65536, $remaining));
            if (!is_string($chunk) || $chunk === '') throw new RuntimeException('The database archive payload is truncated.');
            $remaining -= strlen($chunk);
            hash_update($hash, $chunk);
            database_backup_write_all($payloadOut, $chunk);
        }
        fclose($payloadOut);
        if (!hash_equals($header['payload_sha256'], hash_final($hash))) throw new RuntimeException('Database archive payload checksum verification failed.');

        $gzip = gzopen($payloadTemp, 'rb');
        if ($gzip === false) throw new RuntimeException('The database archive compression stream is invalid.');
        $uncompressed = 0;
        try {
            while (!gzeof($gzip)) {
                $chunk = gzread($gzip, 65536);
                if ($chunk === false) throw new RuntimeException('The database archive compression stream is damaged.');
                if ($chunk === '' && !gzeof($gzip)) throw new RuntimeException('The database archive compression stream is damaged.');
                $uncompressed += strlen($chunk);
                if ($uncompressed > database_backup_max_sql_bytes()) throw new RuntimeException('The database archive expands beyond the configured safety limit.');
            }
        } finally {
            gzclose($gzip);
        }
        if ($uncompressed !== $header['uncompressed_bytes']) throw new RuntimeException('The database archive SQL length does not match its metadata.');
        $header['_payload_temp'] = $payloadTemp;
        return $header;
    } catch (Throwable $error) {
        if (is_string($payloadTemp) && is_file($payloadTemp)) @unlink($payloadTemp);
        throw $error;
    } finally {
        fclose($stream);
    }
}

function database_backup_remove_validation_temp(array $metadata): void
{
    $path = $metadata['_payload_temp'] ?? null;
    if (is_string($path) && is_file($path) && !is_link($path)) @unlink($path);
}

function database_backup_make_defaults_file(array $config): string
{
    $directory = database_backup_directory(true);
    $path = tempnam($directory, '.client-');
    if ($path === false) throw new RuntimeException('Unable to create a private database client settings file.');
    @chmod($path, 0600);
    $quote = static function (string $value): string {
        if (str_contains($value, "\0") || str_contains($value, "\r") || str_contains($value, "\n")) throw new RuntimeException('Database client settings contain unsupported characters.');
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    };
    $contents = "[client]\n" . 'host=' . $quote($config['host']) . "\n" . 'user=' . $quote($config['user']) . "\n" . 'password=' . $quote($config['password']) . "\n" . 'default-character-set=utf8mb4' . "\n";
    if ($config['port'] !== null) $contents .= 'port=' . (int)$config['port'] . "\n";
    $stream = fopen($path, 'wb');
    if ($stream === false) {
        @unlink($path);
        throw new RuntimeException('Unable to create private database client settings.');
    }
    try {
        database_backup_write_all($stream, $contents);
    } finally {
        fclose($stream);
    }
    @chmod($path, 0600);
    return $path;
}

function database_backup_process(string $binary, array $arguments, array $descriptors): array
{
    if (database_backup_disabled_functions() !== []) throw new RuntimeException('PHP process execution is disabled on this host.');
    $process = @proc_open(array_merge([$binary], $arguments), $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Unable to start the configured MySQL command-line client.');
    $status = proc_close($process);
    return ['status' => $status, 'pipes' => $pipes];
}

function database_backup_run_dump(string $kind, ?int $actorId = null): array
{
    if (!in_array($kind, ['scheduled', 'manual', 'pre_restore'], true)) throw new RuntimeException('Invalid database backup type.');
    $config = database_backup_connection_config('DB_');
    $binary = database_backup_find_binary('MYSQLDUMP_BIN', ['mysqldump', 'mariadb-dump']);
    if ($binary === null || database_backup_disabled_functions() !== []) throw new RuntimeException('Database backup is disabled because mysqldump or PHP process execution is unavailable.');
    $directory = database_backup_directory(true);
    $defaults = database_backup_make_defaults_file($config);
    $sqlTemp = tempnam($directory, '.dump-');
    $stderrTemp = tempnam($directory, '.dump-error-');
    $gzipTemp = tempnam($directory, '.gzip-');
    if ($sqlTemp === false || $stderrTemp === false || $gzipTemp === false) {
        foreach ([$sqlTemp, $stderrTemp, $gzipTemp] as $temp) if (is_string($temp)) @unlink($temp);
        @unlink($defaults);
        throw new RuntimeException('Unable to prepare private temporary backup files.');
    }
    foreach ([$sqlTemp, $stderrTemp, $gzipTemp] as $temp) @chmod($temp, 0600);
    try {
        $dumpArgs = [
            '--defaults-extra-file=' . $defaults,
            '--single-transaction', '--quick', '--routines', '--triggers', '--events',
            '--hex-blob', '--no-tablespaces', '--skip-comments', '--default-character-set=utf8mb4',
            $config['name'],
        ];
        $process = @proc_open(array_merge([$binary], $dumpArgs), [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $sqlTemp, 'wb'],
            2 => ['file', $stderrTemp, 'wb'],
        ], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('Unable to start mysqldump.');
        $exitCode = proc_close($process);
        @chmod($sqlTemp, 0600);
        if ($exitCode !== 0) {
            error_log('Database dump command failed with exit status ' . (int)$exitCode . '.');
            throw new RuntimeException('The database dump command failed. Confirm the database client and account permissions.');
        }
        $sqlBytes = filesize($sqlTemp);
        if ($sqlBytes === false || $sqlBytes < 1 || $sqlBytes > database_backup_max_sql_bytes()) throw new RuntimeException('The SQL dump is empty or exceeds BACKUP_MAX_UNCOMPRESSED_BYTES.');
        $source = fopen($sqlTemp, 'rb');
        $gzip = gzopen($gzipTemp, 'wb6');
        if ($source === false || $gzip === false) {
            if (is_resource($source)) fclose($source);
            if ($gzip !== false) gzclose($gzip);
            throw new RuntimeException('Unable to compress the SQL dump.');
        }
        try {
            while (!feof($source)) {
                $chunk = fread($source, 65536);
                if ($chunk === false) throw new RuntimeException('Unable to read the temporary SQL dump.');
                if ($chunk !== '' && gzwrite($gzip, $chunk) !== strlen($chunk)) throw new RuntimeException('Unable to compress the SQL dump.');
            }
        } finally {
            fclose($source);
            gzclose($gzip);
        }
        @chmod($gzipTemp, 0600);
        $payloadBytes = filesize($gzipTemp);
        $payloadSha = hash_file('sha256', $gzipTemp);
        if ($payloadBytes === false || $payloadSha === false) throw new RuntimeException('Unable to validate compressed backup output.');
        $metadata = [
            'format' => 1,
            'database' => $config['name'],
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'kind' => $kind,
            'compression' => 'gzip',
            'payload_bytes' => (int)$payloadBytes,
            'payload_sha256' => $payloadSha,
            'uncompressed_bytes' => (int)$sqlBytes,
        ];
        $key = database_backup_app_key();
        if ($key === null) throw new RuntimeException('APP_KEY must contain at least 32 characters before database backups can be created.');
        $metadata['signature'] = hash_hmac('sha256', database_backup_canonical_metadata($metadata), $key);
        $header = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($header) > DATABASE_BACKUP_HEADER_MAX_BYTES) throw new RuntimeException('Database backup metadata exceeds the supported limit.');
        if (12 + strlen($header) + $payloadBytes > database_backup_max_bytes()) throw new RuntimeException('The compressed database backup exceeds BACKUP_MAX_BYTES.');

        $id = 'db-' . bin2hex(random_bytes(16)) . '.s360db';
        $target = database_backup_archive_path($id, false);
        $archiveTemp = tempnam($directory, '.archive-');
        if ($archiveTemp === false) throw new RuntimeException('Unable to prepare the signed database archive.');
        @chmod($archiveTemp, 0600);
        $output = fopen($archiveTemp, 'wb');
        $payload = fopen($gzipTemp, 'rb');
        if ($output === false || $payload === false) {
            if (is_resource($output)) fclose($output);
            if (is_resource($payload)) fclose($payload);
            @unlink($archiveTemp);
            throw new RuntimeException('Unable to write the signed database archive.');
        }
        try {
            database_backup_write_all($output, DATABASE_BACKUP_MAGIC . pack('N', strlen($header)) . $header);
            if (stream_copy_to_stream($payload, $output) !== $payloadBytes) throw new RuntimeException('Unable to write the compressed database archive.');
        } finally {
            fclose($output);
            fclose($payload);
        }
        @chmod($archiveTemp, 0600);
        if (file_exists($target) || !rename($archiveTemp, $target)) {
            @unlink($archiveTemp);
            throw new RuntimeException('Unable to safely finalize the database archive.');
        }
        @chmod($target, 0600);
        return ['id' => $id, 'metadata' => $metadata, 'bytes' => filesize($target) ?: 0, 'actor_id' => $actorId];
    } finally {
        foreach ([$defaults, $sqlTemp, $stderrTemp, $gzipTemp] as $temp) if (is_file($temp) && !is_link($temp)) @unlink($temp);
    }
}

function database_backup_extract_payload(array $metadata): string
{
    $temp = $metadata['_payload_temp'] ?? null;
    if (!is_string($temp) || !is_file($temp) || is_link($temp)) throw new RuntimeException('Verified database payload is unavailable.');
    return $temp;
}

/** Import a verified gzip SQL stream using mysql with argv and stdin pipes only. */
function database_backup_import_payload(string $payloadPath, array $config, string $database): void
{
    if (!database_backup_database_name($database)) throw new RuntimeException('Database name is invalid.');
    $binary = database_backup_find_binary('MYSQL_BIN', ['mysql', 'mariadb']);
    if ($binary === null || database_backup_disabled_functions() !== []) throw new RuntimeException('Database restore is disabled because mysql or PHP process execution is unavailable.');
    $defaults = database_backup_make_defaults_file($config);
    $stderrTemp = tempnam(database_backup_directory(true), '.mysql-error-');
    if ($stderrTemp === false) {
        @unlink($defaults);
        throw new RuntimeException('Unable to prepare a private MySQL error file.');
    }
    @chmod($stderrTemp, 0600);
    $process = null;
    $gzip = null;
    try {
        $process = @proc_open([
            $binary,
            '--defaults-extra-file=' . $defaults,
            '--default-character-set=utf8mb4',
            '--binary-mode=1',
            $database,
        ], [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', $stderrTemp, 'wb'],
        ], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('Unable to start mysql.');
        stream_set_blocking($pipes[0], true);
        $gzip = gzopen($payloadPath, 'rb');
        if ($gzip === false) throw new RuntimeException('The verified database archive could not be decompressed.');
        while (!gzeof($gzip)) {
            $chunk = gzread($gzip, 65536);
            if ($chunk === false) throw new RuntimeException('The verified database archive could not be decompressed.');
            if ($chunk === '') continue;
            $offset = 0;
            while ($offset < strlen($chunk)) {
                $written = fwrite($pipes[0], substr($chunk, $offset));
                if ($written === false || $written === 0) throw new RuntimeException('mysql stopped accepting the database archive.');
                $offset += $written;
            }
        }
        gzclose($gzip);
        $gzip = null;
        fclose($pipes[0]);
        $pipes = [];
        $exitCode = proc_close($process);
        $process = null;
        @chmod($stderrTemp, 0600);
        if ($exitCode !== 0) {
            error_log('Database restore client failed with exit status ' . (int)$exitCode . '.');
            throw new RuntimeException('The MySQL restore command failed.');
        }
    } finally {
        if (is_resource($gzip)) gzclose($gzip);
        if (is_resource($process)) {
            if (isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);
            proc_close($process);
        }
        @unlink($defaults);
        @unlink($stderrTemp);
    }
}

function database_backup_reset_staging(array $config): void
{
    database_backup_clear_database_objects($config);
}

/** Clear objects inside an existing database without requiring global CREATE/DROP DATABASE grants. */
function database_backup_clear_database_objects(array $config): void
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $connection = new mysqli($config['host'], $config['user'], $config['password'], $config['name'], $config['port'] ?? 3306);
    $connection->set_charset('utf8mb4');
    $quoteIdentifier = static fn(string $name): string => '`' . str_replace('`', '``', $name) . '`';
    try {
        $database = $connection->real_escape_string($config['name']);
        $connection->query('SET FOREIGN_KEY_CHECKS = 0');
        $views = [];
        $tables = [];
        $result = $connection->query('SHOW FULL TABLES');
        while ($row = $result->fetch_row()) {
            $name = (string)($row[0] ?? '');
            if ($name === '') continue;
            if (strcasecmp((string)($row[1] ?? ''), 'VIEW') === 0) $views[] = $name;
            else $tables[] = $name;
        }
        foreach ($views as $view) $connection->query('DROP VIEW IF EXISTS ' . $quoteIdentifier($view));
        $triggers = $connection->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '{$database}'");
        while ($row = $triggers->fetch_assoc()) $connection->query('DROP TRIGGER IF EXISTS ' . $quoteIdentifier((string)$row['TRIGGER_NAME']));
        $events = $connection->query("SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = '{$database}'");
        while ($row = $events->fetch_assoc()) $connection->query('DROP EVENT IF EXISTS ' . $quoteIdentifier((string)$row['EVENT_NAME']));
        $routines = $connection->query("SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = '{$database}'");
        while ($row = $routines->fetch_assoc()) {
            $type = strtoupper((string)$row['ROUTINE_TYPE']);
            if (in_array($type, ['PROCEDURE', 'FUNCTION'], true)) $connection->query('DROP ' . $type . ' IF EXISTS ' . $quoteIdentifier((string)$row['ROUTINE_NAME']));
        }
        foreach ($tables as $table) $connection->query('DROP TABLE IF EXISTS ' . $quoteIdentifier($table));
    } finally {
        try { $connection->query('SET FOREIGN_KEY_CHECKS = 1'); } catch (Throwable $error) {}
        $connection->close();
    }
}

function database_backup_verify_database(array $config, ?array $schemaReference = null): void
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $connection = new mysqli($config['host'], $config['user'], $config['password'], $config['name'], $config['port'] ?? 3306);
    $connection->set_charset('utf8mb4');
    try {
        $availableColumns = [];
        $baseTables = [];
        $result = $connection->query("SELECT c.TABLE_NAME, c.COLUMN_NAME, t.TABLE_TYPE
            FROM information_schema.COLUMNS c
            INNER JOIN information_schema.TABLES t
                ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
            WHERE c.TABLE_SCHEMA = DATABASE()");
        while ($row = $result->fetch_assoc()) {
            $table = strtolower((string)$row['TABLE_NAME']);
            $column = strtolower((string)$row['COLUMN_NAME']);
            $availableColumns[$table][] = $column;
            if (strcasecmp((string)$row['TABLE_TYPE'], 'BASE TABLE') === 0) $baseTables[$table] = true;
        }
        $incompatible = database_backup_schema_compatibility_errors($availableColumns, $baseTables, $schemaReference);
        if ($incompatible !== []) {
            throw new RuntimeException('The restored database is missing current Sevilla360 schema fields: ' . implode(', ', array_slice($incompatible, 0, 12)) . '.');
        }
        foreach (['users', 'bookings', 'venues'] as $table) {
            $result = $connection->query('SELECT COUNT(*) FROM `' . $table . '`');
            $result->free();
        }
    } finally {
        $connection->close();
    }
}

/** Build a normalized base-table/column fingerprint without reading business data. */
function database_backup_normalize_schema_reference(array $columns, array $baseTables): array
{
    $normalizedColumns = [];
    foreach ($columns as $table => $tableColumns) {
        $name = strtolower((string)$table);
        if ($name === '' || !is_array($tableColumns)) continue;
        $normalizedColumns[$name] = array_values(array_unique(array_map(static fn($column): string => strtolower((string)$column), $tableColumns)));
    }
    $normalizedTables = array_values(array_unique(array_filter(array_map(static fn($table): string => strtolower((string)$table), $baseTables), static fn(string $table): bool => $table !== '')));
    if ($normalizedTables === []) throw new RuntimeException('The production database schema reference is empty.');
    $base = array_fill_keys($normalizedTables, true);
    foreach (array_keys($normalizedColumns) as $table) {
        if (!isset($base[$table])) throw new RuntimeException('The production database schema reference is inconsistent.');
    }
    foreach ($normalizedTables as $table) {
        if (empty($normalizedColumns[$table])) throw new RuntimeException('The production database schema reference is incomplete.');
    }
    return ['base_tables' => $normalizedTables, 'columns' => $normalizedColumns];
}

function database_backup_require_schema_reference(?array $schemaReference): array
{
    if (!is_array($schemaReference) || !isset($schemaReference['base_tables'], $schemaReference['columns']) || !is_array($schemaReference['base_tables']) || !is_array($schemaReference['columns'])) {
        throw new RuntimeException('The production database schema reference could not be read safely.');
    }
    return database_backup_normalize_schema_reference($schemaReference['columns'], $schemaReference['base_tables']);
}

/** Capture required compatibility from the current target before touching staging or production. */
function database_backup_capture_schema_reference(mysqli $connection): array
{
    try {
        $result = $connection->query("SELECT t.TABLE_NAME, c.COLUMN_NAME, t.TABLE_TYPE
            FROM information_schema.TABLES t
            INNER JOIN information_schema.COLUMNS c
                ON c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME
            WHERE t.TABLE_SCHEMA = DATABASE()");
        $columns = [];
        $baseTables = [];
        while ($row = $result->fetch_assoc()) {
            $table = strtolower((string)$row['TABLE_NAME']);
            if (strcasecmp((string)$row['TABLE_TYPE'], 'BASE TABLE') === 0) {
                $columns[$table][] = (string)$row['COLUMN_NAME'];
                $baseTables[$table] = true;
            }
        }
        return database_backup_normalize_schema_reference($columns, array_keys($baseTables));
    } catch (Throwable $error) {
        throw new RuntimeException('The production database schema reference could not be read safely.', 0, $error);
    }
}

/** Minimum current business, authentication, payment, and cancellation schema. */
function database_backup_required_schema_columns(): array
{
    return [
        // reset_token_hash (019), contact/policy/deadline fields (006/010/020),
        // refund fields (007/012/022), and manual payments (020) are used by
        // current authentication and transaction flows. Optional integrations
        // such as Google sign-in, reviews, realtime delivery, and room groups
        // do not block when absent; any such tables/columns enabled on live
        // production are required by the captured schema reference.
        'users' => ['id', 'email', 'role', 'status', 'password_hash', 'reset_token_hash'],
        'staff' => ['user_id', 'status'],
        'customers' => ['id', 'user_id', 'first_name', 'last_name', 'email', 'phone'],
        'venues' => ['id', 'name', 'category', 'status'],
        'bookings' => ['id', 'customer_id', 'venue_id', 'reference_no', 'start_date', 'end_date', 'guests_count', 'contact_phone', 'booking_status', 'payment_status', 'amount_paid', 'payment_due_at', 'policy_accepted_at', 'policy_version'],
        'payments' => ['id', 'booking_id', 'transaction_id', 'payment_method', 'amount', 'payment_date', 'status'],
        'cancellations' => ['id', 'booking_id', 'status', 'refund_transaction_id', 'fee_percent', 'refund_destination_method', 'refund_destination_account_name', 'refund_destination_account_identifier', 'refund_destination_bank_name'],
        'manual_payment_submissions' => ['id', 'booking_id', 'customer_user_id', 'reviewer_user_id', 'payment_id', 'payment_method', 'expected_amount', 'transaction_reference', 'reference_fingerprint', 'proof_filename', 'proof_mime', 'proof_size_bytes', 'proof_sha256', 'status', 'rejection_reason', 'submitted_at', 'reviewed_at'],
        'seminars' => ['id', 'name', 'agreed_price', 'status', 'hall_venue_id', 'hall_start_date', 'hall_end_date', 'hotel_check_in', 'hotel_check_out', 'created_by', 'finalized_at'],
        'seminar_reservations' => ['id', 'seminar_id', 'venue_id', 'resource_kind', 'start_date', 'end_date', 'floor_label', 'allow_mixed_gender'],
        'seminar_attendees' => ['id', 'seminar_id', 'full_name', 'gender', 'location', 'contact', 'assigned_venue_id', 'solo_flag'],
        'seminar_payments' => ['id', 'seminar_id', 'amount', 'payment_method', 'transaction_reference', 'reference_fingerprint', 'idempotency_key', 'status', 'created_by', 'created_at', 'voided_by', 'voided_at', 'void_reason'],
        'audit_logs' => ['id', 'user_id', 'created_at', 'module', 'action', 'ip_address'],
    ];
}

/** Return missing base tables and columns using case-insensitive schema names. */
function database_backup_schema_compatibility_errors(array $availableColumns, array $baseTables, ?array $schemaReference = null): array
{
    $available = [];
    foreach ($availableColumns as $table => $columns) {
        $available[strtolower((string)$table)] = array_fill_keys(array_map(static fn($column): string => strtolower((string)$column), (array)$columns), true);
    }
    $reference = $schemaReference === null ? null : database_backup_require_schema_reference($schemaReference);
    // $baseTables describes the imported candidate only. The fingerprint is
    // a list of production requirements; it must never make a missing table
    // look present in the candidate.
    $base = array_fill_keys(array_map(static fn($table): string => strtolower((string)$table), array_keys(array_filter($baseTables))), true);
    $missing = [];
    $required = database_backup_required_schema_columns();
    if ($reference !== null) {
        foreach ($reference['columns'] as $table => $columns) $required[$table] = array_values(array_unique(array_merge($required[$table] ?? [], $columns)));
    }
    foreach ($required as $table => $columns) {
        $normalizedTable = strtolower($table);
        if (!isset($base[$normalizedTable])) {
            $missing[] = $table . ' (base table)';
            continue;
        }
        foreach ($columns as $column) {
            if (!isset($available[$normalizedTable][strtolower($column)])) $missing[] = $table . '.' . $column;
        }
    }
    return $missing;
}

function database_backup_preflight(string $archivePath, array $schemaReference): array
{
    $schemaReference = database_backup_require_schema_reference($schemaReference);
    $metadata = database_backup_validate_archive($archivePath);
    try {
        $payload = database_backup_extract_payload($metadata);
        $staging = database_backup_staging_config();
        database_backup_reset_staging($staging);
        database_backup_import_payload($payload, $staging, $staging['name']);
        database_backup_verify_database($staging, $schemaReference);
        return $metadata;
    } finally {
        database_backup_remove_validation_temp($metadata);
    }
}

function database_backup_lock(bool $exclusive, bool $blocking = false)
{
    $directory = database_backup_directory(true);
    $path = $directory . DIRECTORY_SEPARATOR . 'operation.lock';
    if (is_link($path)) throw new RuntimeException('Database backup operation lock is invalid.');
    $stream = fopen($path, 'c+b');
    if ($stream === false) throw new RuntimeException('Unable to access the database backup operation lock.');
    @chmod($path, 0600);
    $mode = $exclusive ? LOCK_EX : LOCK_SH;
    if (!$blocking) $mode |= LOCK_NB;
    if (!flock($stream, $mode)) {
        fclose($stream);
        return false;
    }
    return $stream;
}

function database_backup_release_lock($lock): void
{
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function database_backup_maintenance_path(): string
{
    return database_backup_directory(false) . DIRECTORY_SEPARATOR . 'maintenance.json';
}

function database_backup_maintenance_state(): ?array
{
    try {
        $path = database_backup_maintenance_path();
    } catch (Throwable $error) {
        $configured = database_backup_env('BACKUP_DIR');
        $marker = rtrim($configured, '/') . DIRECTORY_SEPARATOR . 'maintenance.json';
        if ($configured !== '' && !str_contains($configured, "\0") && (file_exists($marker) || is_link($marker))) return ['active' => true, 'state' => 'unreadable'];
        return null;
    }
    if (is_link($path) || !is_file($path)) return null;
    $raw = file_get_contents($path);
    if (!is_string($raw) || strlen($raw) > 4096) return ['active' => true, 'state' => 'unreadable'];
    $state = json_decode($raw, true);
    if (!is_array($state) || ($state['active'] ?? false) !== true) return ['active' => true, 'state' => 'invalid'];
    return $state;
}

function database_backup_write_maintenance(string $jobId, string $phase): void
{
    if (!preg_match('/\Ajob-[a-f0-9]{32}\.json\z/D', $jobId) || strlen($phase) > 100) throw new RuntimeException('Invalid database maintenance state.');
    $directory = database_backup_directory(true);
    $path = $directory . DIRECTORY_SEPARATOR . 'maintenance.json';
    $temp = tempnam($directory, '.maintenance-');
    if ($temp === false) throw new RuntimeException('Unable to create database maintenance state.');
    @chmod($temp, 0600);
    $contents = json_encode(['active' => true, 'job_id' => $jobId, 'phase' => $phase, 'started_at' => gmdate('Y-m-d\TH:i:s\Z')], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    try {
        file_put_contents($temp, $contents, LOCK_EX);
        @chmod($temp, 0600);
        if (!rename($temp, $path)) throw new RuntimeException('Unable to activate the database maintenance gate.');
    } finally {
        if (is_file($temp)) @unlink($temp);
    }
}

function database_backup_clear_maintenance(?string $jobId = null): void
{
    $path = database_backup_maintenance_path();
    clearstatcache(true, $path);
    if (!file_exists($path) && !is_link($path)) return;
    if (is_link($path) || !is_file($path)) throw new RuntimeException('The database maintenance marker is invalid and cannot be cleared safely.');
    if ($jobId !== null) {
        $state = database_backup_maintenance_state();
        if (!is_array($state) || ($state['job_id'] ?? null) !== $jobId) throw new RuntimeException('The database maintenance marker belongs to a different or unreadable job.');
    }
    if (!unlink($path)) throw new RuntimeException('Unable to clear the database maintenance marker.');
    clearstatcache(true, $path);
    if (file_exists($path) || is_link($path)) throw new RuntimeException('The database maintenance marker remains active.');
}

function database_backup_request_is_exempt_from_gate(): bool
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $allowed = [
        '/actions/admin/create_database_backup.php',
        '/actions/admin/upload_database_backup.php',
        '/actions/admin/restore_database_backup.php',
        '/actions/admin/get_database_backup_status.php',
        '/actions/admin/download_database_backup.php',
    ];
    foreach ($allowed as $path) if ($script === $path || str_ends_with($script, $path)) return true;
    return false;
}

function database_backup_route_uses_gate(string $script, string $method): bool
{
    $allowed = [
        '/actions/admin/create_database_backup.php',
        '/actions/admin/upload_database_backup.php',
        '/actions/admin/restore_database_backup.php',
        '/actions/admin/get_database_backup_status.php',
        '/actions/admin/download_database_backup.php',
    ];
    foreach ($allowed as $path) if ($script === $path || str_ends_with($script, $path)) return false;
    $method = strtoupper($method);
    $isAction = str_contains('/' . ltrim($script, '/'), '/actions/');
    return $isAction || !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
}

function database_backup_web_request_uses_gate(): bool
{
    if (PHP_SAPI === 'cli') return false;
    return database_backup_route_uses_gate((string)($_SERVER['SCRIPT_NAME'] ?? ''), (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

/** Gate all unsafe web methods for the full request lifetime. */
function database_backup_enforce_web_write_gate(): void
{
    if (!database_backup_web_request_uses_gate()) return;
    $configured = database_backup_env('BACKUP_DIR');
    if ($configured === '') return;
    try {
        database_backup_directory(false);
    } catch (Throwable $error) {
        if (database_backup_maintenance_state() === null) return;
        http_response_code(503);
        header('Retry-After: 60');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Database maintenance status is unavailable. Please try again shortly.']);
        exit;
    }
    $lock = database_backup_lock(false, false);
    if ($lock === false) {
        http_response_code(503);
        header('Retry-After: 60');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Database maintenance is in progress. Please try again shortly.']);
        exit;
    }
    $state = database_backup_maintenance_state();
    if ($state === null) {
        register_shutdown_function(static function () use ($lock): void { database_backup_release_lock($lock); });
        return;
    }
    database_backup_release_lock($lock);
    http_response_code(503);
    header('Retry-After: 60');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Database maintenance is in progress. Please try again shortly.']);
    exit;
}

function database_backup_csrf_valid(): bool
{
    $session = $_SESSION['csrf_token'] ?? '';
    $client = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
    return is_string($session) && $session !== '' && is_string($client) && $client !== '' && hash_equals($session, $client);
}

function database_backup_is_admin_session(): bool
{
    return ($_SESSION['logged_in'] ?? false) === true && ($_SESSION['role'] ?? '') === 'admin' && (int)($_SESSION['user_id'] ?? 0) > 0;
}

function database_backup_require_admin(bool $csrf = true): void
{
    if (!database_backup_is_admin_session()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Administrator access is required.']);
        exit;
    }
    if ($csrf && !database_backup_csrf_valid()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']);
        exit;
    }
}

function database_backup_job_path(string $id): string
{
    if (!preg_match('/\Ajob-[a-f0-9]{32}\.json\z/D', $id)) throw new RuntimeException('Invalid backup job ID.');
    $directory = database_backup_subdirectory('jobs', false);
    $path = $directory . DIRECTORY_SEPARATOR . $id;
    if (is_link($path) || (file_exists($path) && (!is_file($path) || dirname((string)realpath($path)) !== $directory))) throw new RuntimeException('Backup job path is invalid.');
    return $path;
}

function database_backup_write_job(array $job): void
{
    $directory = database_backup_subdirectory('jobs', true);
    $id = (string)($job['id'] ?? '');
    if (!preg_match('/\Ajob-[a-f0-9]{32}\.json\z/D', $id)) throw new RuntimeException('Invalid backup job ID.');
    $path = $directory . DIRECTORY_SEPARATOR . $id;
    if (is_link($path)) throw new RuntimeException('Backup job path is invalid.');
    $temp = tempnam($directory, '.job-');
    if ($temp === false) throw new RuntimeException('Unable to update private backup job status.');
    @chmod($temp, 0600);
    try {
        $json = json_encode($job, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        if (strlen($json) > 16384) throw new RuntimeException('Backup job status is too large.');
        file_put_contents($temp, $json, LOCK_EX);
        @chmod($temp, 0600);
        if (!rename($temp, $path)) throw new RuntimeException('Unable to update private backup job status.');
    } finally {
        if (is_file($temp)) @unlink($temp);
    }
}

function database_backup_load_job(string $id): array
{
    $path = database_backup_job_path($id);
    if (!is_file($path)) throw new RuntimeException('Backup job was not found.');
    $raw = file_get_contents($path);
    $job = is_string($raw) && strlen($raw) <= 16384 ? json_decode($raw, true) : null;
    if (!is_array($job) || ($job['id'] ?? null) !== $id) throw new RuntimeException('Backup job status is invalid.');
    return $job;
}

function database_backup_queue_job(string $action, int $actorId, ?string $archiveId = null, bool $freshAuth = false): array
{
    if (!in_array($action, ['create', 'restore', 'validate'], true) || $actorId < 1) throw new RuntimeException('Invalid database backup job request.');
    if ($archiveId !== null && !preg_match('/\Adb-[a-f0-9]{32}\.s360db\z/D', $archiveId)) throw new RuntimeException('Invalid database backup ID.');
    $id = 'job-' . bin2hex(random_bytes(16)) . '.json';
    $job = [
        'id' => $id,
        'action' => $action,
        'actor_id' => $actorId,
        'archive_id' => $archiveId,
        'status' => 'queued',
        'phase' => 'Queued',
        'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'started_at' => null,
        'completed_at' => null,
        'safety_archive_id' => null,
        'message' => 'Waiting for the command-line worker.',
        'fresh_auth' => $freshAuth,
        'fresh_auth_at' => $freshAuth ? time() : null,
    ];
    database_backup_write_job($job);
    return $job;
}

function database_backup_list_jobs(int $limit = 10): array
{
    try {
        $directory = database_backup_subdirectory('jobs', false);
    } catch (Throwable $error) {
        return [];
    }
    $paths = glob($directory . DIRECTORY_SEPARATOR . 'job-*.json') ?: [];
    $jobs = [];
    foreach ($paths as $path) {
        if (is_link($path) || !is_file($path) || !preg_match('/\Ajob-[a-f0-9]{32}\.json\z/D', basename($path))) continue;
        try { $jobs[] = database_backup_load_job(basename($path)); } catch (Throwable $error) { continue; }
    }
    usort($jobs, static fn(array $a, array $b): int => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
    return array_slice($jobs, 0, max(1, min(50, $limit)));
}

function database_backup_list_archives(bool $verify = false): array
{
    try {
        $directory = database_backup_directory(false);
    } catch (Throwable $error) {
        return [];
    }
    $archives = [];
    foreach (glob($directory . DIRECTORY_SEPARATOR . 'db-*.s360db') ?: [] as $path) {
        $id = basename($path);
        if (is_link($path) || !is_file($path) || !preg_match('/\Adb-[a-f0-9]{32}\.s360db\z/D', $id)) continue;
        $row = ['id' => $id, 'bytes' => filesize($path) ?: 0, 'created_at' => null, 'kind' => 'unknown', 'header_valid' => false, 'payload_checked' => false, 'payload_valid' => null];
        try {
            $metadata = database_backup_read_archive_header($path);
            $row['created_at'] = $metadata['created_at'];
            $row['kind'] = $metadata['kind'];
            $row['header_valid'] = true;
        } catch (Throwable $error) {
            $row['message'] = 'Archive metadata check failed.';
        }
        if ($verify && $row['header_valid']) {
            $row['payload_checked'] = true;
            try {
                $verified = database_backup_validate_archive($path);
                database_backup_remove_validation_temp($verified);
                $row['payload_valid'] = true;
            } catch (Throwable $error) {
                $row['payload_valid'] = false;
                $row['message'] = 'Archive validation failed.';
            }
        }
        $archives[] = $row;
    }
    usort($archives, static fn(array $a, array $b): int => strcmp((string)$b['created_at'], (string)$a['created_at']));
    return $archives;
}

function database_backup_apply_retention(): int
{
    $directory = database_backup_directory(true);
    $archives = [];
    foreach (glob($directory . DIRECTORY_SEPARATOR . 'db-*.s360db') ?: [] as $path) {
        if (is_link($path) || !is_file($path) || !preg_match('/\Adb-[a-f0-9]{32}\.s360db\z/D', basename($path))) continue;
        try {
            $metadata = database_backup_validate_archive($path);
            $archives[] = ['path' => $path, 'id' => basename($path), 'kind' => $metadata['kind'], 'created_at' => $metadata['created_at'], 'timestamp' => strtotime($metadata['created_at']) ?: 0];
            database_backup_remove_validation_temp($metadata);
        } catch (Throwable $error) {
            // Corrupt or incompatible files are retained for administrator review.
        }
    }
    $cutoff = time() - (DATABASE_BACKUP_RETENTION_DAYS * 86400);
    $scheduled = array_values(array_filter($archives, static fn(array $row): bool => $row['kind'] === 'scheduled'));
    usort($scheduled, static fn(array $a, array $b): int => $b['timestamp'] <=> $a['timestamp']);
    $keepScheduled = array_fill_keys(array_column(array_slice($scheduled, 0, DATABASE_BACKUP_RETENTION_DAYS), 'id'), true);
    $removed = 0;
    foreach ($archives as $archive) {
        $expired = $archive['timestamp'] > 0 && $archive['timestamp'] < $cutoff;
        $oldScheduled = $archive['kind'] === 'scheduled' && !isset($keepScheduled[$archive['id']]);
        if (($expired || $oldScheduled) && is_file($archive['path']) && !is_link($archive['path']) && unlink($archive['path'])) $removed++;
    }
    return $removed;
}

function database_backup_audit(mysqli $conn, ?int $userId, string $eventType, string $action, array $details = []): void
{
    $safeEvents = ['database.backup_created', 'database.backup_uploaded', 'database.backup_downloaded', 'database.restore_started', 'database.restore_succeeded', 'database.restore_failed', 'database.restore_recovered'];
    if (!in_array($eventType, $safeEvents, true)) return;
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'CLI');
    if (strlen($ip) > 45) $ip = substr($ip, 0, 45);
    $json = json_encode(array_intersect_key($details, array_flip(['archive_id', 'job_id', 'kind', 'status', 'safety_archive_id'])), JSON_INVALID_UTF8_SUBSTITUTE);
    try {
        $stmt = $conn->prepare('INSERT INTO audit_logs (user_id, module, action, ip_address, event_type, entity_type, entity_id, details_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        if ($stmt) {
            $module = 'Database Backup';
            $entityType = 'database_backup';
            $entityId = null;
            $stmt->bind_param('isssssis', $userId, $module, $action, $ip, $eventType, $entityType, $entityId, $json);
            if ($stmt->execute()) {
                $stmt->close();
                return;
            }
            $stmt->close();
        }
        $legacy = $conn->prepare('INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, ?, ?, ?)');
        if (!$legacy) throw new RuntimeException('Unable to prepare legacy audit entry.');
        $module = 'Database Backup';
        $legacy->bind_param('isss', $userId, $module, $action, $ip);
        if (!$legacy->execute()) throw new RuntimeException('Unable to write legacy audit entry.');
        $legacy->close();
    } catch (Throwable $error) {
        error_log('Database backup audit entry could not be recorded.');
    }
}

function database_backup_admin_account_state(?array $row): string
{
    if ($row === null) return 'unverifiable';
    if (($row['role'] ?? null) !== 'admin') return 'inactive';
    $staffStatus = $row['staff_status'] ?? null;
    if (!is_string($staffStatus)) return 'unverifiable';
    return strcasecmp(trim($staffStatus), 'active') === 0 ? 'active' : 'inactive';
}

/** Allow status polling from the authenticated session only while maintenance is active and the DB cannot confirm suspension. */
function database_backup_admin_status_fallback_allowed(?array $maintenanceState, string $errorMessage): bool
{
    return $maintenanceState !== null && $errorMessage !== 'The administrator account is no longer active.';
}

function database_backup_verify_fresh_admin(mysqli $conn, int $actorId): void
{
    $stmt = $conn->prepare("SELECT u.role, u.status AS user_status, s.status AS staff_status FROM users u LEFT JOIN staff s ON s.user_id = u.id WHERE u.id = ? LIMIT 1");
    if (!$stmt) throw new RuntimeException('Unable to verify the administrator account.');
    $stmt->bind_param('i', $actorId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $state = database_backup_admin_account_state(is_array($row) ? $row : null);
    if ($state === 'inactive') throw new RuntimeException('The administrator account is no longer active.');
    if ($state !== 'active') throw new RuntimeException('The administrator account could not be verified.');
}

function database_backup_verify_admin_password(mysqli $conn, int $actorId, string $password): bool
{
    if ($password === '' || strlen($password) > 1024) return false;
    $stmt = $conn->prepare("SELECT u.password_hash, u.role, u.status AS user_status, s.status AS staff_status FROM users u LEFT JOIN staff s ON s.user_id = u.id WHERE u.id = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('i', $actorId);
    if (!$stmt->execute()) {
        $stmt->close();
        return false;
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return is_array($row) && $row['role'] === 'admin' && strcasecmp((string)($row['staff_status'] ?? ''), 'active') === 0 && is_string($row['password_hash'] ?? null) && password_verify($password, $row['password_hash']);
}

/** Revalidate session role and staff status against the current database row. */
function database_backup_verify_request_admin(bool $allowMaintenanceFallback = false): bool
{
    $connection = null;
    try {
        $connection = database_backup_connect_production();
        database_backup_verify_fresh_admin($connection, (int)($_SESSION['user_id'] ?? 0));
        return true;
    } catch (Throwable $error) {
        if ($allowMaintenanceFallback && database_backup_admin_status_fallback_allowed(database_backup_maintenance_state(), $error->getMessage())) return false;
        if ($error->getMessage() === 'The administrator account is no longer active.') throw $error;
        throw new RuntimeException('The active administrator account could not be revalidated.');
    } finally {
        if ($connection instanceof mysqli) $connection->close();
    }
}

function database_backup_job_update(array &$job, string $status, string $phase, string $message, array $extra = []): void
{
    $job['status'] = $status;
    $job['phase'] = $phase;
    $job['message'] = substr($message, 0, 500);
    if ($status === 'running' && empty($job['started_at'])) $job['started_at'] = gmdate('Y-m-d\TH:i:s\Z');
    if (in_array($status, ['succeeded', 'succeeded_maintenance', 'failed', 'failed_recovered', 'recovered_maintenance', 'recovery_failed'], true)) $job['completed_at'] = gmdate('Y-m-d\TH:i:s\Z');
    foreach ($extra as $key => $value) $job[$key] = $value;
    database_backup_write_job($job);
}

function database_backup_connect_production(): mysqli
{
    $config = database_backup_connection_config('DB_');
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $connection = new mysqli($config['host'], $config['user'], $config['password'], $config['name'], $config['port'] ?? 3306);
    $connection->set_charset('utf8mb4');
    return $connection;
}

/** Execute one queued create/validate/restore job. Called only by the CLI worker. */
function database_backup_process_job(string $id): array
{
    $job = database_backup_load_job($id);
    if (($job['status'] ?? '') !== 'queued') return $job;
    $action = (string)($job['action'] ?? '');
    $conn = null;
    $lock = null;
    $schemaReference = null;
    $restoreVerified = false;
    try {
        if (database_backup_maintenance_state() !== null) throw new RuntimeException('Database maintenance is already active; use the CLI recovery path before starting queued backup work.');
        $conn = database_backup_connect_production();
        database_backup_verify_fresh_admin($conn, (int)($job['actor_id'] ?? 0));
        if ($action === 'create') {
            $lock = database_backup_lock(false, false);
            if ($lock === false || database_backup_maintenance_state() !== null) throw new RuntimeException('Database restore is active; the backup job will be retried after maintenance.');
            database_backup_job_update($job, 'running', 'Creating database dump', 'Dumping and signing the database.');
            $created = database_backup_run_dump('manual', (int)$job['actor_id']);
            database_backup_audit($conn, (int)$job['actor_id'], 'database.backup_created', 'Created signed database backup', ['archive_id' => $created['id'], 'job_id' => $id, 'kind' => 'manual', 'status' => 'succeeded']);
            database_backup_apply_retention();
            database_backup_job_update($job, 'succeeded', 'Complete', 'Signed database backup is ready for download.', ['archive_id' => $created['id']]);
            return $job;
        }
        if (!in_array($action, ['restore', 'validate'], true)) throw new RuntimeException('Unsupported database backup job.');
        $schemaReference = database_backup_capture_schema_reference($conn);
        if ($action === 'restore' && (($job['fresh_auth'] ?? false) !== true || !is_int($job['fresh_auth_at'] ?? null) || time() - $job['fresh_auth_at'] > 900)) {
            throw new RuntimeException('Fresh administrator authentication expired before the restore worker ran.');
        }
        $archiveId = (string)($job['archive_id'] ?? '');
        $archivePath = database_backup_archive_path($archiveId, true);
        database_backup_job_update($job, 'running', 'Preflight on staging database', 'Testing the signed archive against the dedicated staging database.');
        database_backup_preflight($archivePath, $schemaReference);
        if ($action === 'validate') {
            database_backup_job_update($job, 'succeeded', 'Preflight passed', 'The signed archive restores and contains the required Sevilla360 tables on staging.');
            return $job;
        }
        database_backup_verify_fresh_admin($conn, (int)$job['actor_id']);
        $lock = database_backup_lock(true, true);
        database_backup_write_maintenance($id, 'Preparing pre-restore safety backup');
        database_backup_job_update($job, 'running', 'Creating safety backup', 'The site write gate is active; creating a signed pre-restore safety backup.');
        database_backup_audit($conn, (int)$job['actor_id'], 'database.restore_started', 'Started database restore', ['archive_id' => $archiveId, 'job_id' => $id, 'status' => 'running']);
        $safety = database_backup_run_dump('pre_restore', (int)$job['actor_id']);
        $job['safety_archive_id'] = $safety['id'];
        database_backup_write_job($job);
        database_backup_write_maintenance($id, 'Restoring production database');
        database_backup_job_update($job, 'running', 'Restoring production database', 'Applying the verified archive to production.');
        database_backup_clear_database_objects(database_backup_connection_config('DB_'));
        $metadata = database_backup_validate_archive($archivePath);
        try {
            database_backup_import_payload(database_backup_extract_payload($metadata), database_backup_connection_config('DB_'), database_backup_connection_config('DB_')['name']);
        } finally {
            database_backup_remove_validation_temp($metadata);
        }
        database_backup_verify_database(database_backup_connection_config('DB_'), $schemaReference);
        $restoreVerified = true;
        $conn->close();
        $conn = database_backup_connect_production();
        database_backup_audit($conn, (int)$job['actor_id'], 'database.restore_succeeded', 'Restored production database', ['archive_id' => $archiveId, 'job_id' => $id, 'safety_archive_id' => $safety['id'], 'status' => 'succeeded']);
        database_backup_job_update($job, 'succeeded', 'Restore verified', 'Production restore passed verification; clearing the maintenance gate.', ['archive_id' => $archiveId, 'safety_archive_id' => $safety['id']]);
        database_backup_clear_maintenance($id);
        try {
            database_backup_apply_retention();
        } catch (Throwable $retentionError) {
            error_log('Database backup retention failed after a successful restore.');
        }
        return $job;
    } catch (Throwable $error) {
        error_log('Database backup job ' . $id . ' failed: ' . get_class($error));
        if ($action === 'restore' && $restoreVerified) {
            try {
                $maintenance = database_backup_maintenance_state();
                if ($maintenance === null) database_backup_write_maintenance($id, 'Restore verified; maintenance marker cleanup failed');
                elseif (($maintenance['job_id'] ?? null) === $id) database_backup_write_maintenance($id, 'Restore verified; maintenance marker cleanup failed');
            } catch (Throwable $markerError) {
                error_log('Verified database restore maintenance state could not be refreshed.');
            }
            try {
                database_backup_job_update($job, 'succeeded_maintenance', 'Restore verified; writes paused', 'Production restore is verified, but the maintenance marker could not be cleared safely. Inspect private storage before reopening writes.', ['archive_id' => (string)($job['archive_id'] ?? ''), 'safety_archive_id' => (string)($job['safety_archive_id'] ?? ''), 'maintenance_cleanup_error_type' => get_class($error)]);
            } catch (Throwable $statusError) {
                error_log('Verified database restore job status could not be updated.');
            }
        } elseif ($action === 'restore' && is_string($job['safety_archive_id'] ?? null) && $job['safety_archive_id'] !== '') {
            $safetyPath = null;
            $recoveryVerified = false;
            try {
                database_backup_write_maintenance($id, 'Automatic recovery in progress');
                database_backup_job_update($job, 'running', 'Recovering from safety backup', 'The requested restore failed; restoring the pre-restore safety backup.');
                $safetyPath = database_backup_archive_path($job['safety_archive_id'], true);
                $safety = database_backup_validate_archive($safetyPath);
                try {
                    database_backup_clear_database_objects(database_backup_connection_config('DB_'));
                    database_backup_import_payload(database_backup_extract_payload($safety), database_backup_connection_config('DB_'), database_backup_connection_config('DB_')['name']);
                } finally {
                    database_backup_remove_validation_temp($safety);
                }
                database_backup_verify_database(database_backup_connection_config('DB_'), $schemaReference);
                $recoveryVerified = true;
                database_backup_job_update($job, 'failed_recovered', 'Recovered from safety backup', 'The restore failed. Production was recovered and verified; clearing the maintenance gate.', ['recovery_completed_at' => gmdate('Y-m-d\TH:i:s\Z')]);
                try {
                    if ($conn instanceof mysqli) database_backup_audit($conn, (int)$job['actor_id'], 'database.restore_recovered', 'Recovered production database after failed restore', ['archive_id' => (string)($job['archive_id'] ?? ''), 'job_id' => $id, 'safety_archive_id' => $job['safety_archive_id'], 'status' => 'recovered']);
                    if ($conn instanceof mysqli) database_backup_audit($conn, (int)$job['actor_id'], 'database.restore_failed', 'Failed restore recovered from safety archive', ['archive_id' => (string)($job['archive_id'] ?? ''), 'job_id' => $id, 'safety_archive_id' => $job['safety_archive_id'], 'status' => 'failed_recovered']);
                } catch (Throwable $auditError) {
                    error_log('Database restore recovery audit could not be written.');
                }
                database_backup_clear_maintenance($id);
            } catch (Throwable $recoveryError) {
                try {
                    $maintenance = database_backup_maintenance_state();
                    if ($maintenance === null) database_backup_write_maintenance($id, $recoveryVerified ? 'Recovery verified; maintenance marker cleanup failed' : 'Recovery failed; manual recovery required');
                    elseif (($maintenance['job_id'] ?? null) === $id) database_backup_write_maintenance($id, $recoveryVerified ? 'Recovery verified; maintenance marker cleanup failed' : 'Recovery failed; manual recovery required');
                } catch (Throwable $markerError) {
                    error_log('Database restore recovery maintenance state could not be refreshed.');
                }
                try {
                    if ($recoveryVerified) {
                        database_backup_job_update($job, 'recovered_maintenance', 'Safety recovery verified; writes paused', 'Production was recovered and verified, but the maintenance marker could not be cleared safely. Inspect private storage before reopening writes.', ['recovery_error_type' => get_class($recoveryError)]);
                    } else {
                        database_backup_job_update($job, 'recovery_failed', 'Manual recovery required', 'Automatic recovery failed. The maintenance gate remains active; restore the listed safety archive from the CLI worker.', ['recovery_error_type' => get_class($recoveryError)]);
                    }
                    if (!$recoveryVerified && $conn instanceof mysqli) database_backup_audit($conn, (int)$job['actor_id'], 'database.restore_failed', 'Restore and safety recovery failed', ['archive_id' => (string)($job['archive_id'] ?? ''), 'job_id' => $id, 'safety_archive_id' => $job['safety_archive_id'], 'status' => 'recovery_failed']);
                } catch (Throwable $statusError) {
                    error_log('Database restore recovery job status could not be updated.');
                }
            }
        } elseif ($action === 'restore') {
            try {
                database_backup_clear_maintenance($id);
                database_backup_job_update($job, 'failed', 'Restore not applied', 'Restore preflight or safety backup failed; production was not changed.');
                if ($conn instanceof mysqli) database_backup_audit($conn, (int)$job['actor_id'], 'database.restore_failed', 'Database restore was not applied', ['archive_id' => (string)($job['archive_id'] ?? ''), 'job_id' => $id, 'status' => 'failed']);
            } catch (Throwable $markerError) {
                error_log('A failed database restore could not clear its maintenance marker.');
                try {
                    database_backup_job_update($job, 'failed_maintenance', 'Restore not applied; writes paused', 'Production was not changed, but the maintenance marker could not be cleared safely. Inspect private storage before reopening writes.', ['maintenance_cleanup_error_type' => get_class($markerError)]);
                } catch (Throwable $statusError) {
                    error_log('Failed database restore job status could not be updated.');
                }
            }
        } else {
            database_backup_job_update($job, 'failed', 'Failed', 'The database backup job failed. Check the server log and host capabilities.');
        }
        return $job;
    } finally {
        if ($conn instanceof mysqli) $conn->close();
        database_backup_release_lock($lock);
    }
}
