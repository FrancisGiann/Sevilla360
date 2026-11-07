<?php
declare(strict_types=1);

// A bounded framed stream, not a general-purpose archive: no archive entry can
// carry a link, permissions, executable configuration, or extraction command.
const DATABASE_BACKUP_MEDIA_MAGIC = 'S360IMG2';
const DATABASE_BACKUP_MEDIA_MANIFEST_MAX = 2097152;
const DATABASE_BACKUP_MEDIA_FILES_MAX = 10000;
const DATABASE_BACKUP_MEDIA_FILE_MAX = 67108864;

function database_backup_media_root(): string
{
    $root = dirname(__DIR__) . '/assets/uploads';
    // Ancestors must also be real directories, never redirected by a link.
    for ($path = $root; $path !== dirname($path); $path = dirname($path)) {
        if (is_link($path) || (file_exists($path) && !is_dir($path))) throw new RuntimeException('Image storage has an unsafe ancestor.');
    }
    return $root;
}

function database_backup_media_path_valid(string $path): bool
{
    // Current CMS originals, generated WebP derivatives, and the narrow QR
    // path accepted only to safely inspect format-2 archives created before
    // QR files were removed from backup coverage.
    return strlen($path) <= 240 && preg_match('~\A(?:[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|webp)|(?:\.cms-thumbnails|\.cms-hero|\.cms-card)/[a-f0-9]{64}(?:-[0-9]{2,4})?\.webp|payment-qrs/[a-f0-9]{48}\.(?:jpg|png|webp))\z~D', $path) === 1;
}

function database_backup_media_is_payment_qr(string $path): bool
{
    return str_starts_with($path, 'payment-qrs/');
}

function database_backup_media_backup_path_valid(string $path): bool
{
    return !database_backup_media_is_payment_qr($path) && database_backup_media_path_valid($path);
}

function database_backup_media_image(string $file, string $relative): void
{
    $stat = lstat($file);
    if ($stat === false || is_link($file) || !is_file($file) || $stat['nlink'] !== 1 || $stat['size'] < 1 || $stat['size'] > DATABASE_BACKUP_MEDIA_FILE_MAX) throw new RuntimeException('An image is unavailable, linked, or exceeds the 64 MiB file limit.');
    $expected = match (strtolower(pathinfo($relative, PATHINFO_EXTENSION))) {
        'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', default => '',
    };
    $info = @getimagesize($file);
    if ($expected === '' || !is_array($info) || ($info['mime'] ?? '') !== $expected || (new finfo(FILEINFO_MIME_TYPE))->file($file) !== $expected || $info[0] < 1 || $info[1] < 1 || $info[0] > 32768 || $info[1] > 32768 || $info[0] * $info[1] > 134217728) throw new RuntimeException('An image has unsupported or invalid content.');
}

/** Inventory without following links. Non-image protection/config files stay local. */
function database_backup_media_inventory(): array
{
    $root = database_backup_media_root();
    if (!is_dir($root)) return [];
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    $visited = 0;
    foreach ($iterator as $entry) {
        if (++$visited > 20000) throw new RuntimeException('Image storage has too many entries.');
        $path = $entry->getPathname();
        if ($entry->isLink()) throw new RuntimeException('Image storage contains a symbolic link.');
        if ($entry->isDir()) continue;
        if (!$entry->isFile()) throw new RuntimeException('Image storage contains a non-regular file.');
        $relative = substr($path, strlen($root) + 1);
        // QR files are local payment configuration assets, not backup media.
        // Leave them untouched and omit every file beneath this private folder.
        if (database_backup_media_is_payment_qr($relative)) continue;
        if (!database_backup_media_backup_path_valid($relative)) {
            // Never silently omit an image in an unsupported location/name.
            if (preg_match('/\.(?:jpe?g|png|webp|gif|svg|avif|bmp|tiff?)\z/i', $relative)) throw new RuntimeException('An image path or format is outside supported backup coverage: ' . $relative);
            continue;
        }
        database_backup_media_image($path, $relative);
        if (count($files) >= DATABASE_BACKUP_MEDIA_FILES_MAX) throw new RuntimeException('Image backup exceeds the 10,000-file limit.');
        $files[$relative] = ['path' => $relative, 'bytes' => $entry->getSize(), 'sha256' => hash_file('sha256', $path)];
    }
    ksort($files, SORT_STRING);
    return array_values($files);
}

