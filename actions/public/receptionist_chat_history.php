<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../includes/receptionist_ai.php';
require_once __DIR__ . '/../../includes/receptionist_natural.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');

function receptionist_chat_history_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') receptionist_chat_history_response(['success' => false], 405);
$contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') receptionist_chat_history_response(['success' => false], 415);
$sessionCsrf = $_SESSION['csrf_token'] ?? null;
$clientCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!is_string($sessionCsrf) || $sessionCsrf === '' || !is_string($clientCsrf) || $clientCsrf === '' || !hash_equals($sessionCsrf, $clientCsrf)) {
    receptionist_chat_history_response(['success' => false], 403);
}
$body = file_get_contents('php://input');
if (!is_string($body) || strlen($body) > 1000 || !is_array(json_decode($body, true))) {
    receptionist_chat_history_response(['success' => false], 400);
}

receptionist_ai_enforce_session_owner();
if (receptionist_natural_enabled()) {
    require_once __DIR__ . '/../../config/db_connect.php';
    $rawState = is_array($_SESSION['receptionist_natural_state'] ?? null) ? $_SESSION['receptionist_natural_state'] : [];
    $catalog = receptionist_ai_public_venue_catalog($conn);
    $state = receptionist_natural_state($rawState, $conn, $catalog);
    $_SESSION['receptionist_natural_state'] = $state;
    $history = receptionist_natural_bound_history(is_array($state['history'] ?? null) ? $state['history'] : []);
    receptionist_chat_history_response([
        'success' => true,
        'history' => $history,
        'revision' => max(0, (int)($state['revision'] ?? 0)),
        'natural_state' => receptionist_natural_public_state($state),
    ]);
}
$history = receptionist_ai_public_history(is_array($_SESSION['receptionist_ai_history'] ?? null) ? $_SESSION['receptionist_ai_history'] : []);
receptionist_chat_history_response(['success' => true, 'history' => $history]);
