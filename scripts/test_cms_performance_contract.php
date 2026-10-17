<?php
/** Regression contract for CMS image derivatives, public delivery, and file lifecycle. */
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$cms = $read('includes/admin-page/admin_cms.php');
$dashboard = $read('admin_dashboard.php');
$upload = $read('actions/admin/upload_media.php');
$previewEndpoint = $read('actions/admin/media_preview.php');
$delete = $read('actions/admin/delete_media.php');
$helper = $read('includes/media_helper.php');
$cmsJs = $read('assets/js/admin-page/admin_cms.js');
$hotspotJs = $read('assets/js/admin-page/admin_hotspots.js');
$backfill = $read('scripts/backfill_cms_thumbnails.php');
$uploadAccess = $read('assets/uploads/.htaccess');
$publicAccess = $read('.htaccess');
$gitignore = $read('.gitignore');
$landing = $read('index.php');
$landingJs = $read('assets/js/index.js');
$gitCommandSucceeds = static function (array $command) use ($root): bool {
    $process = proc_open($command, [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ], $pipes, $root, null, ['bypass_shell' => true]);
    return is_resource($process) && proc_close($process) === 0;
};
$uploadSourceIsIgnored = $gitCommandSucceeds([
    'git', 'check-ignore', '--no-index', '--quiet', '--', 'assets/uploads/.cms-contract-source.jpg',
]);
$uploadDerivativeIsIgnored = $gitCommandSucceeds([
    'git', 'check-ignore', '--no-index', '--quiet', '--', 'assets/uploads/.cms-card/' . str_repeat('a', 64) . '-800.webp',
]);
$fallbackIsTracked = $gitCommandSucceeds([
    'git', 'ls-files', '--error-unmatch', '--', 'assets/uploads/home-hero-expanded-pool.png',
]);

