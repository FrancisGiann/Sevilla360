<?php
/** Idempotently create admin-card thumbnails and the active homepage hero WebP. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/media_helper.php';

$result = $conn->query('SELECT id, file_path, slot_assignment FROM media_cms ORDER BY id ASC');
if (!$result) {
    fwrite(STDERR, "Could not read CMS media rows.\n");
    exit(1);
}

$processed = 0;
$generated = 0;
$heroGenerated = 0;
$heroBytes = 0;
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

        if (($row['slot_assignment'] ?? '') === 'home-hero') {
            $heroWasValid = media_cms_existing_hero_derivative_url($sourcePath) !== null;
            $heroUrl = media_cms_ensure_hero_derivative($sourcePath);
            $heroPath = media_cms_existing_hero_derivative_file_path($sourcePath);
            if ($heroUrl === null || $heroPath === null) throw new RuntimeException('Active hero derivative could not be created or validated.');
            if (!$heroWasValid) $heroGenerated++;
            $heroFileSize = @filesize($heroPath);
            if ($heroFileSize === false || $heroFileSize < 1) throw new RuntimeException('Active hero derivative size could not be read.');
            $heroBytes += (int)$heroFileSize;
        }
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
    'active_hero_derivatives_created_or_repaired' => $heroGenerated,
    'active_hero_derivative_bytes' => $heroBytes,
    'failures' => $failures,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
exit($failures === 0 ? 0 : 1);
