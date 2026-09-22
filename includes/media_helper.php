<?php
declare(strict_types=1);

/**
 * SEVILLA360 - CMS Media Helper
 * 
 * Resolves the CMS image URL for a venue/room display name.
 * Uses the exact normalization convention from admin_cms.php so slot keys match.
 * 
 * Slot naming convention:
 *   Event halls / villas:  venue_<normalized venue name>
 *   Hotel rooms:           venue_<normalized "Building Name - Room Type">
 * 
 * @param mysqli $conn       Active database connection
 * @param string $display_name  The human-readable name to look up
 * @return string            Relative file_path from media_cms, or placeholder
 */

const MEDIA_CMS_THUMBNAIL_WIDTH = 480;
const MEDIA_CMS_THUMBNAIL_HEIGHT = 270;

/** Return the canonical assets/uploads root used by CMS media. */
function media_cms_upload_root(): string
{
    static $root = null;
    if ($root !== null) return $root;

    $configuredRoot = dirname(__DIR__) . '/assets/uploads';
    if (is_link($configuredRoot)) {
        throw new RuntimeException('CMS media upload directory is invalid.');
    }
    $resolved = realpath($configuredRoot);
    if ($resolved === false || !is_dir($resolved)) {
        throw new RuntimeException('CMS media upload directory is unavailable.');
    }
    $root = rtrim($resolved, DIRECTORY_SEPARATOR);
    return $root;
}

/** Normalize a DB media path and reject traversal, absolute paths, and null bytes. */
function media_cms_upload_relative_path(string $relativePath): string
{
    $normalized = str_replace('\\', '/', trim($relativePath));
    if (!str_starts_with($normalized, 'assets/uploads/') || str_contains($normalized, "\0")) {
        throw new InvalidArgumentException('CMS media path is invalid.');
    }

    $relative = substr($normalized, strlen('assets/uploads/'));
    if ($relative === '' || str_starts_with($relative, '/') || preg_match('~(?:^|/)\.\.?(?:/|$)~', $relative) === 1) {
        throw new InvalidArgumentException('CMS media path is invalid.');
    }

    $parts = explode('/', $relative);
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            throw new InvalidArgumentException('CMS media path is invalid.');
        }
    }
    return 'assets/uploads/' . implode('/', $parts);
}

/**
 * Resolve a CMS source path under assets/uploads. Existing files must be regular
 * files with no symlink hop; missing files are returned only for post-transaction
 * cleanup where the DB row is already being retired.
 */
function media_cms_upload_file_path(string $relativePath, bool $requireFile = false): string
{
    $normalized = media_cms_upload_relative_path($relativePath);
    $relative = substr($normalized, strlen('assets/uploads/'));
    $root = media_cms_upload_root();
    $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

    $walk = $root;
    foreach (explode('/', $relative) as $segment) {
        $walk .= DIRECTORY_SEPARATOR . $segment;
        if (is_link($walk)) {
            throw new InvalidArgumentException('CMS media path is invalid.');
        }
    }

    if (!file_exists($candidate) && !is_link($candidate)) {
        $parent = realpath(dirname($candidate));
        if ($requireFile || $parent === false || ($parent !== $root && !str_starts_with($parent, $root . DIRECTORY_SEPARATOR))) {
            throw new InvalidArgumentException('CMS media path is invalid.');
        }
        return $candidate;
    }

    if (is_link($candidate) || !is_file($candidate)) {
        throw new InvalidArgumentException('CMS media path is invalid.');
    }
    $resolved = realpath($candidate);
    if ($resolved === false || $resolved !== $candidate || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
        throw new InvalidArgumentException('CMS media path is invalid.');
    }
    return $resolved;
}

/** Return the deterministic derivative path for a source media URL. */
function media_cms_thumbnail_relative_path(string $sourcePath): string
{
    $normalized = media_cms_upload_relative_path($sourcePath);
    $key = hash('sha256', $normalized);
    return 'assets/uploads/.cms-thumbnails/' . $key . '.webp';
}

