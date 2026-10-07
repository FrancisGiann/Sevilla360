<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../includes/receptionist_ai.php';
require_once __DIR__ . '/../../includes/receptionist_natural.php';

header('Content-Type: application/json; charset=UTF-8');

function receptionist_chat_reset_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') receptionist_chat_reset_response(['success' => false, 'message' => 'POST is required.'], 405);
$contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') receptionist_chat_reset_response(['success' => false, 'message' => 'JSON requests are required.'], 415);
$sessionCsrf = $_SESSION['csrf_token'] ?? null;
$clientCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!is_string($sessionCsrf) || $sessionCsrf === '' || !is_string($clientCsrf) || $clientCsrf === '' || !hash_equals($sessionCsrf, $clientCsrf)) {
    receptionist_chat_reset_response(['success' => false, 'message' => 'CSRF validation failed.'], 403);
}

receptionist_ai_enforce_session_owner();
if (receptionist_natural_enabled()) {
    $rawBody = file_get_contents('php://input');
    $request = is_string($rawBody) && strlen($rawBody) <= 1000 ? json_decode($rawBody, true) : null;
    if (!is_array($request)) receptionist_chat_reset_response(['success' => false, 'message' => 'Invalid reset request.'], 400);
    $state = array_replace(receptionist_natural_empty_state(), is_array($_SESSION['receptionist_natural_state'] ?? null) ? $_SESSION['receptionist_natural_state'] : []);
    $revision = max(0, (int)$state['revision']);
    $expected = filter_var($request['expected_revision'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($expected === false || $expected !== $revision) {
        receptionist_chat_reset_response(['success' => false, 'code' => 'stale_state', 'revision' => $revision, 'natural_state' => receptionist_natural_public_state($state)], 409);
    }
    $fresh = receptionist_natural_empty_state();
    $fresh['revision'] = $revision + 1;
    $_SESSION['receptionist_natural_state'] = $fresh;
    unset($_SESSION['receptionist_ai_message_count'], $_SESSION['receptionist_ai_history'], $_SESSION['receptionist_ai_context'], $_SESSION['receptionist_ai_focus']);
    $_SESSION['receptionist_ai_owner'] = receptionist_ai_session_owner();
    receptionist_chat_reset_response(['success' => true, 'revision' => $fresh['revision'], 'natural_state' => receptionist_natural_public_state($fresh)]);
}

unset($_SESSION['receptionist_ai_message_count'], $_SESSION['receptionist_ai_history'], $_SESSION['receptionist_ai_context'], $_SESSION['receptionist_ai_focus']);
$_SESSION['receptionist_ai_owner'] = receptionist_ai_session_owner();
receptionist_chat_reset_response(['success' => true]);