function database_backup_media_copy($source, $target, int $bytes): string
{
    $hash = hash_init('sha256');
    while ($bytes > 0) {
        $chunk = fread($source, min(65536, $bytes));
        if ($chunk === false || $chunk === '') throw new RuntimeException('A backup stream is incomplete.');
        $bytes -= strlen($chunk);
        hash_update($hash, $chunk);
        database_backup_write_all($target, $chunk);
    }
    return hash_final($hash);
}

/** Construct the SQL+images payload under the caller's exclusive operation lock. */
function database_backup_media_bundle(string $sql, string $target): int
{
    $files = database_backup_media_inventory();
    $manifest = ['sql_bytes' => filesize($sql), 'files' => $files];
    $json = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $total = 12 + strlen($json) + $manifest['sql_bytes'] + array_sum(array_column($files, 'bytes'));
    if (strlen($json) > DATABASE_BACKUP_MEDIA_MANIFEST_MAX || $total > database_backup_max_sql_bytes()) throw new RuntimeException('Database and images exceed BACKUP_MAX_UNCOMPRESSED_BYTES.');
    $output = fopen($target, 'wb');
    if ($output === false) throw new RuntimeException('Unable to prepare the image bundle.');
    try {
        database_backup_write_all($output, DATABASE_BACKUP_MEDIA_MAGIC . pack('N', strlen($json)) . $json);
        $source = fopen($sql, 'rb');
        if ($source === false) throw new RuntimeException('Unable to read SQL for the image bundle.');
        try { database_backup_media_copy($source, $output, $manifest['sql_bytes']); } finally { fclose($source); }
        foreach ($files as $file) {
            $path = database_backup_media_root() . '/' . $file['path'];
            database_backup_media_image($path, $file['path']);
            $source = fopen($path, 'rb');
            if ($source === false) throw new RuntimeException('Unable to read a backup image.');
            try { $copiedHash = database_backup_media_copy($source, $output, $file['bytes']); } finally { fclose($source); }
            if (!hash_equals($file['sha256'], $copiedHash)) throw new RuntimeException('An image changed during backup.');
        }
    } finally { fclose($output); }
    return $total;
}

function database_backup_media_read($stream, int $bytes): string
{
    $data = '';
    while (strlen($data) < $bytes) {
        $chunk = fread($stream, $bytes - strlen($data));
        if ($chunk === false || $chunk === '') throw new RuntimeException('The image bundle is truncated.');
        $data .= $chunk;
    }
    return $data;
}