/** Return the absolute derivative path, without requiring it to exist yet. */
function media_cms_thumbnail_file_path(string $sourcePath): string
{
    $relative = media_cms_thumbnail_relative_path($sourcePath);
    $root = media_cms_upload_root();
    $thumbnailDir = $root . DIRECTORY_SEPARATOR . '.cms-thumbnails';
    if (is_link($thumbnailDir) || (!is_dir($thumbnailDir) && !@mkdir($thumbnailDir, 0755, true) && !is_dir($thumbnailDir))) {
        throw new RuntimeException('CMS thumbnail directory is unavailable.');
    }
    $resolvedDir = realpath($thumbnailDir);
    if ($resolvedDir === false || $resolvedDir !== $thumbnailDir) {
        throw new RuntimeException('CMS thumbnail directory is invalid.');
    }
    return $thumbnailDir . DIRECTORY_SEPARATOR . basename($relative);
}

/** Return the safe public URL for a thumbnail with stable filemtime versioning. */
function media_cms_thumbnail_url(string $thumbnailRelativePath, int $version): string
{
    if ($thumbnailRelativePath === 'assets/img/placeholder.jpg') {
        return $thumbnailRelativePath;
    }
    if (preg_match('~\Aassets/uploads/\.cms-thumbnails/[a-f0-9]{64}\.webp\z~', $thumbnailRelativePath) !== 1) {
        throw new InvalidArgumentException('CMS thumbnail URL is invalid.');
    }
    $normalized = media_cms_upload_relative_path($thumbnailRelativePath);
    return $version > 0 ? $normalized . '?v=' . $version : $normalized;
}

/** Render an escaped, layout-stable image for a CMS card. */
function media_cms_card_image_markup(array $media, string $alt = ''): string
{
    $path = (string)($media['thumbnail_path'] ?? 'assets/img/placeholder.jpg');
    if ($path !== 'assets/img/placeholder.jpg' && preg_match('~\Aassets/uploads/\.cms-thumbnails/[a-f0-9]{64}\.webp\z~', $path) !== 1) {
        $path = 'assets/img/placeholder.jpg';
    } elseif ($path !== 'assets/img/placeholder.jpg') {
        $path = media_cms_thumbnail_url($path, (int)($media['thumbnail_version'] ?? 0));
    }
    $width = max(1, min(MEDIA_CMS_THUMBNAIL_WIDTH, (int)($media['thumbnail_width'] ?? MEDIA_CMS_THUMBNAIL_WIDTH)));
    $height = max(1, min(MEDIA_CMS_THUMBNAIL_HEIGHT, (int)($media['thumbnail_height'] ?? MEDIA_CMS_THUMBNAIL_HEIGHT)));
    $safe = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<img src="' . $safe($path) . '" alt="' . $safe($alt) . '" width="' . $width . '" height="' . $height . '" loading="lazy" decoding="async">';
}

/** Locate the fixed ImageMagick binary without consulting a user-controlled PATH. */
function media_cms_thumbnail_binary(): string
{
    static $binary = null;
    if ($binary !== null) return $binary;

    foreach (['/usr/bin/magick', '/usr/bin/convert'] as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            $binary = $candidate;
            return $binary;
        }
    }
    throw new RuntimeException('Image processing is unavailable.');
}

/**
 * Create or reuse a bounded WebP derivative for an admin card. ImageMagick is
 * launched with an argument array, so media paths never pass through a shell.
 */
