<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json; charset=utf-8');

function media_preview_reply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    media_preview_reply(405, ['success' => false, 'message' => 'POST is required.']);
}
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    media_preview_reply(401, ['success' => false, 'message' => 'Unauthorized access.']);
}
$clientToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$clientToken)) {
    media_preview_reply(403, ['success' => false, 'message' => 'CSRF validation failed.']);
}

$request = json_decode((string)file_get_contents('php://input'), true);
$rawContext = is_array($request) ? ($request['context'] ?? null) : null;
$mediaId = filter_var(is_array($request) ? ($request['id'] ?? null) : null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$context = is_string($rawContext) ? $rawContext : '';
if (!$mediaId || !in_array($context, ['grid', 'hotel', 'manager'], true)) {
    media_preview_reply(422, ['success' => false, 'message' => 'A valid media preview is required.']);
}

session_write_close();
try {
    require_once __DIR__ . '/../../config/db_connect.php';
    require_once __DIR__ . '/../../includes/session_account_guard.php';
    if (!session_account_require_active($conn, ['admin'], static function (string $state): never { media_preview_reply($state === 'invalid' ? 401 : 503, ['success' => false, 'message' => $state === 'invalid' ? 'Unauthorized access.' : 'Account status could not be verified.']); })) exit;
    require_once __DIR__ . '/../../includes/media_helper.php';

    $stmt = $conn->prepare("SELECT file_path, media_type FROM media_cms WHERE id = ? AND media_type IN ('standard', '360') LIMIT 1");
    if (!$stmt) throw new RuntimeException('Preview media could not be loaded.');
    $stmt->bind_param('i', $mediaId);
    if (!$stmt->execute()) throw new RuntimeException('Preview media could not be loaded.');
    $media = $stmt->get_result()->fetch_assoc();
    if (!$media || media_cms_source_file_status((string)$media['file_path']) !== 'available') {
        media_preview_reply(404, ['success' => false, 'message' => 'The original image is missing or unavailable.']);
    }

    $sizes = match ($context) {
        'hotel' => '(max-width: 700px) calc(84vw - 2rem), (max-width: 1000px) calc(50vw - 4rem), 430px',
        'manager' => '(max-width: 700px) calc(100vw - 3rem), (max-width: 1000px) 50vw, 600px',
        default => '(max-width: 767px) calc(100vw - 2rem), (max-width: 1100px) calc(50vw - 2rem), 400px',
    };
    media_cms_ensure_card_derivatives((string)$media['file_path']);
    $preview = media_cms_card_image_data((string)$media['file_path'], $sizes);
    if ($preview['srcset'] === '') throw new RuntimeException('A responsive preview could not be generated.');

    media_preview_reply(200, [
        'success' => true,
        'id' => (int)$mediaId,
        'media_type' => (string)$media['media_type'],
        'src' => (string)$preview['src'],
        'srcset' => (string)$preview['srcset'],
        'sizes' => (string)$preview['sizes'],
        'width' => (int)$preview['width'],
        'height' => (int)$preview['height'],
    ]);
} catch (Throwable $error) {
    error_log('CMS responsive preview generation failed: ' . get_class($error));
    media_preview_reply(500, ['success' => false, 'message' => 'A sharper preview is not available yet. The existing thumbnail remains in place.']);
}