/** Unpack to generated private filenames, never archive-supplied filesystem paths. */
function database_backup_media_unpack(array $metadata): array
{
    $directory = database_backup_directory(true) . '/.media-' . bin2hex(random_bytes(16));
    if (!mkdir($directory, 0700)) throw new RuntimeException('Unable to stage backup images.');
    $gzip = gzopen($metadata['_payload_temp'], 'rb');
    if ($gzip === false) { rmdir($directory); throw new RuntimeException('Unable to open the image bundle.'); }
    try {
        // fread on a gzip stream is unsupported; use a bounded decompressed file.
        $raw = fopen($directory . '/bundle', 'x+b');
        if ($raw === false) throw new RuntimeException('Unable to stage the image bundle.');
        chmod($directory . '/bundle', 0600);
        try {
            $expanded = 0;
            while (!gzeof($gzip)) {
                $chunk = gzread($gzip, 65536);
                if ($chunk === false || ($chunk === '' && !gzeof($gzip))) throw new RuntimeException('Invalid image bundle compression.');
                $expanded += strlen($chunk);
                if ($expanded > database_backup_max_sql_bytes()) throw new RuntimeException('Image bundle expands beyond the safety limit.');
                database_backup_write_all($raw, $chunk);
            }
            if ($expanded !== $metadata['uncompressed_bytes']) throw new RuntimeException('Image bundle expanded length mismatch.');
            rewind($raw);
            $prefix = database_backup_media_read($raw, 12);
            $length = unpack('Nlength', substr($prefix, 8))['length'];
            if (substr($prefix, 0, 8) !== DATABASE_BACKUP_MEDIA_MAGIC || $length < 2 || $length > DATABASE_BACKUP_MEDIA_MANIFEST_MAX) throw new RuntimeException('Invalid image bundle header.');
            $manifest = json_decode(database_backup_media_read($raw, $length), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($manifest) || array_keys($manifest) !== ['sql_bytes', 'files'] || !is_int($manifest['sql_bytes']) || $manifest['sql_bytes'] < 1 || !is_array($manifest['files']) || !array_is_list($manifest['files']) || count($manifest['files']) > DATABASE_BACKUP_MEDIA_FILES_MAX) throw new RuntimeException('Invalid image bundle manifest.');
            $expected = 12 + $length + $manifest['sql_bytes'];
            $seen = [];
            foreach ($manifest['files'] as $file) {
                if (!is_array($file) || array_keys($file) !== ['path','bytes','sha256'] || !is_string($file['path']) || !database_backup_media_path_valid($file['path']) || isset($seen[$file['path']]) || !is_int($file['bytes']) || $file['bytes'] < 1 || $file['bytes'] > DATABASE_BACKUP_MEDIA_FILE_MAX || !is_string($file['sha256']) || !preg_match('/\A[a-f0-9]{64}\z/D', $file['sha256'])) throw new RuntimeException('Invalid, duplicate or unsafe image bundle entry.');
                $seen[$file['path']] = true;
                $expected += $file['bytes'];
                if ($expected > database_backup_max_sql_bytes()) throw new RuntimeException('Image bundle exceeds the expanded limit.');
            }
            if ($expected !== $expanded) throw new RuntimeException('Image bundle entry lengths do not match.');
            $sql = gzopen($directory . '/sql.gz', 'wb6');
            if ($sql === false) throw new RuntimeException('Unable to stage SQL.');
            try {
                $remaining = $manifest['sql_bytes'];
                while ($remaining > 0) {
                    $chunk = database_backup_media_read($raw, min(65536, $remaining));
                    $remaining -= strlen($chunk);
                    if (gzwrite($sql, $chunk) !== strlen($chunk)) throw new RuntimeException('Unable to stage SQL.');
                }
            } finally { gzclose($sql); }
            chmod($directory . '/sql.gz', 0600);
            $restorableFiles = [];
            foreach ($manifest['files'] as $index => $file) {
                $path = $directory . '/image-' . $index;
                $output = fopen($path, 'xb');
                if ($output === false) throw new RuntimeException('Unable to stage an image.');
                chmod($path, 0600);
                try { database_backup_media_copy($raw, $output, $file['bytes']); } finally { fclose($output); }
                if (!hash_equals($file['sha256'], (string)hash_file('sha256', $path))) throw new RuntimeException('Image checksum mismatch.');
                if (database_backup_media_is_payment_qr($file['path'])) {
                    // Older format-2 archives may contain QR entries. Validate
                    // the safe name, length, and digest, but do not inspect or
                    // install them. Existing local QR files remain untouched.
                    if (!unlink($path)) throw new RuntimeException('Unable to discard a legacy payment QR image.');
                    continue;
                }
                database_backup_media_image($path, $file['path']);
                $file['_stage_index'] = $index;
                $restorableFiles[] = $file;
            }
        } finally { fclose($raw); }
        unlink($directory . '/bundle');
        $metadata['_media_directory'] = $directory;
        $metadata['_media_files'] = $restorableFiles;
        $metadata['_sql_temp'] = $directory . '/sql.gz';
        return $metadata;
    } catch (Throwable $error) {
        database_backup_media_cleanup($directory);
        throw $error;
    } finally { gzclose($gzip); }
}

