<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../includes/receptionist_ai.php';

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

unset($_SESSION['receptionist_ai_message_count'], $_SESSION['receptionist_ai_history'], $_SESSION['receptionist_ai_context']);
$_SESSION['receptionist_ai_owner'] = receptionist_ai_session_owner();
receptionist_chat_reset_response(['success' => true]);
