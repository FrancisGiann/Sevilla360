<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/request_context.php';
require_once __DIR__ . '/../../includes/media_helper.php';
require_once __DIR__ . '/../../includes/media_upload_validation.php';

final class MediaUploadException extends RuntimeException
{
    private int $statusCode;

    public function __construct(string $message, int $statusCode = 422)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}

function media_upload_config_limit(string $name, int $fallback): int
{
    $value = $_ENV[$name] ?? getenv($name);
    if (!is_scalar($value) || !preg_match('/^[1-9][0-9]*$/', (string)$value)) {
        return $fallback;
    }

    $limit = (int)$value;
    return $limit > 0 ? $limit : $fallback;
}

function media_upload_ini_bytes(string $value): int
{
    $value = trim($value);
    if (!preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([kmgt])?$/i', $value, $matches)) {
        return 0;
    }

    $multipliers = ['k' => 1024, 'm' => 1048576, 'g' => 1073741824, 't' => 1099511627776];
    $unit = strtolower($matches[2] ?? '');
    return (int)((float)$matches[1] * ($multipliers[$unit] ?? 1));
}

function media_upload_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
}

function media_upload_error(int $uploadError): MediaUploadException
{
    switch ($uploadError) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return new MediaUploadException('The upload is too large for the server or application limit. Please choose a smaller image.', 413);
        case UPLOAD_ERR_PARTIAL:
            return new MediaUploadException('The upload was interrupted. Please try again.', 400);
        case UPLOAD_ERR_NO_FILE:
            return new MediaUploadException('No files were uploaded.', 400);
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
        case UPLOAD_ERR_EXTENSION:
            return new MediaUploadException('The server could not process the uploaded image. Please try again.', 500);
        default:
            return new MediaUploadException('The uploaded file could not be processed.', 422);
    }
}

// 1. Auth Guard: Only Super Admins manage CMS
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    media_upload_response(['success' => false, 'message' => 'Unauthorized access.'], 401);
    exit;
}

