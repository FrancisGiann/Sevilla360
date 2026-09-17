<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/rate_limit.php';
require_once __DIR__ . '/../../includes/receptionist_ai.php';
require_once __DIR__ . '/../../includes/receptionist_knowledge.php';

header('Content-Type: application/json; charset=UTF-8');

function receptionist_chat_request_id(): string
{
    try { return 'rc_' . bin2hex(random_bytes(8)); } catch (Throwable $error) { return 'rc_' . substr(hash('sha256', uniqid('', true)), 0, 16); }
}

function receptionist_chat_log(string $event, array $metadata = []): void
{
    $safe = ['event' => $event, 'request_id' => $metadata['request_id'] ?? null];
    foreach (['provider', 'model', 'fallback_class', 'latency_ms', 'attempt_count', 'prompt_bytes', 'response_bytes', 'action', 'http_status', 'curl_errno_category', 'finish_reason', 'truncated', 'blocked'] as $key) {
        if (array_key_exists($key, $metadata) && (is_scalar($metadata[$key]) || $metadata[$key] === null)) $safe[$key] = $metadata[$key];
    }
    error_log('receptionist_chat ' . json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function receptionist_chat_guided_copy(string $language, string $fallbackClass): string
{
    $variant = match (receptionist_ai_fallback_metadata($fallbackClass)['code']) {
        'busy' => 'busy', 'visit_limit' => 'limit', 'provider_timeout' => 'timeout',
        'network_error' => 'network', 'provider_unavailable' => 'unavailable', 'server_error' => 'server', default => 'default',
    };
    return receptionist_ai_guided_message($language, $variant);
}

function receptionist_chat_response(array $payload, int $status = 200): never
{
    if (!array_key_exists('request_id', $payload) && is_string($GLOBALS['receptionist_chat_request_id'] ?? null)) $payload['request_id'] = $GLOBALS['receptionist_chat_request_id'];
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function receptionist_chat_guided(string $language = 'en', ?string $message = null, string $fallbackClass = 'provider_unavailable', ?string $requestId = null) : never
{
    $message ??= receptionist_ai_guided_message($language);
    $metadata = receptionist_ai_fallback_metadata($fallbackClass);
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
        'fallback_code' => $metadata['code'],
        'retryable' => $metadata['retryable'],
        'request_id' => $requestId,
    ]);
}

function receptionist_chat_prepare_knowledge(mysqli $conn, array $answer, array $baseSlots, array $venueCatalog): array
{
    return receptionist_ai_prepare_knowledge($conn, $answer, $baseSlots, $venueCatalog);
}

function receptionist_chat_store_turn(array $slots, string $message, string $reply, int $count, array $history): void
{
    $allowed = array_flip(['intent', 'occasion', 'purpose', 'group_size', 'preference', 'start_date', 'end_date', 'active_venue_id', 'active_room_group_id']);
    $_SESSION['receptionist_ai_context'] = array_intersect_key($slots, $allowed);
    $_SESSION['receptionist_ai_message_count'] = $count + 1;
    $history[] = ['role' => 'user', 'content' => $message];
    $history[] = ['role' => 'assistant', 'content' => $reply];
    $_SESSION['receptionist_ai_history'] = array_slice($history, -16);
}

$requestId = receptionist_chat_request_id();
$GLOBALS['receptionist_chat_request_id'] = $requestId;
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
    $sessionSlots = [];
    if (is_array($_SESSION['receptionist_ai_context'] ?? null)) {
        try {
            $sessionSlots = receptionist_ai_validate_slots($conn, $_SESSION['receptionist_ai_context'], [], $venueCatalog);
        } catch (Throwable $sessionContextError) {
            unset($_SESSION['receptionist_ai_context']);
        }
    }
    $requestSlots = receptionist_ai_validate_slots($conn, $requestContext, [], $venueCatalog);
    $baseSeed = $sessionSlots;
    if (isset($requestSlots['intent'], $sessionSlots['intent']) && $requestSlots['intent'] !== $sessionSlots['intent']) $baseSeed = ['intent' => $requestSlots['intent']];
    $baseSlots = receptionist_ai_validate_slots($conn, $requestSlots, $baseSeed, $venueCatalog);
    $faqs = receptionist_faq_load($conn);

    try {
        if (!check_rate_limit($conn, 'receptionist_chat', 30, 10)) {
            receptionist_chat_log('fallback', ['request_id' => $requestId, 'fallback_class' => 'busy']);
            receptionist_chat_guided($language, receptionist_chat_guided_copy($language, 'busy'), 'busy', $requestId);
        }
    } catch (Throwable $rateError) {
        receptionist_chat_log('fallback', ['request_id' => $requestId, 'fallback_class' => 'server_error']);
        receptionist_chat_guided($language, receptionist_chat_guided_copy($language, 'server_error'), 'server_error', $requestId);
    }

    $count = (int)($_SESSION['receptionist_ai_message_count'] ?? 0);
    if ($count >= 25) {
        receptionist_chat_log('fallback', ['request_id' => $requestId, 'fallback_class' => 'visit_limit']);
        receptionist_chat_guided($language, receptionist_chat_guided_copy($language, 'visit_limit'), 'visit_limit', $requestId);
    }

    $history = is_array($_SESSION['receptionist_ai_history'] ?? null) ? $_SESSION['receptionist_ai_history'] : [];
    $shortlist = receptionist_ai_shortlist_faq($faqs, $message);
    $knowledgeRecords = receptionist_public_knowledge_records($conn, $faqs);
    $showSupportFaqCta = receptionist_knowledge_is_support_faq_request($message);
    $respondDeterministic = static function (array $answer, ?string $fallbackClass = null) use ($conn, $baseSlots, $venueCatalog, $message, $count, $history, $language, $showSupportFaqCta): never {
        $prepared = receptionist_chat_prepare_knowledge($conn, $answer, $baseSlots, $venueCatalog);
        $prepared['show_support_faq_cta'] = $showSupportFaqCta || ($prepared['show_support_faq_cta'] ?? false) === true;
        if ($fallbackClass !== null) {
            $metadata = receptionist_ai_fallback_metadata($fallbackClass);
            $prepared['fallback_code'] = $metadata['code'];
            $prepared['retryable'] = $metadata['retryable'];
        }
        receptionist_chat_store_turn($prepared['slots'], $message, (string)$prepared['reply'], $count, $history);
        receptionist_chat_response(['success' => true, 'mode' => 'knowledge', 'validated_slots' => $prepared['slots']] + $prepared);
    };
    $knowledgeAnswer = receptionist_knowledge_reply($knowledgeRecords, $message, $language, $baseSlots);
    if ($knowledgeAnswer !== null) $respondDeterministic($knowledgeAnswer);
    $provider = receptionist_ai_provider();
    if (!$provider) {
        receptionist_chat_log('fallback', ['request_id' => $requestId, 'provider' => receptionist_ai_sanitize_provider_error_code(receptionist_ai_env('AI_PROVIDER', 'openrouter')) ?? 'unknown', 'model' => receptionist_ai_sanitize_provider_error_code(receptionist_ai_env('AI_MODEL')) ?? 'unknown', 'fallback_class' => 'provider_unavailable']);
        receptionist_chat_guided($language, receptionist_chat_guided_copy($language, 'provider_unavailable'), 'provider_unavailable', $requestId);
    }
    $limits = receptionist_ai_limits();
    $safeContext = array_intersect_key($baseSlots, array_flip(['intent', 'occasion', 'purpose', 'group_size', 'preference', 'start_date', 'end_date', 'active_venue_id', 'active_room_group_id']));
    $knowledgeSelection = receptionist_knowledge_select($knowledgeRecords, $message, 8);
    $messages = [['role' => 'system', 'content' => receptionist_ai_system_prompt($shortlist, $safeContext, $language, $venueCatalog, $knowledgeSelection, $message)]];
    foreach (array_slice($history, -16) as $turn) {
        if (is_array($turn) && in_array($turn['role'] ?? '', ['user', 'assistant'], true) && is_string($turn['content'] ?? null)) $messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $message];
    $promptBytes = strlen((string)json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $started = microtime(true);
    try {
        $result = $provider->complete($messages, $limits['tokens'], $limits['timeout']);
    } catch (Throwable $providerError) {
        $latencyMs = (int)round((microtime(true) - $started) * 1000);
        receptionist_chat_log('fallback', ['request_id' => $requestId, 'provider' => receptionist_ai_sanitize_provider_error_code(receptionist_ai_env('AI_PROVIDER', 'openrouter')) ?? 'unknown', 'model' => receptionist_ai_sanitize_provider_error_code(receptionist_ai_env('AI_MODEL')) ?? 'unknown', 'fallback_class' => 'provider_unavailable', 'latency_ms' => $latencyMs, 'prompt_bytes' => $promptBytes]);
        $deterministicAnswer = receptionist_knowledge_reply($knowledgeRecords, $message, $language, $baseSlots);
        if ($deterministicAnswer !== null) $respondDeterministic($deterministicAnswer, 'provider_unavailable');
        receptionist_chat_guided($language, receptionist_chat_guided_copy($language, 'provider_unavailable'), 'provider_unavailable', $requestId);
    }
    $latencyMs = (int)round((microtime(true) - $started) * 1000);
    if (empty($result['success']) || !is_array($result['payload'] ?? null)) {
        $diagnostic = is_array($result['diagnostic'] ?? null) ? $result['diagnostic'] : [];
        $fallbackClass = (string)($result['error_class'] ?? 'provider_unavailable');
        receptionist_chat_log('fallback', ['request_id' => $requestId, 'provider' => receptionist_ai_sanitize_provider_error_code(receptionist_ai_env('AI_PROVIDER', 'openrouter')) ?? 'unknown', 'model' => receptionist_ai_sanitize_provider_error_code(receptionist_ai_env('AI_MODEL')) ?? 'unknown', 'fallback_class' => $fallbackClass, 'latency_ms' => $latencyMs, 'attempt_count' => $diagnostic['attempt_count'] ?? null, 'prompt_bytes' => $promptBytes, 'response_bytes' => $diagnostic['response_bytes'] ?? null, 'http_status' => $diagnostic['http_status'] ?? null, 'curl_errno_category' => $diagnostic['curl_errno_category'] ?? null, 'finish_reason' => $diagnostic['finish_reason'] ?? null, 'truncated' => $diagnostic['truncated'] ?? null, 'blocked' => $diagnostic['blocked'] ?? null]);
        $deterministicAnswer = receptionist_knowledge_reply($knowledgeRecords, $message, $language, $baseSlots);
        if ($deterministicAnswer !== null) $respondDeterministic($deterministicAnswer, $fallbackClass);
        receptionist_chat_guided($language, receptionist_chat_guided_copy($language, $fallbackClass), $fallbackClass, $requestId);
    }
    // Only a deterministic message shortlist may be selected by the model;
    // the returned answer is then copied from that approved FAQ item.
    try {
        $normalized = receptionist_ai_normalize_output($result['payload'], $conn, $baseSlots, $shortlist, $language, $venueCatalog);
    } catch (Throwable $providerSchemaError) {
        receptionist_chat_log('fallback', ['request_id' => $requestId, 'provider' => receptionist_ai_sanitize_provider_error_code(receptionist_ai_env('AI_PROVIDER', 'openrouter')) ?? 'unknown', 'model' => receptionist_ai_sanitize_provider_error_code(receptionist_ai_env('AI_MODEL')) ?? 'unknown', 'fallback_class' => 'provider_schema', 'latency_ms' => $latencyMs, 'prompt_bytes' => $promptBytes]);
        $deterministicAnswer = receptionist_knowledge_reply($knowledgeRecords, $message, $language, $baseSlots);
        if ($deterministicAnswer !== null) $respondDeterministic($deterministicAnswer, 'provider_schema');
        receptionist_chat_guided($language, receptionist_chat_guided_copy($language, 'provider_schema'), 'provider_schema', $requestId);
    }
    $normalized['show_support_faq_cta'] = $showSupportFaqCta;
    receptionist_chat_store_turn($normalized['slots'], $message, (string)$normalized['reply'], $count, $history);
    $diagnostic = is_array($result['diagnostic'] ?? null) ? $result['diagnostic'] : [];
    receptionist_chat_log('success', ['request_id' => $requestId, 'provider' => receptionist_ai_sanitize_provider_error_code(receptionist_ai_env('AI_PROVIDER', 'openrouter')) ?? 'unknown', 'model' => receptionist_ai_sanitize_provider_error_code(receptionist_ai_env('AI_MODEL')) ?? 'unknown', 'latency_ms' => $latencyMs, 'action' => $normalized['action'], 'attempt_count' => $diagnostic['attempt_count'] ?? null, 'prompt_bytes' => $promptBytes, 'response_bytes' => $diagnostic['response_bytes'] ?? null, 'finish_reason' => $diagnostic['finish_reason'] ?? null, 'truncated' => $diagnostic['truncated'] ?? null, 'blocked' => $diagnostic['blocked'] ?? null]);
    receptionist_chat_response(['success' => true, 'mode' => 'ai', 'validated_slots' => $normalized['slots']] + $normalized);
} catch (ReceptionistSensitiveInputException $error) {
    receptionist_chat_response(['success' => false, 'code' => 'sensitive_input', 'message' => receptionist_ai_privacy_message($language ?? 'en')], 422);
} catch (InvalidArgumentException $error) {
    $contextError = in_array($error->getMessage(), ['Invalid receptionist venue category.', 'Invalid receptionist preference.', 'Invalid guest count.', 'Guest count exceeds the active venue capacity.', 'Invalid date.', 'Checkout must be after check-in.', 'Invalid or mismatched venue.', 'Invalid hotel room identity.', 'Hotel room identity needs a venue.'], true);
    receptionist_chat_response(['success' => false, 'code' => $contextError ? 'invalid_context' : 'invalid_request', 'message' => $error->getMessage()], 422);
} catch (Throwable $error) {
    receptionist_chat_log('fallback', ['request_id' => $requestId, 'fallback_class' => 'server_error']);
    receptionist_chat_guided($language ?? 'en', receptionist_chat_guided_copy($language ?? 'en', 'server_error'), 'server_error', $requestId);
}
