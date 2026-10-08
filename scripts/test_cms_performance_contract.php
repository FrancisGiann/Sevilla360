<?php
/** Regression contract for CMS thumbnailing, hero delivery, and file lifecycle. */
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$cms = $read('includes/admin-page/admin_cms.php');
$dashboard = $read('admin_dashboard.php');
$upload = $read('actions/admin/upload_media.php');
$delete = $read('actions/admin/delete_media.php');
$helper = $read('includes/media_helper.php');
$cmsJs = $read('assets/js/admin-page/admin_cms.js');
$hotspotJs = $read('assets/js/admin-page/admin_hotspots.js');
$backfill = $read('scripts/backfill_cms_thumbnails.php');
$uploadAccess = $read('assets/uploads/.htaccess');

$checks = [
    'CMS query selects only needed fields once' => substr_count($cms, 'FROM media_cms') === 1
        && str_contains($cms, 'SELECT id, file_name, file_path, media_type, slot_assignment, is_primary,')
        && !str_contains($cms, 'SELECT * FROM media_cms'),
    'one gallery payload retains original paths and bounded derivative URLs' => str_contains($cms, 'window.galleryData =')
        && str_contains($cms, 'thumbnail_path')
        && !str_contains($cms, 'window.panoDataOrdered'),
    'management thumbnails accept only deterministic WebP derivative paths' => str_contains($cmsJs, '\\.cms-thumbnails')
        && str_contains($cmsJs, '{64}\\.webp'),
    'panorama order derives from the same media set in browser code' => str_contains($hotspotJs, 'window.galleryData?.[currentSlot]')
        && str_contains($hotspotJs, 'is_primary')
        && str_contains($hotspotJs, '.slice().filter(')
        && str_contains($hotspotJs, 'Number(left.id) - Number(right.id)'),
    'all initial CMS card image markup uses lazy async thumbnails with intrinsic dimensions' => str_contains($cms, 'media_cms_card_image_markup(')
        && str_contains($helper, 'loading="lazy"')
        && str_contains($helper, 'decoding="async"')
        && str_contains($helper, 'width="\' . $width . \'" height="\' . $height'),
    'CMS image and local asset URLs do not use time cache busters' => !str_contains($cms, '?v=')
        && !str_contains($cms, 'time()')
        && !preg_match('/(?:admin_cms|admin_hotspots|panorama-view-compat|hotspot-material|guide_tours)\.\w+\?v=<\?=\s*time\(\)/', $dashboard)
        && !str_contains($cmsJs, 'Date.now()'),
    'upload requires derivative and tracks it for rollback/replacement cleanup' => str_contains($upload, 'media_cms_ensure_admin_thumbnail($file[\'db_path\'])')
        && str_contains($upload, "'thumbnail_path' => media_cms_thumbnail_file_path")
        && str_contains($upload, 'media_cms_ensure_hero_derivative($file[\'db_path\'])')
        && str_contains($upload, "'hero_path' => \$website_slot === 'home-hero'")
        && strpos($upload, '$new_files[] = media_cms_thumbnail_file_path') < strpos($upload, 'media_cms_ensure_admin_thumbnail($file[\'db_path\'])')
        && str_contains($upload, 'foreach ($new_files as $path)')
        && strpos($upload, 'foreach ($old_files as $old)') > strpos($upload, '$conn->commit()'),
    'delete removes deterministic derivatives after safe DB commit' => str_contains($delete, '$media[\'_thumbnail_path\'] = media_cms_thumbnail_file_path')
        && str_contains($delete, '$media[\'_hero_path\'] = media_cms_existing_hero_derivative_file_path')
        && strpos($delete, 'if (!$conn->commit())') < strpos($delete, 'media_cms_unlink_safe($physicalPath, $uploadRoot)')
        && str_contains($delete, 'media_cms_unlink_safe($physicalPath, $uploadRoot)'),
    'thumbnail helper bounds WebP dimensions and uses safe atomic ImageMagick execution' => str_contains($helper, 'MEDIA_CMS_THUMBNAIL_WIDTH = 480')
        && str_contains($helper, "'image/webp'")
        && str_contains($helper, 'proc_open($command')
        && str_contains($helper, 'media_cms_imagick_write_bounded_webp(')
        && str_contains($helper, 'Imagick::setResourceLimit($resource, $bounded)')
        && strpos($helper, '$sourceInfo = @getimagesize($source);') < strpos($helper, '$image->readImageFile($stream)')
        && str_contains($helper, '$sourceWidth > intdiv(40000000, $sourceHeight)')
        && str_contains($helper, '$orientationResult = $image->autoOrientImage()')
        && str_contains($helper, '$orientationResult === false')
        && str_contains($helper, '$image->stripImage()')
        && str_contains($helper, 'rename($temporaryPath, $thumbnailPath)')
        && str_contains($helper, 'media_cms_upload_file_path($normalized, true)'),
    'hero derivatives are separate, bounded, and generated outside public requests' => str_contains($helper, 'assets/uploads/.cms-hero/')
        && str_contains($helper, "'-resize', '1600x1000>'")
        && str_contains($helper, 'media_cms_existing_hero_derivative_url($normalized)')
        && !str_contains($read('index.php'), 'media_cms_ensure_hero_derivative('),
    'uploads script execution guard works with PHP-FPM and allows only image extensions' => str_contains($uploadAccess, '<IfModule mod_php.c>')
        && str_contains($uploadAccess, 'php_flag engine off')
        && str_contains($uploadAccess, '<FilesMatch "\\.(jpg|jpeg|png|gif|webp)$">')
        && str_contains($uploadAccess, 'SetHandler None'),
    'backfill is idempotent and selects only CMS media rows' => str_contains($backfill, 'SELECT id, file_path, slot_assignment FROM media_cms')
        && str_contains($backfill, 'media_cms_ensure_admin_thumbnail($sourcePath)')
        && str_contains($backfill, 'media_cms_ensure_hero_derivative($sourcePath)')
        && !str_contains($backfill, 'payment-qrs'),
];

