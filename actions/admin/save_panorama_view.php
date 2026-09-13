<?php
require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../includes/showroom_tour.php';
header('Content-Type: application/json; charset=utf-8');

function save_panorama_view_response(array $payload, int $status_code = 200): void
{
    http_response_code($status_code);
    echo json_encode($payload);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    save_panorama_view_response(['success' => false, 'message' => 'Use POST to save a panorama view.'], 405);
    exit;
}

$content_type = trim((string)($_SERVER['CONTENT_TYPE'] ?? ''));
if (preg_match('/\Aapplication\/json(?:\s*;|\s*\z)/i', $content_type) !== 1) {
    save_panorama_view_response(['success' => false, 'message' => 'Content-Type must be application/json.'], 415);
    exit;
}

if (!showroom_tour_is_admin(isset($_SESSION['role']) && is_string($_SESSION['role']) ? $_SESSION['role'] : null)) {
    save_panorama_view_response(['success' => false, 'message' => 'Administrator access is required.'], 401);
    exit;
}

$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$session_csrf_token = $_SESSION['csrf_token'] ?? null;
if (!is_string($session_csrf_token) || !is_string($client_csrf_token) || !hash_equals($session_csrf_token, $client_csrf_token)) {
    save_panorama_view_response(['success' => false, 'message' => 'CSRF validation failed.'], 403);
    exit;
}

try {
    $decoded = json_decode((string)file_get_contents('php://input'), false, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    save_panorama_view_response(['success' => false, 'message' => 'Request body must be valid JSON.'], 400);
    exit;
}

if (!$decoded instanceof stdClass) {
    save_panorama_view_response(['success' => false, 'message' => 'A JSON object is required.'], 400);
    exit;
}

$validation = null;
try {
    $validation = showroom_tour_validate_view_request($decoded);
} catch (InvalidArgumentException $e) {
    save_panorama_view_response(['success' => false, 'message' => $e->getMessage()], 422);
    exit;
}
$media_id = $validation['media_id'];
$view = $validation['view'];
$x = $view['x'] ?? null;
$y = $view['y'] ?? null;
$z = $view['z'] ?? null;
$fov = $view['fov'] ?? null;

try {
    require_once __DIR__ . '/../../config/db_connect.php';
    $lookup = $conn->prepare('SELECT media_type FROM media_cms WHERE id = ? LIMIT 1');
    if (!$lookup) throw new RuntimeException('Could not prepare panorama lookup.');
    $lookup->bind_param('i', $media_id);
    if (!$lookup->execute()) throw new RuntimeException('Could not verify panorama.');
    $media = $lookup->get_result()->fetch_assoc();
    if (!$media) {
        save_panorama_view_response(['success' => false, 'message' => 'Panorama was not found.'], 404);
        exit;
    }
    if (!showroom_tour_is_panorama((string)$media['media_type'])) {
        save_panorama_view_response(['success' => false, 'message' => 'Saved views are only available for 360 panoramas.'], 422);
        exit;
    }

    $save = $conn->prepare('UPDATE media_cms SET showroom_view_x = ?, showroom_view_y = ?, showroom_view_z = ?, showroom_fov = ? WHERE id = ? AND media_type = \'360\'');
    if (!$save) throw new RuntimeException('Could not prepare panorama view update.');
    $save->bind_param('ddddi', $x, $y, $z, $fov, $media_id);
    if (!$save->execute()) throw new RuntimeException('Could not save panorama view.');

    save_panorama_view_response([
        'success' => true,
        'message' => $view === null ? 'Default view cleared.' : 'Default view saved.',
        'view' => $view === null ? null : ['x' => $x, 'y' => $y, 'z' => $z, 'fov' => $fov],
    ]);
} catch (Throwable $e) {
    error_log('Panorama view save failed: ' . get_class($e));
    save_panorama_view_response(['success' => false, 'message' => 'The panorama view could not be saved.'], 500);
}