// ==========================================
// CSRF PROTECTION GUARD (JSON)
// ==========================================
$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $client_csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed. Unauthorized request.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // PHP drops the request body entirely when post_max_size is exceeded,
        // leaving no upload error entry to inspect.
        $content_length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        $post_max_bytes = media_upload_ini_bytes((string)ini_get('post_max_size'));
        if ($content_length > 0 && $post_max_bytes > 0 && $content_length > $post_max_bytes && empty($_POST) && empty($_FILES)) {
            throw new MediaUploadException('The upload is too large for the server request limit. Please choose a smaller image.', 413);
        }

        $media_type = isset($_POST['media_type']) && is_string($_POST['media_type']) ? trim($_POST['media_type']) : '';
        $website_slot = isset($_POST['website_slot']) && is_string($_POST['website_slot']) ? trim($_POST['website_slot']) : '';

        if ($media_type === '' || $website_slot === '') {
            throw new MediaUploadException('Missing media type or slot assignment.', 400);
        }

        $home_slots = [
            'home-hero' => 'standard',
            'home-about' => 'standard'
        ];
        $slot_classification = media_upload_classify_website_slot($website_slot);
        if ($slot_classification === null) {
            throw new MediaUploadException('The selected website slot is invalid.', 422);
        }
        $expected_media_type = $slot_classification['expected_media_type'];
        if ($media_type !== 'standard' && $media_type !== '360') {
            throw new MediaUploadException('The selected media type is invalid.', 422);
        }
        if ($expected_media_type !== null && $media_type !== $expected_media_type) {
            throw new MediaUploadException('The selected media type does not match the website slot.', 422);
        }

        $files = $_FILES['fileInput'] ?? null;
        $file_fields = ['name', 'type', 'tmp_name', 'error', 'size'];
        if (!is_array($files)) {
            throw new MediaUploadException('No files were uploaded.', 400);
        }
        foreach ($file_fields as $field) {
            if (!isset($files[$field]) || !is_array($files[$field])) {
                throw new MediaUploadException('Invalid upload payload.', 400);
            }
        }
        $uploaded_count = count($files['name']);
        if ($uploaded_count < 1) {
            throw new MediaUploadException('No files were uploaded.', 400);
        }

        // Homepage slots are one-to-one. Reject extra candidates instead of
        // silently discarding them after validation.
        $is_strict_slot = isset($home_slots[$website_slot]);
        if ($is_strict_slot && $uploaded_count > 1) {
            throw new MediaUploadException('You can only upload one image at a time for homepage slots.', 422);
        }

        // Ensure upload directory exists with least required permissions.
        $upload_dir = __DIR__ . '/../../assets/uploads/';
        if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
            throw new RuntimeException('Upload directory is unavailable.');
        }

    // =========================================================================
    // SECURITY FIX: STRICT MIME TYPE MAPPING
    // Map genuine binary MIME types to safe, forced extensions
    // =========================================================================
    $allowed_mimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];
    
    // Initialize PHP's secure File Information resource
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    
        // 48 MiB leaves a small multipart/request overhead margin below PHP's
        // normal 50M upload and post limits.
        $max_bytes = media_upload_config_limit('MEDIA_MAX_IMAGE_BYTES', 50331648);
        $max_pixels = media_upload_config_limit('MEDIA_MAX_IMAGE_PIXELS', 40000000);
        $max_width = media_upload_config_limit('MEDIA_MAX_IMAGE_WIDTH', 10000);
        $max_height = media_upload_config_limit('MEDIA_MAX_IMAGE_HEIGHT', 10000);

        // Validate every replacement candidate before touching existing DB rows
        // or media. A strict-slot replacement is all-or-nothing for its file.
        $validated = [];
        for ($i = 0; $i < $uploaded_count; $i++) {
            $upload_error = is_int($files['error'][$i] ?? null) ? $files['error'][$i] : (int)($files['error'][$i] ?? -1);
            if ($upload_error !== UPLOAD_ERR_OK) {
                throw media_upload_error($upload_error);
            }
            $tmp_path = $files['tmp_name'][$i] ?? null;
            if (!is_string($tmp_path) || $tmp_path === '' || !is_uploaded_file($tmp_path)) {
                throw new MediaUploadException('The uploaded file could not be processed.', 422);
            }
            $size = filesize($tmp_path);
            if ($size === false) {
                throw new MediaUploadException('The uploaded file could not be read.', 422);
            }
            if ($size > $max_bytes) {
                $max_mb = rtrim(rtrim(number_format($max_bytes / 1048576, 1, '.', ''), '0'), '.');
                throw new MediaUploadException("Image is too large. The maximum allowed size is {$max_mb} MB.", 413);
            }
            $dimensions = @getimagesize($tmp_path);
            $true_mime = $finfo->file($tmp_path);
            $width = isset($dimensions[0]) && is_int($dimensions[0]) ? $dimensions[0] : 0;
            $height = isset($dimensions[1]) && is_int($dimensions[1]) ? $dimensions[1] : 0;
            if (!is_string($true_mime) || !array_key_exists($true_mime, $allowed_mimes) || $dimensions === false || $width < 1 || $height < 1) {
                throw new MediaUploadException('Only valid JPG, PNG, or WEBP images are allowed.', 422);
            }
            if ($width > $max_width || $height > $max_height) {
                throw new MediaUploadException('Image dimensions exceed the configured limit.', 422);
            }
            if ($width > intdiv($max_pixels, $height)) {
                throw new MediaUploadException('Image pixel dimensions exceed the configured limit.', 422);
            }

            $safe_slot = preg_replace('/[^a-zA-Z0-9_-]/', '', $website_slot);
            if (!is_string($safe_slot) || $safe_slot === '') {
                throw new MediaUploadException('The selected website slot is invalid.', 422);
            }
            $new_filename = $safe_slot . '_' . bin2hex(random_bytes(8)) . '.' . $allowed_mimes[$true_mime];
            $validated[] = ['tmp' => $tmp_path, 'destination' => $upload_dir . $new_filename, 'filename' => $new_filename, 'db_path' => 'assets/uploads/' . $new_filename];
        }

        $new_files = []; $old_files = []; $successful_uploads = 0; $transaction_started = false; $commit_attempted = false; $committed = false;
        try {
        // Image conversion can take several seconds. Stage every file before
        // opening a database connection so the server cannot expire an idle
        // connection or transaction while derivatives are being generated.
        foreach ($validated as $file) {
            if (!move_uploaded_file($file['tmp'], $file['destination'])) throw new Exception('Failed to move uploaded image.');
            $new_files[] = $file['destination'];
            $new_files[] = media_cms_thumbnail_file_path($file['db_path']);
            media_cms_ensure_admin_thumbnail($file['db_path']);
            if ($website_slot === 'home-hero') {
                $new_files[] = media_cms_hero_derivative_file_path($file['db_path']);
                media_cms_ensure_hero_derivative($file['db_path']);
            }
            // Standard venue photos feed public cards, so keep their responsive
            // candidates ready at upload time. Panorama card previews are not
            // required by the public tour; the authenticated CMS preview route
            // creates those candidates lazily when an editor opens the gallery.
            // Avoid decoding each large equirectangular source three extra times
            // inside the upload request.
            if ($media_type === 'standard') {
                foreach (MEDIA_CMS_CARD_DERIVATIVE_WIDTHS as $width) {
                    $new_files[] = media_cms_card_derivative_file_path($file['db_path'], $width);
                }
                media_cms_ensure_card_derivatives($file['db_path']);
            }
        }

        // Connect only after CPU-heavy image work is complete. The marker makes
        // database connection failures throwable so staged files are cleaned up.
        if (!defined('SEVILLA_CMS_MEDIA_UPLOAD')) define('SEVILLA_CMS_MEDIA_UPLOAD', true);
        require_once __DIR__ . '/../../config/db_connect.php';
        if (!$conn->begin_transaction()) throw new Exception('Could not start upload transaction.');
        $transaction_started = true;
        $canonical_gallery_slot = $slot_classification['canonical_gallery_slot'];
        if ($canonical_gallery_slot !== null
            && !media_upload_canonical_slot_exists($conn, $canonical_gallery_slot)) {
            throw new MediaUploadException('The selected website slot is invalid.', 422);
        }
        $option_group_id = isset($slot_classification['option_group_id']) ? (int)$slot_classification['option_group_id'] : null;
        if ($option_group_id !== null) {
            if (!hotel_group_media_overrides_schema_ready($conn)) {
                throw new MediaUploadException('Room-option galleries are not available yet. Apply migration 032 and refresh this page.', 503);
            }
            if (!media_upload_option_slot_exists($conn, $option_group_id, $website_slot)) {
                throw new MediaUploadException('The selected room option is invalid or no longer has active inventory.', 422);
            }
        }

        // Keep old DB records until the transaction includes the new records;
        // old physical files are deleted only after commit below.
        if ($is_strict_slot) {
            $stmt_check = $conn->prepare("SELECT id, file_path FROM media_cms WHERE slot_assignment = ? FOR UPDATE");
            if (!$stmt_check) throw new Exception('Could not load existing media.');
            $stmt_check->bind_param('s', $website_slot); if (!$stmt_check->execute()) throw new Exception('Could not load existing media.');
            $res = $stmt_check->get_result();
            while ($old_media = $res->fetch_assoc()) {
                $old_path = media_cms_upload_file_path((string)$old_media['file_path']);
                $old_files[] = [
                    'id' => (int)$old_media['id'],
                    'path' => $old_path,
                    'thumbnail_path' => media_cms_thumbnail_file_path((string)$old_media['file_path']),
                    'hero_path' => $website_slot === 'home-hero'
                        ? media_cms_existing_hero_derivative_file_path((string)$old_media['file_path'])
                        : null,
                    'card_paths' => array_values(array_filter(array_map(
                        static fn(int $width): ?string => media_cms_existing_card_derivative_file_path((string)$old_media['file_path'], $width),
                        MEDIA_CMS_CARD_DERIVATIVE_WIDTHS
                    ), static fn(?string $path): bool => $path !== null)),
                ];
            }
            $stmt_del = $conn->prepare("DELETE FROM media_cms WHERE slot_assignment = ?");
            if (!$stmt_del) throw new Exception('Could not replace existing media records.');
            $stmt_del->bind_param('s', $website_slot); if (!$stmt_del->execute()) throw new Exception('Could not replace existing media records.');
        }
        foreach ($validated as $file) {
            $stmt_insert = $conn->prepare("INSERT INTO media_cms (file_name, file_path, media_type, slot_assignment) VALUES (?, ?, ?, ?)");
            if (!$stmt_insert) throw new Exception('Could not record uploaded media.');
            $stmt_insert->bind_param('ssss', $file['filename'], $file['db_path'], $media_type, $website_slot);
            if (!$stmt_insert->execute()) throw new Exception('Could not record uploaded media.');
            $successful_uploads++;
        }
        if ($successful_uploads < 1) throw new Exception('No valid files were uploaded.');
        if ($option_group_id !== null) {
            $overrideColumn = $media_type === '360' ? 'tour_media_override_enabled' : 'standard_media_override_enabled';
            $enableOverride = $conn->prepare("UPDATE hotel_room_groups SET {$overrideColumn} = 1 WHERE id = ?");
            if (!$enableOverride) throw new Exception('Could not enable this room option gallery.');
            $enableOverride->bind_param('i', $option_group_id);
            if (!$enableOverride->execute() || $enableOverride->affected_rows < 0) throw new Exception('Could not enable this room option gallery.');
            $enableOverride->close();
        }
        if (isset($_SESSION['user_id'])) {
            $log_user = (int)$_SESSION['user_id']; $log_module = 'Media CMS'; $log_action = "Uploaded $successful_uploads file(s) to slot: $website_slot"; $log_ip = request_client_ip();
            $audit_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, ?, ?, ?)");
            if (!$audit_stmt) throw new Exception('Could not record media audit entry.');
            $audit_stmt->bind_param('isss', $log_user, $log_module, $log_action, $log_ip); if (!$audit_stmt->execute()) throw new Exception('Could not record media audit entry.');
        }
        $commit_attempted = true;
        if (!$conn->commit()) throw new Exception('Could not commit uploaded media.');
        $committed = true; $transaction_started = false;
        $cleanup_failures = [];
        $upload_root = media_cms_upload_root();
        foreach ($old_files as $old) {
            foreach (array_merge([$old['path'], $old['thumbnail_path'], $old['hero_path'] ?? null], $old['card_paths'] ?? []) as $path) {
                if ($path === null) continue;
                if (!media_cms_unlink_safe($path, $upload_root)) $cleanup_failures[] = $path;
            }
        }
        if ($cleanup_failures) error_log('Media replacement committed but old-file cleanup failed: ' . implode(', ', $cleanup_failures));
        echo json_encode(['success' => true, 'message' => "Successfully uploaded $successful_uploads file(s)!", 'cleanup_warning' => $cleanup_failures ? 'Old media files were retained because physical cleanup failed.' : null]);
    } catch (Throwable $e) {
        if ($transaction_started && !$committed) {
            try {
                if (!$conn->rollback()) error_log('CMS media upload rollback failed.');
            } catch (Throwable $rollback_error) {
                error_log('CMS media upload rollback failed: ' . $rollback_error->getMessage());
            }
        }
        // If commit was attempted but the connection failed before its result
        // arrived, the DB outcome is unknown. Retain staged files rather than
        // risk deleting files referenced by a commit that reached the server.
        if (!$commit_attempted) {
            foreach ($new_files as $path) {
                try {
                    if (is_file($path)) @unlink($path);
                } catch (Throwable $cleanup_error) {
                    error_log('CMS media upload staged-file cleanup failed: ' . $cleanup_error->getMessage());
                }
            }
        }
        throw $e;
    }
    } catch (Throwable $e) {
        http_response_code($e instanceof MediaUploadException ? $e->statusCode() : 500);
        $message = $e instanceof MediaUploadException ? $e->getMessage() : 'Upload could not be completed. Please try again.';
        if (!$e instanceof MediaUploadException) {
            error_log('CMS media upload failed: ' . $e->getMessage());
        }
        echo json_encode(['success' => false, 'message' => $message]);
    }
} else {
    media_upload_response(['success' => false, 'message' => 'Invalid request method.'], 405);
}
?>