function media_cms_ensure_admin_thumbnail(string $sourcePath): array
{
    $normalized = media_cms_upload_relative_path($sourcePath);
    $source = media_cms_upload_file_path($normalized, true);
    $sourceInfo = @getimagesize($source);
    $sourceMime = is_array($sourceInfo) ? (string)($sourceInfo['mime'] ?? '') : '';
    $sourceWidth = (int)($sourceInfo[0] ?? 0);
    $sourceHeight = (int)($sourceInfo[1] ?? 0);
    if (!in_array($sourceMime, ['image/jpeg', 'image/png', 'image/webp'], true)
        || $sourceWidth < 1 || $sourceHeight < 1
        || $sourceWidth > 10000 || $sourceHeight > 10000
        || $sourceWidth > intdiv(40000000, $sourceHeight)) {
        throw new RuntimeException('CMS source is not a supported image within the configured dimensions.');
    }
    $thumbnailPath = media_cms_thumbnail_file_path($normalized);
    $thumbnailRelative = media_cms_thumbnail_relative_path($normalized);

    $validThumbnail = static function (string $path): ?array {
        if (is_link($path) || !is_file($path)) return null;
        $info = @getimagesize($path);
        $size = @filesize($path);
        if ($info === false || ($info['mime'] ?? '') !== 'image/webp' || $size === false || $size < 1 || $size > 1048576) return null;
        $width = (int)($info[0] ?? 0);
        $height = (int)($info[1] ?? 0);
        if ($width < 1 || $height < 1 || $width > MEDIA_CMS_THUMBNAIL_WIDTH || $height > MEDIA_CMS_THUMBNAIL_HEIGHT) return null;
        $resolved = realpath($path);
        if ($resolved === false || $resolved !== $path) return null;
        return ['width' => $width, 'height' => $height, 'size' => (int)$size];
    };

    $existing = $validThumbnail($thumbnailPath);
    if ($existing !== null) {
        return ['path' => $thumbnailRelative] + $existing;
    }

    $temporaryPath = $thumbnailPath . '.tmp-' . bin2hex(random_bytes(8)) . '.webp';
    $pipes = [];
    try {
        $command = [
            media_cms_thumbnail_binary(),
            '-limit', 'memory', '128MiB',
            '-limit', 'map', '256MiB',
            '-limit', 'disk', '512MiB',
            '-limit', 'time', '60',
            $source,
            '-auto-orient',
            '-thumbnail', MEDIA_CMS_THUMBNAIL_WIDTH . 'x' . MEDIA_CMS_THUMBNAIL_HEIGHT . '>',
            '-strip',
            '-quality', '78',
            '-define', 'webp:method=6',
            $temporaryPath,
        ];
        $process = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ], $pipes, dirname($source));
        if (!is_resource($process)) throw new RuntimeException('Thumbnail generation could not start.');
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException('Thumbnail generation failed.');
        }

        $generated = $validThumbnail($temporaryPath);
        if ($generated === null) throw new RuntimeException('Generated thumbnail failed validation.');
        if (!rename($temporaryPath, $thumbnailPath)) throw new RuntimeException('Generated thumbnail could not be committed.');

        $committed = $validThumbnail($thumbnailPath);
        if ($committed === null) throw new RuntimeException('Committed thumbnail failed validation.');
        return ['path' => $thumbnailRelative] + $committed;
    } finally {
        if (is_file($temporaryPath) || is_link($temporaryPath)) @unlink($temporaryPath);
    }
}

/** Unlink a CMS source/thumbnail only if it remains a regular file in its root. */
function media_cms_unlink_safe(string $absolutePath, string $root): bool
{
    if (!is_file($absolutePath)) return true;
    if (is_link($absolutePath)) return false;
    $resolved = realpath($absolutePath);
    if ($resolved === false || $resolved !== $absolutePath || !str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
        return false;
    }
    return @unlink($resolved);
}

/** Build a CMS venue slot exactly as includes/admin-page/admin_cms.php does. */
function media_cms_venue_slot_key(string $display_name): string
{
    $safe_id = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $display_name));
    $safe_id = trim($safe_id, '_');
    return 'venue_' . $safe_id;
}

/**
 * Resolve a hotel's media slot: a saved explicit mapping wins, otherwise use
 * the legacy CMS convention with the exact physical room-type label retained
 * by the group's active inventory. This never infers a type/tier from names.
 */
