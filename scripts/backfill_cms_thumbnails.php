<?php
/** Idempotently create admin-card derivatives for media currently in media_cms. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/media_helper.php';

$result = $conn->query('SELECT id, file_path FROM media_cms ORDER BY id ASC');
if (!$result) {
    fwrite(STDERR, "Could not read CMS media rows.\n");
    exit(1);
}

$processed = 0;
$generated = 0;
$derivatives = [];
$failures = 0;

while ($row = $result->fetch_assoc()) {
    $processed++;
    $sourcePath = (string)$row['file_path'];
    try {
        $thumbnailRelative = media_cms_thumbnail_relative_path($sourcePath);
        $thumbnailPath = media_cms_thumbnail_file_path($sourcePath);
        $existingRegularFile = !is_link($thumbnailPath) && is_file($thumbnailPath);
        $beforeInfo = $existingRegularFile ? @getimagesize($thumbnailPath) : false;
        $beforeBytes = $existingRegularFile ? @filesize($thumbnailPath) : false;
        $wasValid = $beforeInfo !== false
            && ($beforeInfo['mime'] ?? '') === 'image/webp'
            && $beforeBytes !== false
            && $beforeBytes > 0
            && $beforeBytes <= 1048576
            && (int)$beforeInfo[0] <= MEDIA_CMS_THUMBNAIL_WIDTH
            && (int)$beforeInfo[1] <= MEDIA_CMS_THUMBNAIL_HEIGHT;

        $thumbnail = media_cms_ensure_admin_thumbnail($sourcePath);
        if (!$wasValid) $generated++;
        $derivatives[$thumbnailRelative] = (int)$thumbnail['size'];
    } catch (Throwable $e) {
        $failures++;
        fwrite(STDERR, 'Failed media row ' . (int)$row['id'] . ': ' . $e->getMessage() . "\n");
    }
}

$totalBytes = array_sum($derivatives);
echo json_encode([
    'media_rows' => $processed,
    'thumbnails_created_or_repaired' => $generated,
    'thumbnail_files' => count($derivatives),
    'thumbnail_bytes' => $totalBytes,
    'thumbnail_mib' => round($totalBytes / 1048576, 3),
    'failures' => $failures,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
exit($failures === 0 ? 0 : 1);