$checks = [
    'CMS query selects only needed fields once' => substr_count($cms, 'FROM media_cms') === 1
        && str_contains($cms, 'SELECT id, file_name, file_path, media_type, slot_assignment, is_primary,')
        && !str_contains($cms, 'SELECT * FROM media_cms'),
    'one gallery payload retains original paths and bounded derivative URLs' => str_contains($cms, 'window.galleryData =')
        && str_contains($cms, 'thumbnail_path')
        && !str_contains($cms, 'window.panoDataOrdered'),
    'CMS standard and panorama cards use cached responsive derivatives with a bounded thumbnail fallback' =>
        str_contains($helper, "media_cms_card_image_data(\$media['file_path']")
        && str_contains($helper, "in_array((\$media['media_type'] ?? ''), ['standard', '360'], true)")
        && str_contains($helper, "srcset=\"")
        && str_contains($cms, 'media_cms_card_image_markup('),
    'management thumbnails accept only deterministic WebP derivative paths' => str_contains($cmsJs, '\\.cms-thumbnails')
        && str_contains($cmsJs, '{64}\\.webp'),
    'panorama order derives from the exact source media set in browser code' => str_contains($hotspotJs, 'window.galleryData?.[source.slot]')
        && str_contains($hotspotJs, 'window.hotelSourcePanoData?.[source.slot]')
        && str_contains($hotspotJs, 'sourcePhotos.slice().filter(')
        && str_contains($hotspotJs, 'is_primary')
        && str_contains($hotspotJs, 'Number(left.id) - Number(right.id)'),
    'all initial CMS card image markup uses lazy async thumbnails with intrinsic dimensions' => str_contains($cms, 'media_cms_card_image_markup(')
        && str_contains($helper, 'loading="lazy"')
        && str_contains($helper, 'decoding="async"')
        && str_contains($helper, 'width="\' . $width . \'" height="\' . $height'),
    'CMS image and local asset URLs do not use time cache busters' => !str_contains($cms, '?v=')
        && !str_contains($cms, 'time()')
        && !preg_match('/(?:admin_cms|admin_hotspots|panorama-view-compat|hotspot-material|guide_tours)\.\w+\?v=<\?=\s*time\(\)/', $dashboard)
        && !str_contains($cmsJs, 'Date.now()'),
    'upload generates stable thumbnails and standard-card derivatives, tracking them for cleanup' => str_contains($upload, 'media_cms_ensure_admin_thumbnail($file[\'db_path\'])')
        && str_contains($upload, "'thumbnail_path' => media_cms_thumbnail_file_path")
        && str_contains($upload, 'media_cms_ensure_hero_derivative($file[\'db_path\'])')
        && str_contains($upload, "'hero_path' => \$website_slot === 'home-hero'")
        && str_contains($upload, 'media_cms_ensure_card_derivatives($file[\'db_path\'])')
        && str_contains($upload, "if (\$media_type === 'standard') {")
        && str_contains($upload, "'card_paths' => array_values(array_filter(array_map(")
        && strpos($upload, '$new_files[] = media_cms_thumbnail_file_path') < strpos($upload, 'media_cms_ensure_admin_thumbnail($file[\'db_path\'])')
        && str_contains($upload, 'foreach ($new_files as $path)')
        && strpos($upload, 'foreach ($old_files as $old)') > strpos($upload, '$conn->commit()'),
    '360 uploads defer public-card conversion to the authenticated lazy preview route' => !str_contains($upload, "in_array(\$media_type, ['standard', '360'], true)")
        && str_contains($previewEndpoint, "\$_SESSION['role'] !== 'admin'")
        && str_contains($previewEndpoint, "\$_SESSION['csrf_token']")
        && str_contains($previewEndpoint, 'media_cms_ensure_card_derivatives((string)$media[\'file_path\'])'),
    'CMS upload connects and starts its transaction only after CPU-heavy derivatives finish' => strpos($upload, "require_once __DIR__ . '/../../config/db_connect.php';") > max(
            strpos($upload, 'media_cms_ensure_admin_thumbnail($file[\'db_path\'])'),
            strpos($upload, 'media_cms_ensure_hero_derivative($file[\'db_path\'])'),
            strpos($upload, 'media_cms_ensure_card_derivatives($file[\'db_path\'])')
        )
        && strpos($upload, '$conn->begin_transaction()') > strpos($upload, "require_once __DIR__ . '/../../config/db_connect.php';")
        && str_contains($read('config/db_connect.php'), 'defined(\'SEVILLA_CMS_MEDIA_UPLOAD\')'),
    'upload UI distinguishes completed file transfer from server-side image processing' => str_contains($cms, 'id="upload-progress-label" role="status" aria-live="polite"')
        && str_contains($cms, 'role="progressbar" aria-label="File transfer"')
        && str_contains($cmsJs, 'xhr.upload.addEventListener("load"')
        && str_contains($cmsJs, 'Upload received. Processing ${uploadDescription} on the server')
        && str_contains($cmsJs, "progText.innerText = 'Sent'")
        && str_contains($cmsJs, 'server is processing images.'),
    'ambiguous gateway and post-transfer failures instruct editors to reconcile before retrying' => str_contains($cmsJs, 'xhr.status === 504 || xhr.status >= 500')
        && str_contains($cmsJs, 'check the gallery before retrying; the images may already be saved.'),
    'CMS upload preserves the original failure when rollback fails and retains staged files after an uncertain commit' => str_contains($upload, 'catch (Throwable $rollback_error)')
        && str_contains($upload, 'if (!$commit_attempted) {')
        && strpos($upload, 'if (!$commit_attempted) {') < strpos($upload, 'throw $e;')
        && str_contains($upload, '$commit_attempted = true;'),
    'delete removes deterministic derivatives after safe DB commit' => str_contains($delete, '$media[\'_thumbnail_path\'] = media_cms_thumbnail_file_path')
        && str_contains($delete, '$media[\'_hero_path\'] = media_cms_existing_hero_derivative_file_path')
        && str_contains($delete, '$media[\'_card_paths\'] = array_values(array_filter(array_map(')
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
        && !str_contains($landing, 'media_cms_ensure_hero_derivative('),
    'public card derivatives are bounded, cached, and exposed only when files validate' => str_contains($helper, 'assets/uploads/.cms-card/')
        && str_contains($helper, 'MEDIA_CMS_CARD_DERIVATIVE_WIDTHS = [480, 800, 1320]')
        && str_contains($helper, 'media_cms_existing_card_derivative($normalized, $width)')
        && str_contains($helper, 'MEDIA_CMS_CARD_DERIVATIVE_MAX_HEIGHT = 1600')
        && str_contains($helper, '(max-width: 767px) calc(100vw - 2rem)')
        && str_contains($helper, 'min(calc(57.5vw - 1.725rem), 41.5rem)')
        && !str_contains($landing, 'media_cms_ensure_card_derivatives('),
    'Apache exposes only existing regular card WebPs at generated widths before the dot-path deny' => str_contains($publicAccess, 'RewriteRule ^assets/uploads/\\.cms-card/[a-f0-9]{64}-(?:480|800|1320)\\.webp$ - [END]')
        && str_contains($publicAccess, "RewriteCond %{REQUEST_FILENAME} -f\nRewriteCond %{REQUEST_FILENAME} !-l\nRewriteRule ^assets/uploads/\\.cms-card/")
        && strpos($publicAccess, 'RewriteRule ^assets/uploads/\\.cms-card/') < strpos($publicAccess, 'RewriteRule (^|/)\\.(?!well-known(?:/|$)) - [F,L]'),
    'Hostinger deployment preservation covers source uploads and derivatives while retaining its tracked fallback' => str_contains($gitignore, "\n/assets/uploads/\n")
        && is_file($root . '/assets/uploads/home-hero-expanded-pool.png')
        && $uploadSourceIsIgnored
        && $uploadDerivativeIsIgnored
        && $fallbackIsTracked,
    'SSR and carousel cards use the same responsive source, candidates, and sizes' => str_contains($landing, "'card_image' => \$public_card_image(")
        && str_contains($landing, 'srcset=')
        && str_contains($landing, 'sizes=')
        && str_contains($landingJs, 'cardImage.srcset')
        && str_contains($landingJs, 'cardImage.sizes')
        && str_contains($landingJs, 'review.image.srcset.trim()')
        && str_contains($landingJs, "image.sizes = '(max-width: 767px) calc(100vw - 2rem), (max-width: 1024px) calc(50vw - 2.125rem), (max-width: 1175px) calc(33.333vw - 1.833rem), 23.25rem'")
        && str_contains($landingJs, 'image.removeAttribute(\'srcset\')')
        && !str_contains($landingJs, 'venue.thumbnails'),
    'uploads script execution guard works with PHP-FPM and allows only image extensions' => str_contains($uploadAccess, '<IfModule mod_php.c>')
        && str_contains($uploadAccess, 'php_flag engine off')
        && str_contains($uploadAccess, '<FilesMatch "\\.(jpg|jpeg|png|gif|webp)$">')
        && str_contains($uploadAccess, 'SetHandler None'),
    'backfill is idempotent and creates public candidates for standard and 360 CMS media' => str_contains($backfill, 'SELECT id, file_path, slot_assignment, media_type FROM media_cms')
        && str_contains($backfill, "WHERE media_type IN ('standard', '360') ORDER BY id ASC")
        && str_contains($backfill, "in_array('--cards-only'")
        && str_contains($backfill, 'media_cms_ensure_admin_thumbnail($sourcePath)')
        && str_contains($backfill, 'media_cms_ensure_hero_derivative($sourcePath)')
        && str_contains($backfill, "in_array((\$row['media_type'] ?? ''), ['standard', '360'], true)")
        && str_contains($backfill, 'media_cms_ensure_card_derivatives($sourcePath)')
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
    $cardPaths = [];
    $smallSourcePath = null;
    $smallCardPaths = [];
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
        $thumbnailFallbackMarkup = media_cms_card_image_markup([
            'media_type' => 'standard',
            'file_path' => $sourceRelative,
            'thumbnail_path' => $thumbnail['path'],
            'thumbnail_version' => (int)(@filemtime($thumbnailPath) ?: 0),
            'thumbnail_width' => $thumbnail['width'],
            'thumbnail_height' => $thumbnail['height'],
        ], 'Bounded fallback card');
        $panoramaCardMarkup = media_cms_card_image_markup([
            'media_type' => '360',
            'file_path' => $sourceRelative,
            'thumbnail_path' => $thumbnail['path'],
            'thumbnail_version' => (int)(@filemtime($thumbnailPath) ?: 0),
            'thumbnail_width' => $thumbnail['width'],
            'thumbnail_height' => $thumbnail['height'],
        ], 'Bounded panorama card');

        $sourceBytes = filesize($sourcePath);
        $sourceModifiedAt = filemtime($sourcePath);
        $missingCardImage = media_cms_card_image_data($sourceRelative);
        $assert($missingCardImage['src'] === $sourceRelative && $missingCardImage['srcset'] === ''
            && $missingCardImage['width'] === 1600 && $missingCardImage['height'] === 900,
            'missing derivatives fall back to the safe original with its actual dimensions');
        $cardDerivatives = media_cms_ensure_card_derivatives($sourceRelative);
        foreach (MEDIA_CMS_CARD_DERIVATIVE_WIDTHS as $index => $width) {
            $cardPath = media_cms_card_derivative_file_path($sourceRelative, $width);
            $cardPaths[] = $cardPath;
            $cardInfo = @getimagesize($cardPath);
            $assert(is_array($cardInfo) && ($cardInfo['mime'] ?? '') === 'image/webp'
                && (int)$cardInfo[0] === $width && (int)$cardInfo[1] <= MEDIA_CMS_CARD_DERIVATIVE_MAX_HEIGHT,
                'card derivative has its requested width and bounded WebP dimensions');
            $assert(isset($cardDerivatives[$index]) && $cardDerivatives[$index]['path'] === media_cms_card_derivative_relative_path($sourceRelative, $width),
                'card derivative path is deterministic');
        }
        $cardImage = media_cms_card_image_data($sourceRelative);
        $assert(count(explode(', ', $cardImage['srcset'])) === count(MEDIA_CMS_CARD_DERIVATIVE_WIDTHS)
            && $cardImage['src'] === $cardDerivatives[0]['url']
            && $cardImage['width'] === $cardDerivatives[2]['width']
            && $cardImage['height'] === $cardDerivatives[2]['height'],
            'card image data exposes validated candidates and actual intrinsic dimensions');
        $responsiveCardMarkup = media_cms_card_image_markup([
            'media_type' => 'standard',
            'file_path' => $sourceRelative,
            'thumbnail_path' => $thumbnail['path'],
            'thumbnail_version' => (int)(@filemtime($thumbnailPath) ?: 0),
            'thumbnail_width' => $thumbnail['width'],
            'thumbnail_height' => $thumbnail['height'],
        ], 'Responsive standard card');
        $assert(str_contains($responsiveCardMarkup, 'srcset=')
            && str_contains($responsiveCardMarkup, 'sizes="(max-width: 767px)')
            && str_contains($responsiveCardMarkup, $cardDerivatives[0]['url'])
            && str_contains($responsiveCardMarkup, 'width="' . $cardDerivatives[2]['width'] . '" height="' . $cardDerivatives[2]['height'] . '"'),
            'standard CMS grid card selects existing responsive derivatives with intrinsic source dimensions');
        $assert(!str_contains($thumbnailFallbackMarkup, 'srcset=')
            && str_contains($thumbnailFallbackMarkup, media_cms_thumbnail_url($thumbnail['path'], (int)(@filemtime($thumbnailPath) ?: 0)))
            && !str_contains($thumbnailFallbackMarkup, $sourceRelative),
            'standard CMS card without cached public derivatives falls back to its bounded admin thumbnail');
        $responsivePanoramaCardMarkup = media_cms_card_image_markup([
            'media_type' => '360',
            'file_path' => $sourceRelative,
            'thumbnail_path' => $thumbnail['path'],
            'thumbnail_version' => (int)(@filemtime($thumbnailPath) ?: 0),
            'thumbnail_width' => $thumbnail['width'],
            'thumbnail_height' => $thumbnail['height'],
        ], 'Responsive tour card');
        $assert(str_contains($responsivePanoramaCardMarkup, 'srcset=')
            && str_contains($responsivePanoramaCardMarkup, $cardDerivatives[0]['url'])
            && !str_contains($responsivePanoramaCardMarkup, $sourceRelative),
            '360 CMS grid card selects bounded cached derivatives instead of loading the full panorama');
        foreach ($cardDerivatives as $candidate) {
            $candidateFile = media_cms_upload_file_path($candidate['path'], true);
            $assert(is_file($candidateFile) && str_contains($cardImage['srcset'], $candidate['url'] . ' ' . $candidate['width'] . 'w'),
                'responsive source candidate points to an existing derivative with its real width descriptor');
        }
        $assert(media_cms_ensure_card_derivatives($sourceRelative) === $cardDerivatives,
            'card derivatives are deterministic and reusable without repeated conversion');
        clearstatcache(true, $cardPaths[1]);
        $sourceModifiedAt = filemtime($sourcePath);
        $assert(touch($cardPaths[1], max(1, $sourceModifiedAt - 10)), 'stale card derivative fixture can be created');
        clearstatcache(true, $cardPaths[1]);
        $assert(media_cms_existing_card_derivative($sourceRelative, MEDIA_CMS_CARD_DERIVATIVE_WIDTHS[1]) === null,
            'older card derivative is not exposed');
        $rebuiltCards = media_cms_ensure_card_derivatives($sourceRelative);
        clearstatcache(true, $cardPaths[1]);
        $assert(filemtime($cardPaths[1]) >= $sourceModifiedAt && $rebuiltCards[1]['size'] > 0,
            'stale card derivative is rebuilt before reuse');
        $assert(filesize($sourcePath) === $sourceBytes, 'card derivative generation leaves the original image unchanged');

        $smallName = '.cms-contract-small-' . bin2hex(random_bytes(8)) . '.png';
        $smallSourcePath = $uploadRoot . DIRECTORY_SEPARATOR . $smallName;
        $smallRelative = 'assets/uploads/' . $smallName;
        $smallProcess = proc_open([
            media_cms_thumbnail_binary(), '-size', '320x240', 'xc:#ccddee', $smallSourcePath,
        ], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $assert(is_resource($smallProcess) && proc_close($smallProcess) === 0, 'small source fixture image can be generated');
        $smallDerivatives = media_cms_ensure_card_derivatives($smallRelative);
        foreach (MEDIA_CMS_CARD_DERIVATIVE_WIDTHS as $width) $smallCardPaths[] = media_cms_card_derivative_file_path($smallRelative, $width);
        $smallCardImage = media_cms_card_image_data($smallRelative);
        $assert(count($smallDerivatives) === count(MEDIA_CMS_CARD_DERIVATIVE_WIDTHS)
            && count(explode(', ', $smallCardImage['srcset'])) === 1
            && $smallDerivatives[2]['width'] === 320
            && $smallCardImage['width'] === 320,
            'small sources are never enlarged and duplicate candidates are removed');
        $smallPanoramaMarkup = media_cms_card_image_markup([
            'media_type' => '360',
            'file_path' => $smallRelative,
            'thumbnail_path' => 'assets/img/placeholder.jpg',
            'source_available' => true,
        ], 'Small tour card');
        $assert(substr_count($smallPanoramaMarkup, ' 320w') === 1
            && !str_contains($smallPanoramaMarkup, ' 480w')
            && !str_contains($smallPanoramaMarkup, ' 800w')
            && !str_contains($smallPanoramaMarkup, ' 1320w'),
            'small panorama previews retain the source pixel width without inflated srcset descriptors');

        $invalidCardImage = media_cms_card_image_data('assets/uploads/missing-card-image.jpg');
        $assert($invalidCardImage['src'] === 'assets/img/placeholder.jpg' && $invalidCardImage['srcset'] === '',
            'missing or invalid media uses the placeholder without broken srcset URLs');
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
        $cardTraversalRejected = false;
        try {
            media_cms_ensure_card_derivatives('assets/uploads/../payment-qrs/not-a-cms-image.jpg');
        } catch (InvalidArgumentException) {
            $cardTraversalRejected = true;
        }
        $assert($cardTraversalRejected, 'card derivative rejects path traversal');

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
        echo "PASS|filesystem thumbnail, hero and card derivatives, responsive candidates, traversal, symlink, and fallback checks\n";
    } catch (Throwable $e) {
        fwrite(STDERR, 'FAIL|filesystem thumbnail checks: ' . $e->getMessage() . "\n");
        $failed++;
    } finally {
        if (is_string($thumbnailPath)) media_cms_unlink_safe($thumbnailPath, $uploadRoot);
        if (is_string($heroPath)) media_cms_unlink_safe($heroPath, $uploadRoot);
        foreach (array_merge($cardPaths, $smallCardPaths) as $cardPath) media_cms_unlink_safe($cardPath, $uploadRoot);
        media_cms_unlink_safe($sourcePath, $uploadRoot);
        if (is_string($smallSourcePath)) media_cms_unlink_safe($smallSourcePath, $uploadRoot);
        if (is_link($linkPath)) @unlink($linkPath);
        if (is_string($outsidePath)) @unlink($outsidePath);
    }
}
exit($failed === 0 ? 0 : 1);