function hotel_room_group_media_slot_key(array $group): ?string
{
    $explicit = trim((string)($group['media_slot_key'] ?? ''));
    if ($explicit !== '') {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,79}$/', $explicit) === 1 ? $explicit : null;
    }

    $building = trim((string)($group['building_name'] ?? ''));
    $roomType = trim((string)($group['legacy_media_room_type'] ?? ''));
    if ($roomType === '') $roomType = trim((string)($group['legacy_room_type'] ?? ''));
    if ($roomType === '') $roomType = trim((string)($group['room_type'] ?? ''));
    if ($building === '' || $roomType === '') return null;
    return media_cms_venue_slot_key($building . ' - ' . $roomType);
}

/** Resolve homepage media without excluding inventory that has no photo. */
function hotel_room_group_public_images(array $group, array $mediaBySlot): array
{
    $slotKey = hotel_room_group_media_slot_key($group);
    $images = $slotKey !== null && isset($mediaBySlot[$slotKey]) && is_array($mediaBySlot[$slotKey])
        ? $mediaBySlot[$slotKey]
        : [];
    $images = array_values(array_filter(array_map(static fn($path): string => trim((string)$path), $images)));
    return $images ?: ['assets/img/placeholder.jpg'];
}

function get_venue_image(mysqli $conn, string $display_name): string {
    $slot_key = media_cms_venue_slot_key($display_name);

    $stmt = $conn->prepare(
        "SELECT file_path FROM media_cms 
         WHERE slot_assignment = ? AND media_type = 'standard' 
         ORDER BY is_primary DESC, id DESC 
         LIMIT 1"
    );
    if (!$stmt) return 'assets/img/placeholder.jpg';

    $stmt->bind_param('s', $slot_key);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['file_path'];
    }

    return 'assets/img/placeholder.jpg';
}

/** Resolve a room-group image through its explicit mapping or exact legacy CMS slot. */
function get_hotel_room_group_image(mysqli $conn, int $room_group_id): string {
    if ($room_group_id < 1) return 'assets/img/placeholder.jpg';
    $groupStmt = $conn->prepare("SELECT g.media_slot_key, g.building_name, g.legacy_room_type,
            COALESCE(NULLIF(t.display_name, ''), g.legacy_room_type) AS room_type,
            (SELECT CASE WHEN COUNT(DISTINCT h.room_type) = 1 THEN MIN(h.room_type) ELSE NULL END
             FROM hotel_rooms h
             INNER JOIN venues v ON v.id = h.venue_id AND v.category = 'Hotel Room' AND v.status = 'Available'
             WHERE h.room_group_id = g.id) AS legacy_media_room_type
        FROM hotel_room_groups g
        LEFT JOIN hotel_room_types t ON t.type_code = g.room_type_code
        WHERE g.id = ? LIMIT 1");
    if (!$groupStmt) return 'assets/img/placeholder.jpg';
    $groupStmt->bind_param('i', $room_group_id);
    if (!$groupStmt->execute()) return 'assets/img/placeholder.jpg';
    $group = $groupStmt->get_result()->fetch_assoc();
    $groupStmt->close();
    if (!is_array($group)) return 'assets/img/placeholder.jpg';
    $slotKey = hotel_room_group_media_slot_key($group);
    if ($slotKey === null) return 'assets/img/placeholder.jpg';

    $stmt = $conn->prepare("SELECT file_path FROM media_cms
        WHERE slot_assignment = ? AND media_type = 'standard'
        ORDER BY is_primary DESC, id DESC LIMIT 1");
    if (!$stmt) return 'assets/img/placeholder.jpg';
    $stmt->bind_param('s', $slotKey);
    if (!$stmt->execute()) return 'assets/img/placeholder.jpg';
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return is_array($row) && !empty($row['file_path']) ? (string)$row['file_path'] : 'assets/img/placeholder.jpg';
}
?>