$failed = 0;
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . "|$label\n";
    if (!$passed) $failed++;
}

if ($failed === 0) {
    require_once $root . '/includes/media_helper.php';
    $uploadRoot = media_cms_upload_root();
    $sourceName = '.cms-contract-' . bin2hex(random_bytes(8)) . '.png';
    $sourceRelative = 'assets/uploads/' . $sourceName;
    $sourcePath = $uploadRoot . DIRECTORY_SEPARATOR . $sourceName;
    $thumbnailPath = null;
    $heroPath = null;
    $outsidePath = tempnam(sys_get_temp_dir(), 'cms-thumb-outside-');
    $linkName = '.cms-contract-link-' . bin2hex(random_bytes(8)) . '.png';
    $linkPath = $uploadRoot . DIRECTORY_SEPARATOR . $linkName;
    try {
        $process = proc_open([
            media_cms_thumbnail_binary(), '-size', '1600x900', 'xc:#ccddee', $sourcePath,
        ], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $generatedSource = is_resource($process) && proc_close($process) === 0;
        $assert($generatedSource, 'contract fixture image can be generated');

        $thumbnail = media_cms_ensure_admin_thumbnail($sourceRelative);
        $thumbnailPath = media_cms_thumbnail_file_path($sourceRelative);
        $assert($thumbnail['width'] === MEDIA_CMS_THUMBNAIL_WIDTH && $thumbnail['height'] === MEDIA_CMS_THUMBNAIL_HEIGHT, 'thumbnail dimensions are bounded while preserving the 16:9 source ratio');
        $assert($thumbnail['size'] <= 1048576 && is_file($thumbnailPath), 'thumbnail output is bounded and exists');
        $repeat = media_cms_ensure_admin_thumbnail($sourceRelative);
        $assert($repeat['path'] === $thumbnail['path'], 'thumbnail path is deterministic and idempotent');
        $markup = media_cms_card_image_markup([
            'thumbnail_path' => $thumbnail['path'],
            'thumbnail_version' => (int)(@filemtime($thumbnailPath) ?: 0),
            'thumbnail_width' => $thumbnail['width'],
            'thumbnail_height' => $thumbnail['height'],
        ], 'A "safe" image');
        $assert(str_contains($markup, 'loading="lazy"') && str_contains($markup, 'decoding="async"') && str_contains($markup, 'alt="A &quot;safe&quot; image"'), 'card markup has lazy decoding, intrinsic dimensions, and escaped attributes');

        $sourceBytes = filesize($sourcePath);
        $sourceModifiedAt = filemtime($sourcePath);
        $heroUrl = media_cms_ensure_hero_derivative($sourceRelative);
        $heroPath = media_cms_existing_hero_derivative_file_path($sourceRelative);
        $heroInfo = $heroPath !== null ? @getimagesize($heroPath) : false;
        $assert($heroUrl !== null && $heroPath !== null && is_array($heroInfo), 'high-resolution hero derivative is generated and readable');
        $assert(($heroInfo['mime'] ?? '') === 'image/webp' && (int)$heroInfo[0] <= 1600 && (int)$heroInfo[1] <= 1000, 'hero derivative has bounded WebP dimensions');
        $assert(filesize($sourcePath) === $sourceBytes && filemtime($sourcePath) === $sourceModifiedAt, 'hero generation leaves the original CMS image unchanged');
        $assert(media_cms_ensure_hero_derivative($sourceRelative) === $heroUrl, 'hero derivative path and version are reusable');
        clearstatcache(true, $heroPath);
        $sourceModifiedAt = filemtime($sourcePath);
        $assert(touch($heroPath, max(1, $sourceModifiedAt - 10)), 'stale hero derivative fixture can be created');
        clearstatcache(true, $heroPath);
        $assert(media_cms_existing_hero_derivative_url($sourceRelative) === null, 'older hero derivative is rejected');
        $rebuiltHeroUrl = media_cms_ensure_hero_derivative($sourceRelative);
        clearstatcache(true, $heroPath);
        $assert($rebuiltHeroUrl !== null && filemtime($heroPath) >= $sourceModifiedAt, 'stale hero derivative is rebuilt before reuse');
        $heroTraversalRejected = media_cms_ensure_hero_derivative('assets/uploads/../payment-qrs/not-a-cms-image.jpg') === null;
        $assert($heroTraversalRejected, 'hero derivative rejects path traversal');

        $traversalRejected = false;
        try {
            media_cms_ensure_admin_thumbnail('assets/uploads/../payment-qrs/not-a-cms-image.jpg');
        } catch (InvalidArgumentException) {
            $traversalRejected = true;
        }
        $assert($traversalRejected, 'path traversal is rejected');

        if (is_string($outsidePath) && symlink($outsidePath, $linkPath)) {
            $symlinkRejected = false;
            try {
                media_cms_ensure_admin_thumbnail('assets/uploads/' . $linkName);
            } catch (InvalidArgumentException) {
                $symlinkRejected = true;
            }
            $assert($symlinkRejected, 'symlink sources are rejected');
        } else {
            $assert(false, 'symlink fixture can be created');
        }
        echo "PASS|filesystem thumbnail, hero derivative, traversal, symlink, and markup checks\n";
    } catch (Throwable $e) {
        fwrite(STDERR, 'FAIL|filesystem thumbnail checks: ' . $e->getMessage() . "\n");
        $failed++;
    } finally {
        if (is_string($thumbnailPath)) media_cms_unlink_safe($thumbnailPath, $uploadRoot);
        if (is_string($heroPath)) media_cms_unlink_safe($heroPath, $uploadRoot);
        media_cms_unlink_safe($sourcePath, $uploadRoot);
        if (is_link($linkPath)) @unlink($linkPath);
        if (is_string($outsidePath)) @unlink($outsidePath);
    }
}
exit($failed === 0 ? 0 : 1);