function database_backup_media_cleanup(string $directory): void
{
    // Only generated flat staging directories in private backup storage.
    if (dirname($directory) !== database_backup_directory(true) || !preg_match('/\A\.media-[a-f0-9]{32}\z/D', basename($directory)) || is_link($directory)) throw new RuntimeException('Invalid image staging directory.');
    foreach (scandir($directory) ?: [] as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = $directory . '/' . $file;
        if (is_link($path) || !is_file($path) || !unlink($path)) throw new RuntimeException('Unable to clean private image staging.');
    }
    if (!rmdir($directory)) throw new RuntimeException('Unable to clean private image staging.');
}

/** Validate targets and test write/rename/unlink permissions before clearing DB. */
function database_backup_media_prepare(array $metadata): void
{
    if ($metadata['format'] !== 2) return; // Legacy restore intentionally leaves files alone.
    database_backup_media_inventory();
    $root = database_backup_media_root();
    if (!is_dir($root) && !mkdir($root, 0755, true)) throw new RuntimeException('Unable to create image storage.');
    $directories = [$root];
    foreach ($metadata['_media_files'] as $file) {
        $directory = dirname($root . '/' . $file['path']);
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0755)) || realpath($directory) !== $directory) throw new RuntimeException('Image restore directory is unsafe or unavailable.');
        $target = $root . '/' . $file['path'];
        if (file_exists($target)) database_backup_media_image($target, $file['path']);
        $directories[] = $directory;
    }
    foreach (database_backup_media_inventory() as $file) $directories[] = dirname($root . '/' . $file['path']);
    foreach (array_unique($directories) as $directory) {
        $probe = tempnam($directory, '.restore-probe-');
        if ($probe === false || dirname($probe) !== $directory) { if ($probe !== false) unlink($probe); throw new RuntimeException('Image restore directory is not writable.'); }
        $renamed = $probe . '-renamed';
        try { if (!rename($probe, $renamed) || !unlink($renamed)) throw new RuntimeException('Image restore directory permissions are insufficient.'); }
        finally { if (is_file($probe)) unlink($probe); if (is_file($renamed)) unlink($renamed); }
    }
}

/** Caller keeps maintenance active until both database and media are verified. */
function database_backup_media_install(array $metadata): void
{
    if ($metadata['format'] !== 2) return;
    database_backup_media_prepare($metadata);
    $root = database_backup_media_root();
    $keep = [];
    foreach ($metadata['_media_files'] as $file) {
        $target = $root . '/' . $file['path'];
        $temp = tempnam(dirname($target), '.restore-image-');
        if ($temp === false || dirname($temp) !== dirname($target)) { if ($temp !== false) unlink($temp); throw new RuntimeException('Unable to stage a restored image.'); }
        try {
            $stageIndex = $file['_stage_index'] ?? null;
            if (!is_int($stageIndex) || !copy($metadata['_media_directory'] . '/image-' . $stageIndex, $temp) || !chmod($temp, 0644) || !hash_equals($file['sha256'], (string)hash_file('sha256', $temp)) || !rename($temp, $target)) throw new RuntimeException('Unable to install a restored image.');
        } finally { if (is_file($temp)) unlink($temp); }
        unset($file['_stage_index']);
        $keep[$file['path']] = $file;
    }
    foreach (database_backup_media_inventory() as $file) {
        if (!isset($keep[$file['path']]) && !unlink($root . '/' . $file['path'])) throw new RuntimeException('Unable to remove an image absent from the snapshot.');
    }
    if (database_backup_media_inventory() !== array_values($keep)) throw new RuntimeException('Restored image inventory does not match the snapshot.');
}
