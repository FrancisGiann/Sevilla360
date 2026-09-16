<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/rate_limit.php';
require_once __DIR__ . '/../../includes/receptionist_ai.php';
require_once __DIR__ . '/../../includes/receptionist_knowledge.php';

header('Content-Type: application/json; charset=UTF-8');

function receptionist_chat_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function receptionist_chat_guided(string $language = 'en', ?string $message = null) : never
{
    $message ??= receptionist_ai_guided_message($language);
    receptionist_chat_response([
        'success' => true,
        'mode' => 'guided',
        'reply' => $message,
        'language' => in_array($language, ['en', 'fil', 'taglish'], true) ? $language : 'en',
        'action' => 'ask',
        'slots' => [],
        'validated_slots' => [],
        'missing_slots' => ['intent'],
        'quick_replies' => ['Event', 'Hotel', 'Villa', 'Support FAQs'],
        'faq_id' => null,
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') receptionist_chat_response(['success' => false, 'message' => 'POST is required.'], 405);
$contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') receptionist_chat_response(['success' => false, 'message' => 'JSON requests are required.'], 415);
$sessionCsrf = $_SESSION['csrf_token'] ?? null;
$clientCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!is_string($sessionCsrf) || $sessionCsrf === '' || !is_string($clientCsrf) || $clientCsrf === '' || !hash_equals($sessionCsrf, $clientCsrf)) {
    receptionist_chat_response(['success' => false, 'message' => 'CSRF validation failed.'], 403);
}
$body = file_get_contents('php://input');
if (is_string($body) && strlen($body) > 20000) receptionist_chat_response(['success' => false, 'message' => 'Chat request is too large.'], 413);
$request = is_string($body) ? json_decode($body, true) : null;
if (!is_array($request)) receptionist_chat_response(['success' => false, 'message' => 'Invalid chat request.'], 400);

try {
    $requestedLanguage = is_string($request['locale'] ?? null) ? strtolower(trim($request['locale'])) : 'auto';
    if (!in_array($requestedLanguage, ['auto', 'en', 'fil', 'taglish'], true)) throw new InvalidArgumentException('Invalid chat language.');
    $rawMessage = is_string($request['message'] ?? null) ? $request['message'] : '';
    $language = receptionist_ai_language($requestedLanguage, $rawMessage);
    $message = receptionist_ai_clean_message($rawMessage);
    $requestContext = is_array($request['context'] ?? null) ? $request['context'] : [];
    $venueCatalog = receptionist_ai_public_venue_catalog($conn);
    $baseSlots = receptionist_ai_validate_slots($conn, $requestContext, [], $venueCatalog);
    $faqs = receptionist_faq_load($conn);

    try {
        if (!check_rate_limit($conn, 'receptionist_chat', 30, 10)) receptionist_chat_guided($language, receptionist_ai_guided_message($language, 'busy'));
    } catch (Throwable $rateError) {
        error_log('receptionist_chat rate limit error ' . json_encode(['status' => 'error', 'error_class' => get_class($rateError)]));
        receptionist_chat_guided($language);
    }

    $count = (int)($_SESSION['receptionist_ai_message_count'] ?? 0);
    if ($count >= 25) receptionist_chat_guided($language, receptionist_ai_guided_message($language, 'limit'));

    $history = is_array($_SESSION['receptionist_ai_history'] ?? null) ? $_SESSION['receptionist_ai_history'] : [];
    $shortlist = receptionist_ai_shortlist_faq($faqs, $message);
    $knowledgeRecords = receptionist_public_knowledge_records($conn, $faqs);
    $knowledgeAnswer = receptionist_knowledge_reply($knowledgeRecords, $message, $language, $baseSlots);
    if ($knowledgeAnswer !== null) {
        $knowledgePatch = is_array($knowledgeAnswer['slots'] ?? null) ? $knowledgeAnswer['slots'] : [];
        $knowledgeValidationBase = $baseSlots;
        if (isset($knowledgePatch['intent'], $knowledgeValidationBase['intent']) && $knowledgePatch['intent'] !== $knowledgeValidationBase['intent']) {
            unset($knowledgeValidationBase['occasion'], $knowledgeValidationBase['purpose'], $knowledgeValidationBase['preference'], $knowledgeValidationBase['group_size'], $knowledgeValidationBase['start_date'], $knowledgeValidationBase['end_date'], $knowledgeValidationBase['active_venue_id'], $knowledgeValidationBase['active_room_group_id']);
        }
        $knowledgeAnswer['slots'] = receptionist_ai_validate_slots($conn, $knowledgePatch, $knowledgeValidationBase, $venueCatalog);
        $knowledgeAnswer['missing_slots'] = is_array($knowledgeAnswer['missing_slots'] ?? null) ? $knowledgeAnswer['missing_slots'] : [];
        $knowledgeAnswer['quick_replies'] = array_slice(array_values(array_filter($knowledgeAnswer['quick_replies'] ?? [], 'is_string')), 0, 4);
        $_SESSION['receptionist_ai_message_count'] = $count + 1;
        $history[] = ['role' => 'user', 'content' => $message];
        $history[] = ['role' => 'assistant', 'content' => $knowledgeAnswer['reply']];
        $_SESSION['receptionist_ai_history'] = array_slice($history, -16);
        receptionist_chat_response(['success' => true, 'mode' => 'knowledge', 'validated_slots' => $knowledgeAnswer['slots']] + $knowledgeAnswer);
    }
    $provider = receptionist_ai_provider();
    if (!$provider) receptionist_chat_guided($language);
    $limits = receptionist_ai_limits();
    $safeContext = array_intersect_key($baseSlots, array_flip(['intent', 'occasion', 'purpose', 'group_size', 'preference', 'start_date', 'end_date', 'active_venue_id', 'active_room_group_id']));
    $knowledgeSelection = receptionist_knowledge_select($knowledgeRecords, $message, 8);
    $messages = [['role' => 'system', 'content' => receptionist_ai_system_prompt($shortlist, $safeContext, $language, $venueCatalog, $knowledgeSelection)]];
    foreach (array_slice($history, -16) as $turn) {
        if (is_array($turn) && in_array($turn['role'] ?? '', ['user', 'assistant'], true) && is_string($turn['content'] ?? null)) $messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $message];
    $started = microtime(true);
    try {
        $result = $provider->complete($messages, $limits['tokens'], $limits['timeout']);
    } catch (Throwable $providerError) {
        error_log('receptionist_chat provider exception ' . json_encode(['status' => 'error', 'provider' => receptionist_ai_env('AI_PROVIDER', 'openrouter'), 'model' => receptionist_ai_env('AI_MODEL'), 'error_class' => get_class($providerError)]));
        receptionist_chat_guided($language);
    }
    $latencyMs = (int)round((microtime(true) - $started) * 1000);
    if (empty($result['success']) || !is_array($result['payload'] ?? null)) {
        $diagnostic = is_array($result['diagnostic'] ?? null) ? $result['diagnostic'] : [];
        error_log('receptionist_chat provider failure ' . json_encode(['status' => 'error', 'provider' => receptionist_ai_env('AI_PROVIDER', 'openrouter'), 'model' => receptionist_ai_env('AI_MODEL'), 'latency_ms' => $latencyMs, 'error_class' => $result['error_class'] ?? 'unknown', 'diagnostic' => $diagnostic]));
        receptionist_chat_guided($language);
    }
    // Only a deterministic message shortlist may be selected by the model;
    // the returned answer is then copied from that approved FAQ item.
    try {
        $normalized = receptionist_ai_normalize_output($result['payload'], $conn, $baseSlots, $shortlist, $language, $venueCatalog);
    } catch (Throwable $providerSchemaError) {
        error_log('receptionist_chat provider schema failure ' . json_encode(['status' => 'error', 'provider' => receptionist_ai_env('AI_PROVIDER', 'openrouter'), 'model' => receptionist_ai_env('AI_MODEL'), 'error_class' => get_class($providerSchemaError)]));
        receptionist_chat_guided($language);
    }
    $_SESSION['receptionist_ai_message_count'] = $count + 1;
    $history[] = ['role' => 'user', 'content' => $message];
    $history[] = ['role' => 'assistant', 'content' => $normalized['reply']];
    $_SESSION['receptionist_ai_history'] = array_slice($history, -16);
    error_log('receptionist_chat provider success ' . json_encode(['status' => 'success', 'provider' => receptionist_ai_env('AI_PROVIDER', 'openrouter'), 'model' => receptionist_ai_env('AI_MODEL'), 'latency_ms' => $latencyMs, 'action' => $normalized['action']]));
    receptionist_chat_response(['success' => true, 'mode' => 'ai', 'validated_slots' => $normalized['slots']] + $normalized);
} catch (ReceptionistSensitiveInputException $error) {
    receptionist_chat_response(['success' => false, 'code' => 'sensitive_input', 'message' => receptionist_ai_privacy_message($language ?? 'en')], 422);
} catch (InvalidArgumentException $error) {
    $contextError = in_array($error->getMessage(), ['Invalid receptionist venue category.', 'Invalid receptionist preference.', 'Invalid guest count.', 'Guest count exceeds the active venue capacity.', 'Invalid date.', 'Checkout must be after check-in.', 'Invalid or mismatched venue.', 'Invalid hotel room identity.', 'Hotel room identity needs a venue.'], true);
    receptionist_chat_response(['success' => false, 'code' => $contextError ? 'invalid_context' : 'invalid_request', 'message' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('receptionist_chat failure ' . json_encode(['status' => 'error', 'error_class' => get_class($error)]));
    receptionist_chat_guided($language ?? 'en');
}
