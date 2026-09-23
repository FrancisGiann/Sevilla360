<?php
declare(strict_types=1);

require_once __DIR__ . '/receptionist_faq.php';

interface ReceptionistAiProviderInterface
{
    /** @return array{success:bool,payload?:array,error_class?:string,diagnostic?:array} */
    public function complete(array $messages, int $maxOutputTokens, int $timeoutSeconds): array;
}

final class ReceptionistSensitiveInputException extends InvalidArgumentException {}

function receptionist_ai_sanitize_provider_error_code($value): ?string
{
    if (!is_string($value)) return null;
    $value = trim($value);
    return preg_match('/\A[a-zA-Z0-9_.:-]{1,80}\z/', $value) === 1 ? $value : null;
}

function receptionist_ai_merge_slots(array $base, array $raw, array $resetKeys = []): array
{
    // Keep the legacy array_replace operation as the starting point for
    // compatibility, then undo null/empty model omissions. A reset is only
    // honored when explicitly requested by application code (for example,
    // Start over or a deliberate category change).
    $merged = array_replace($base, $raw);
    foreach ($raw as $key => $value) {
        if (($value === null || $value === '') && !in_array((string)$key, $resetKeys, true)) {
            if (array_key_exists($key, $base)) $merged[$key] = $base[$key];
            else unset($merged[$key]);
        }
    }
    foreach ($resetKeys as $key) {
        if (array_key_exists($key, $raw) && ($raw[$key] === null || $raw[$key] === '')) unset($merged[$key]);
    }
    return $merged;
}

function receptionist_ai_append_history(array $history, string $message, string $reply, bool $replace = false): array
{
    if ($replace) $history = [];
    $history[] = ['role' => 'user', 'content' => $message];
    $history[] = ['role' => 'assistant', 'content' => $reply];
    return array_slice($history, -16);
}

function receptionist_ai_session_owner(): string
{
    if (($_SESSION['logged_in'] ?? false) !== true) return 'guest';
    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $role = is_string($_SESSION['role'] ?? null) ? $_SESSION['role'] : '';
    if (!is_int($userId) || $userId < 1 || !in_array($role, ['customer', 'staff', 'admin'], true)) return 'guest';
    return 'authenticated:' . hash('sha256', $role . ':' . $userId);
}

function receptionist_ai_resolve_context(array $sessionSlots, array $requestSlots, ?string $messageIntent): array
{
    $sessionIntent = $sessionSlots['intent'] ?? null;
    $requestIntent = $requestSlots['intent'] ?? null;
    $validIntent = static fn($intent): bool => in_array($intent, ['Event Hall', 'Hotel Room', 'Resort Villa'], true);

    if ($validIntent($sessionIntent)) {
        if ($validIntent($messageIntent) && $messageIntent !== $sessionIntent) {
            // The current message explicitly switches flows. Extract new slots
            // from its text instead of inheriting stale browser form values.
            return ['request_slots' => [], 'base_slots' => []];
        }
        if ($validIntent($requestIntent) && $requestIntent !== $sessionIntent && $messageIntent !== $requestIntent) {
            // A stale showroom context must not replace the PHP chat session
            // when the visitor gives a follow-up without naming a new venue.
            return ['request_slots' => [], 'base_slots' => $sessionSlots];
        }
        return ['request_slots' => $requestSlots, 'base_slots' => $sessionSlots];
    }

    if ($validIntent($messageIntent) && $validIntent($requestIntent) && $messageIntent !== $requestIntent) {
        return ['request_slots' => [], 'base_slots' => []];
    }
    // Before this chat session has a category, an existing guided showroom
    // selection can seed the typed conversation.
    return ['request_slots' => $requestSlots, 'base_slots' => $requestSlots];
}

function receptionist_ai_enforce_session_owner(): void
{
    $owner = receptionist_ai_session_owner();
    if (!array_key_exists('receptionist_ai_owner', $_SESSION)) {
        // Preserve legacy anonymous session state across deployment. Authenticated
        // sessions without an owner marker cannot safely inherit prior history.
        if ($owner !== 'guest') {
            unset($_SESSION['receptionist_ai_message_count'], $_SESSION['receptionist_ai_history'], $_SESSION['receptionist_ai_context']);
        }
        $_SESSION['receptionist_ai_owner'] = $owner;
        return;
    }
    if ($_SESSION['receptionist_ai_owner'] !== $owner) {
        unset($_SESSION['receptionist_ai_message_count'], $_SESSION['receptionist_ai_history'], $_SESSION['receptionist_ai_context']);
        $_SESSION['receptionist_ai_owner'] = $owner;
    }
}

function receptionist_ai_public_history(array $history): array
{
    $public = [];
    foreach (array_slice($history, -16) as $turn) {
        if (!is_array($turn) || !in_array($turn['role'] ?? null, ['user', 'assistant'], true) || !is_string($turn['content'] ?? null)) continue;
        $content = trim($turn['content']);
        if ($content === '' || preg_match('//u', $content) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $content) === 1) continue;
        $maximum = $turn['role'] === 'user' ? 500 : 1200;
        $length = function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') : strlen($content);
        if ($length > $maximum) continue;
        if (receptionist_ai_is_sensitive_message($content)) continue;
        $public[] = ['role' => $turn['role'], 'content' => $content];
    }
    return $public;
}

function receptionist_ai_fallback_metadata(string $errorClass): array
{
    $normalized = strtolower(trim($errorClass));
    $map = [
        'busy' => ['code' => 'busy', 'retryable' => true],
        'rate_limit' => ['code' => 'busy', 'retryable' => true],
        'provider_rate_limit' => ['code' => 'busy', 'retryable' => true],
        'visit_limit' => ['code' => 'visit_limit', 'retryable' => false],
        'invalid_request' => ['code' => 'invalid_request', 'retryable' => false],
        'invalid_context' => ['code' => 'invalid_context', 'retryable' => false],
        'provider_timeout' => ['code' => 'provider_timeout', 'retryable' => true],
        'timeout' => ['code' => 'provider_timeout', 'retryable' => true],
        'provider_transport' => ['code' => 'network_error', 'retryable' => true],
        'network_error' => ['code' => 'network_error', 'retryable' => true],
        'curl_unavailable' => ['code' => 'provider_unavailable', 'retryable' => true],
        'provider_http' => ['code' => 'provider_unavailable', 'retryable' => true],
        'provider_schema' => ['code' => 'provider_unavailable', 'retryable' => true],
        'invalid_json' => ['code' => 'provider_unavailable', 'retryable' => true],
        'provider_truncated' => ['code' => 'provider_unavailable', 'retryable' => true],
        'provider_blocked' => ['code' => 'provider_unavailable', 'retryable' => true],
        'provider_unavailable' => ['code' => 'provider_unavailable', 'retryable' => true],
        'server_error' => ['code' => 'server_error', 'retryable' => true],
    ];
    return $map[$normalized] ?? ['code' => 'server_error', 'retryable' => true];
}

function receptionist_ai_retry_delay_ms(int $attempt, int $backoffMs = 250): int
{
    $attempt = max(1, min(3, $attempt));
    $backoffMs = max(0, min(1000, $backoffMs));
    return min(1000, $backoffMs * (2 ** ($attempt - 1)));
}

function receptionist_ai_should_retry_transient(string $errorClass, ?int $status = null): bool
{
    if (in_array($errorClass, ['provider_transport', 'provider_timeout'], true)) return true;
    return $status === 408 || $status === 429 || ($status !== null && $status >= 500 && $status <= 599);
}

function receptionist_ai_retry_policy(int $timeoutSeconds): array
{
    $attempts = (int)receptionist_ai_env('AI_MAX_ATTEMPTS', '3');
    $backoff = (int)receptionist_ai_env('AI_RETRY_BACKOFF_MS', '250');
    return [
        'deadline_seconds' => max(1, min(30, $timeoutSeconds)),
        'max_attempts' => max(1, min(3, $attempts ?: 3)),
        'backoff_ms' => max(0, min(1000, $backoff ?: 250)),
    ];
}

function receptionist_ai_curl_errno_category(int $errno): ?string
{
    if ($errno <= 0) return null;
    if (defined('CURLE_OPERATION_TIMEDOUT') && $errno === CURLE_OPERATION_TIMEDOUT) return 'timeout';
    if (defined('CURLE_COULDNT_RESOLVE_HOST') && $errno === CURLE_COULDNT_RESOLVE_HOST) return 'dns';
    if (defined('CURLE_COULDNT_CONNECT') && $errno === CURLE_COULDNT_CONNECT) return 'connect';
    if (defined('CURLE_SSL_CONNECT_ERROR') && $errno === CURLE_SSL_CONNECT_ERROR) return 'tls';
    return 'transport';
}

function receptionist_ai_response_signals(array $decoded, array $choice = []): array
{
    $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
    $candidates = is_array($decoded['candidates'] ?? null) ? $decoded['candidates'] : [];
    $finish = $choice['finish_reason'] ?? ($choice['finishReason'] ?? ($choice['stop_reason'] ?? ($choice['stopReason'] ?? ($decoded['finish_reason'] ?? ($decoded['finishReason'] ?? ($decoded['stop_reason'] ?? ($decoded['stopReason'] ?? null)))))));
    if ($finish === null && is_array($candidates[0] ?? null)) {
        $finish = $candidates[0]['finishReason'] ?? ($candidates[0]['finish_reason'] ?? null);
    }
    $finishLower = strtolower((string)$finish);
    $signals = [
        'finish_reason' => receptionist_ai_sanitize_provider_error_code($finish),
        'truncated' => in_array($finishLower, ['length', 'max_tokens', 'max_output_tokens', 'truncated'], true),
        'blocked' => false,
    ];
    if (in_array($finishLower, ['safety', 'blocked', 'block', 'content_filter', 'safety_filter'], true)) $signals['blocked'] = true;
    foreach (['truncated', 'is_truncated', 'was_truncated'] as $key) {
        if (($decoded[$key] ?? false) === true || ($choice[$key] ?? false) === true) $signals['truncated'] = true;
    }
    foreach (['blocked', 'block_reason', 'blockReason', 'safety_reason', 'safetyReason', 'safety_blocked', 'safetyBlocked'] as $key) {
        if (array_key_exists($key, $decoded) && $decoded[$key] !== null && $decoded[$key] !== false && $decoded[$key] !== '') $signals['blocked'] = true;
    }
    foreach (['prompt_feedback', 'promptFeedback'] as $feedbackKey) {
        if (!is_array($decoded[$feedbackKey] ?? null)) continue;
        if (($decoded[$feedbackKey]['block_reason'] ?? ($decoded[$feedbackKey]['blockReason'] ?? '')) !== '') $signals['blocked'] = true;
    }
    if ($candidates !== []) {
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) continue;
            $candidateFinish = strtolower((string)($candidate['finishReason'] ?? ($candidate['finish_reason'] ?? '')));
            if (in_array($candidateFinish, ['safety', 'blocked', 'block', 'content_filter', 'safety_filter'], true)) $signals['blocked'] = true;
            if (in_array($candidateFinish, ['length', 'max_tokens', 'max_output_tokens', 'truncated'], true)) $signals['truncated'] = true;
            if (($candidate['blocked'] ?? false) === true || (($candidate['blockReason'] ?? '') !== '')) $signals['blocked'] = true;
        }
    }
    if (isset($usage['prompt_tokens']) && is_numeric($usage['prompt_tokens'])) $signals['prompt_tokens'] = (int)$usage['prompt_tokens'];
    if (isset($usage['completion_tokens']) && is_numeric($usage['completion_tokens'])) $signals['completion_tokens'] = (int)$usage['completion_tokens'];
    return $signals;
}

function receptionist_ai_should_retry_without_response_format(bool $structured, int $status, ?string $errorCode, string $errorMessage): bool
{
    if (!$structured || $status < 400 || $status >= 500 || $status === 429) return false;
    $code = strtolower((string)($errorCode ?? ''));
    $message = strtolower($errorMessage);
    return str_contains($message, 'response_format') || str_contains($message, 'json schema') || str_contains($message, 'structured output')
        || str_contains($code, 'response_format') || str_contains($code, 'json_schema');
}

function receptionist_ai_response_schema(): array
{
    $nullableString = static fn(array $enum = []): array => $enum
        ? ['type' => ['string', 'null'], 'enum' => array_merge($enum, [null])]
        : ['type' => ['string', 'null']];
    $nullableInteger = static fn(): array => ['type' => ['integer', 'null'], 'minimum' => 1];
    return [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['language', 'action', 'reply', 'faq_id', 'slots', 'quick_replies'],
        'properties' => [
            'language' => ['type' => 'string', 'enum' => ['en', 'fil', 'taglish']],
            'action' => ['type' => 'string', 'enum' => ['ask', 'social', 'faq', 'recommend', 'venue', 'availability', 'contact', 'unsupported']],
            'reply' => ['type' => 'string', 'maxLength' => 1200],
            'faq_id' => $nullableString(),
            'slots' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['intent', 'occasion', 'purpose', 'group_size', 'preference', 'start_date', 'end_date', 'active_venue_id', 'active_room_group_id'],
                'properties' => [
                    'intent' => $nullableString(['Event Hall', 'Hotel Room', 'Resort Villa']),
                    'occasion' => $nullableString(['wedding', 'celebration', 'corporate', 'other']),
                    'purpose' => $nullableString(['relaxation', 'family', 'private']),
                    'group_size' => $nullableInteger(),
                    'preference' => $nullableString(['save', 'best_fit', 'comfort']),
                    'start_date' => ['type' => ['string', 'null']],
                    'end_date' => ['type' => ['string', 'null']],
                    'active_venue_id' => $nullableInteger(),
                    'active_room_group_id' => $nullableInteger(),
                ],
            ],
            'quick_replies' => ['type' => 'array', 'maxItems' => 4, 'items' => ['type' => 'string', 'maxLength' => 80]],
        ],
    ];
}

function receptionist_ai_normalize_model_id(string $providerId, string $model): string
{
    if (strtolower($providerId) !== 'google' || str_starts_with($model, 'models/')) return $model;
    return 'models/' . $model;
}

final class ReceptionistGenericOpenAiProvider implements ReceptionistAiProviderInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly string $providerId = 'openrouter',
        private readonly string $siteUrl = '',
        private readonly string $siteName = 'Sevilla360',
        private readonly ?Closure $transport = null,
        private readonly ?Closure $sleep = null,
        private readonly ?Closure $clock = null
    ) {}

    public function complete(array $messages, int $maxOutputTokens, int $timeoutSeconds): array
    {
        $policy = receptionist_ai_retry_policy($timeoutSeconds);
        $started = $this->now();
        $deadline = $started + $policy['deadline_seconds'];
        $structured = strtolower($this->providerId) !== 'google';
        $attempt = 0;
        $retriedWithoutFormat = false;
        $last = ['success' => false, 'error_class' => 'provider_unavailable', 'diagnostic' => []];
        while ($attempt < $policy['max_attempts']) {
            $remainingMs = $this->remainingMilliseconds($deadline);
            if ($remainingMs <= 0) {
                $last = ['success' => false, 'error_class' => 'provider_timeout', 'diagnostic' => []];
                break;
            }
            $attempt++;
            $requestTimeoutMs = max(1, min($policy['deadline_seconds'] * 1000, $remainingMs));
            $result = $this->request($messages, $maxOutputTokens, $requestTimeoutMs, $structured);
            $last = $result;
            $diagnostic = is_array($result['diagnostic'] ?? null) ? $result['diagnostic'] : [];
            $diagnostic['attempt_count'] = $attempt;
            $diagnostic['deadline_seconds'] = $policy['deadline_seconds'];
            $result['diagnostic'] = $diagnostic;
            if (!empty($result['success'])) {
                if ($retriedWithoutFormat) $result['diagnostic']['retried_without_response_format'] = true;
                return $result;
            }
            if ($structured && !empty($result['_retry_without_structured_output'])) {
                unset($result['_retry_without_structured_output']);
                $structured = false;
                $retriedWithoutFormat = true;
                $last = $result;
                continue;
            }
            $status = is_int($diagnostic['http_status'] ?? null) ? $diagnostic['http_status'] : null;
            $errorClass = (string)($result['error_class'] ?? '');
            if (!receptionist_ai_should_retry_transient($errorClass, $status) || $attempt >= $policy['max_attempts']) break;
            $remainingMs = $this->remainingMilliseconds($deadline);
            $delayMs = receptionist_ai_retry_delay_ms($attempt, $policy['backoff_ms']);
            if ($remainingMs <= 0 || $delayMs >= $remainingMs) break;
            $this->wait($delayMs);
        }
        unset($last['_retry_without_structured_output']);
        if (is_array($last['diagnostic'] ?? null)) {
            $last['diagnostic']['attempt_count'] = max($attempt, (int)($last['diagnostic']['attempt_count'] ?? 0));
            $last['diagnostic']['deadline_seconds'] = $policy['deadline_seconds'];
            if ($retriedWithoutFormat) $last['diagnostic']['retried_without_response_format'] = true;
        }
        return $last;
    }

    private function request(array $messages, int $maxOutputTokens, int $timeoutMs, bool $structured): array
    {
        if ($this->transport === null && !function_exists('curl_init')) return ['success' => false, 'error_class' => 'curl_unavailable'];
        $url = rtrim($this->baseUrl, '/') . '/chat/completions';
        $request = [
            'model' => receptionist_ai_normalize_model_id($this->providerId, $this->model),
            'messages' => $messages,
            'temperature' => 0.2,
            'max_tokens' => $maxOutputTokens,
            'stream' => false,
        ];
        if ($structured) {
            $request['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'sevilla_receptionist_response',
                    'strict' => true,
                    'schema' => receptionist_ai_response_schema(),
                ],
            ];
            if ($this->providerId === 'openrouter') {
                $request['provider'] = ['require_parameters' => true];
                $request['plugins'] = [['id' => 'response-healing']];
            }
        }
        $body = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) return ['success' => false, 'error_class' => 'request_encode'];

        $headers = ['Authorization: Bearer ' . $this->apiKey, 'Content-Type: application/json'];
        if ($this->siteUrl !== '') $headers[] = 'HTTP-Referer: ' . $this->siteUrl;
        if ($this->siteName !== '') $headers[] = 'X-Title: ' . $this->siteName;
        $curlErrno = 0;
        $error = '';
        if ($this->transport !== null) {
            try {
                $transportResult = ($this->transport)($url, $headers, $body, $timeoutMs, $structured);
            } catch (Throwable $transportError) {
                $transportResult = ['status' => 0, 'raw' => '', 'error' => $transportError->getMessage(), 'errno' => 1];
            }
            $status = (int)($transportResult['status'] ?? 0);
            $raw = is_string($transportResult['raw'] ?? null) ? $transportResult['raw'] : '';
            $error = is_string($transportResult['error'] ?? null) ? $transportResult['error'] : '';
            $curlErrno = (int)($transportResult['errno'] ?? 0);
        } else {
            $handle = curl_init($url);
            if ($handle === false) return ['success' => false, 'error_class' => 'curl_init', 'diagnostic' => ['request_bytes' => strlen($body)]];
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => min(5000, max(1, $timeoutMs)),
                CURLOPT_TIMEOUT_MS => max(1, $timeoutMs),
            ]);
            $raw = curl_exec($handle);
            $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $error = curl_error($handle);
            $curlErrno = (int)curl_errno($handle);
            curl_close($handle);
        }
        $rawBytes = is_string($raw) ? strlen($raw) : 0;
        $diagnosticBase = ['http_status' => $status > 0 ? $status : null, 'provider_error_code' => null, 'curl_errno_category' => receptionist_ai_curl_errno_category($curlErrno), 'request_bytes' => strlen($body), 'response_bytes' => $rawBytes];
        if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
            $decodedError = is_string($raw) ? json_decode($raw, true) : null;
            $providerError = is_array($decodedError['error'] ?? null) ? $decodedError['error'] : (is_array($decodedError) ? $decodedError : []);
            $topLevelCode = is_array($decodedError) ? ($decodedError['code'] ?? null) : null;
            $errorCode = receptionist_ai_sanitize_provider_error_code($providerError['code'] ?? $topLevelCode);
            $errorMessage = is_string($providerError['message'] ?? null) ? strtolower($providerError['message']) : '';
            $formatRejected = receptionist_ai_should_retry_without_response_format($structured, $status, $errorCode, $errorMessage);
            $diagnostic = $diagnosticBase;
            $diagnostic['provider_error_code'] = $errorCode;
            $timedOut = defined('CURLE_OPERATION_TIMEDOUT') && $curlErrno === CURLE_OPERATION_TIMEDOUT;
            $errorClass = ($status === 408 || $timedOut || str_contains(strtolower($error), 'timed out'))
                ? 'provider_timeout'
                : ($status === 429 ? 'provider_rate_limit' : (($error !== '' || $curlErrno > 0 || $status === 0) ? 'provider_transport' : 'provider_http'));
            $result = ['success' => false, 'error_class' => $errorClass, 'diagnostic' => $diagnostic];
            if ($formatRejected) $result['_retry_without_structured_output'] = true;
            return $result;
        }
        $decoded = json_decode($raw, true);
        $content = is_array($decoded) ? ($decoded['choices'][0]['message']['content'] ?? null) : null;
        $choice = is_array($decoded['choices'][0] ?? null) ? $decoded['choices'][0] : [];
        $diagnostic = $diagnosticBase + receptionist_ai_response_signals($decoded, $choice);
        if (!is_string($content) || trim($content) === '') return ['success' => false, 'error_class' => 'provider_schema', 'diagnostic' => $diagnostic];
        if (!empty($diagnostic['blocked'])) return ['success' => false, 'error_class' => 'provider_blocked', 'diagnostic' => $diagnostic];
        if (!empty($diagnostic['truncated'])) return ['success' => false, 'error_class' => 'provider_truncated', 'diagnostic' => $diagnostic];
        $content = trim($content);
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/\A```(?:json)?\s*|\s*```\z/i', '', $content) ?? $content;
        }
        $payload = json_decode(trim($content), true);
        return is_array($payload)
            ? ['success' => true, 'payload' => $payload, 'diagnostic' => $diagnostic]
            : ['success' => false, 'error_class' => 'invalid_json', 'diagnostic' => $diagnostic];
    }

    private function now(): float { return $this->clock !== null ? (float)($this->clock)() : microtime(true); }
    private function remainingMilliseconds(float $deadline): int
    {
        return max(0, (int)floor(($deadline - $this->now()) * 1000));
    }
    private function wait(int $milliseconds): void
    {
        if ($milliseconds <= 0) return;
        if ($this->sleep !== null) { ($this->sleep)($milliseconds); return; }
        usleep($milliseconds * 1000);
    }
}

function receptionist_ai_env(string $key, string $fallback = ''): string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return is_string($value) ? trim($value) : $fallback;
}

function receptionist_ai_provider(): ?ReceptionistAiProviderInterface
{
    if (receptionist_ai_env('AI_ENABLED', '0') !== '1') return null;
    $provider = strtolower(receptionist_ai_env('AI_PROVIDER', 'openrouter'));
    $key = receptionist_ai_env('AI_API_KEY');
    $model = receptionist_ai_env('AI_MODEL');
    if ($key === '' || $model === '') return null;
    return new ReceptionistGenericOpenAiProvider(
        $key,
        receptionist_ai_env('AI_BASE_URL', 'https://openrouter.ai/api/v1'),
        $model,
        $provider,
        receptionist_ai_env('AI_SITE_URL'),
        receptionist_ai_env('AI_SITE_NAME', 'Sevilla360')
    );
}

function receptionist_ai_limits(): array
{
    $timeout = (int)receptionist_ai_env('AI_TIMEOUT_SECONDS', '20');
    $tokens = (int)receptionist_ai_env('AI_MAX_OUTPUT_TOKENS', '600');
    return ['timeout' => max(1, min(30, $timeout ?: 20)), 'tokens' => max(64, min(800, $tokens ?: 600))];
}

function receptionist_ai_language(string $requested, string $message): string
{
    if (in_array($requested, ['en', 'fil', 'taglish'], true)) return $requested;
    $lower = strtolower($message);
    if (preg_match('/\b(?:pwede|puwede|maaari)\s+(?:po\s+)?(?:ba|bang)\b|\bmag[- ]?walk[ -]?in\b/u', $lower) === 1) return 'fil';
    $filipinoWords = ['magkano', 'paano', 'saan', 'salamat', 'gusto', 'kailangan', 'pwede', 'mayroon', 'booking'];
    $hits = 0;
    foreach ($filipinoWords as $word) if (preg_match('/\b' . preg_quote($word, '/') . '\b/u', $lower)) $hits++;
    return $hits >= 2 ? 'fil' : 'en';
}

function receptionist_ai_privacy_message(string $language = 'en'): string
{
    return match ($language) {
        'fil' => 'Para sa privacy mo, huwag magpadala ng payment, account, contact, o personal details. Maaari kitang tulungan sa venue at booking guidance.',
        'taglish' => 'For your privacy, huwag mag-share ng payment, account, contact, or personal details. Venue at booking guidance lang ang kailangan ko.',
        default => 'For your privacy, please do not share payment, account, contact, or personal details. I only need venue and booking guidance.',
    };
}

function receptionist_ai_guided_message(string $language = 'en', string $variant = 'default'): string
{
    return match ($variant . ':' . $language) {
        'busy:fil' => 'Maraming tanong ngayon. Gamitin ang guided venue choices o subukan muli makalipas ang ilang minuto.',
        'busy:taglish' => 'Maraming questions ngayon. Gamitin ang guided venue choices or try ulit after a few minutes.',
        'busy:en' => 'I’m getting many questions right now. Please use the guided venue choices or try again in a few minutes.',
        'limit:fil' => 'Naabot na ang chat limit para sa visit na ito. Available pa rin ang guided venue choices o maaari kang makipag-ugnayan sa reception.',
        'limit:taglish' => 'Naabot na ang chat limit for this visit. Available pa rin ang guided venue choices or contact reception.',
        'limit:en' => 'I’ve reached the chat limit for this visit. The guided venue choices are still available, or you can contact reception.',
        'timeout:fil' => 'Hindi sumagot ang service sa oras. Gamitin ang guided venue choices o subukan muli sa ilang sandali.',
        'timeout:taglish' => 'Nag-time out ang service. Gamitin ang guided venue choices or try ulit in a moment.',
        'timeout:en' => 'The receptionist service timed out. Please use the guided venue choices or try again in a moment.',
        'unavailable:fil' => 'Hindi available ang chat service ngayon. Gamitin ang guided venue choices o kontakin ang reception.',
        'unavailable:taglish' => 'The chat service is unavailable right now. Use the guided venue choices or contact reception.',
        'unavailable:en' => 'The chat service is unavailable right now. Please use the guided venue choices or contact reception.',
        'network:fil' => 'May problema sa connection ng chat service. Gamitin ang guided venue choices o subukan muli.',
        'network:taglish' => 'May connection issue ang chat service. Use the guided venue choices or try again.',
        'network:en' => 'There was a connection problem with the chat service. Please use the guided venue choices or try again.',
        'server:fil' => 'May temporary server problem. Gamitin ang guided venue choices o subukan muli mamaya.',
        'server:taglish' => 'There is a temporary server problem. Use the guided venue choices or try again later.',
        'server:en' => 'There is a temporary server problem. Please use the guided venue choices or try again later.',
        'default:fil' => 'Gamitin natin ang guided choices para mahanap ko ang tamang venue details para sa iyo.',
        'default:taglish' => 'Gamitin natin ang guided choices para mahanap ang tamang venue details para sa iyo.',
        default => 'Let’s use the guided choices so I can help with the right venue details.',
    };
}

function receptionist_ai_is_sensitive_message(string $message): bool
{
    if (preg_match('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', $message) === 1) return true;
    $digits = preg_replace('/\D+/', '', $message) ?? '';
    if (preg_match('/(?<!\d)(?:\+?63|0)9\d{9}(?!\d)/', $digits) === 1) return true;
    if (strlen($digits) >= 8 && preg_match('/\b(?:phone|mobile|contact|call|number|whatsapp|viber)\b/i', $message) === 1) return true;
    if (preg_match('/\b(?:password|passcode|one[- ]time password|otp|pin)\b/i', $message) === 1) return true;
    return preg_match('/\b(?:transaction|reference|account|receipt|payment|gcash|maya|card)\b[^\n]{0,24}\b(?=[A-Z0-9-]*\d)[A-Z0-9-]{5,}\b/i', $message) === 1
        || preg_match('/\b(?:\d[ -]?){13,19}\b/', $message) === 1;
}

function receptionist_ai_clean_message(string $message): string
{
    $message = trim($message);
    if ($message === '' || preg_match('//u', $message) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $message)) throw new InvalidArgumentException('Enter a question for the receptionist.');
    $length = function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : strlen($message);
    if ($length > 500) throw new InvalidArgumentException('Please keep your message under 500 characters.');
    if (receptionist_ai_is_sensitive_message($message)) throw new ReceptionistSensitiveInputException('Please remove payment, account, contact, or personal details before sending.');
    return $message;
}

function receptionist_ai_canonical_date($value, ?DateTimeImmutable $today = null): ?string
{
    if (!is_string($value) || trim($value) === '') return null;
    $today ??= new DateTimeImmutable('today');
    $value = trim($value);
    $date = null;
    if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value) === 1) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) || $date->format('Y-m-d') !== $value) return null;
    } elseif (preg_match('/\A\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4}\z/', $value) === 1) {
        // Numeric day/month ordering varies by locale. Require the client or
        // model to send canonical Y-m-d rather than guessing.
        return null;
    } else {
        try { $date = new DateTimeImmutable($value); } catch (Exception $e) { return null; }
        if (!$date) return null;
    }
    $formatted = $date->format('Y-m-d');
    return $formatted >= $today->format('Y-m-d') ? $formatted : null;
}

function receptionist_ai_validate_venue_id(mysqli $conn, $value): ?int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) return null;
    $stmt = $conn->prepare("SELECT id FROM venues WHERE id = ? AND status = 'Available' LIMIT 1");
    if (!$stmt) return null;
    $id = (int)$id;
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) { $stmt->close(); return null; }
    $found = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $found ? $id : null;
}

function receptionist_ai_public_venue_catalog(mysqli $conn): array
{
    $stmt = $conn->prepare("SELECT DISTINCT v.id, v.category, v.name AS venue_name, hr.room_type, hr.room_group_id FROM venues v LEFT JOIN hotel_rooms hr ON hr.venue_id = v.id WHERE v.status = 'Available' AND v.category IN ('Event Hall', 'Hotel Room', 'Resort Villa') ORDER BY v.category, v.name, hr.room_type");
    if (!$stmt || !$stmt->execute()) {
        if ($stmt) $stmt->close();
        $stmt = $conn->prepare("SELECT DISTINCT v.id, v.category, v.name AS venue_name, hr.room_type, NULL AS room_group_id FROM venues v LEFT JOIN hotel_rooms hr ON hr.venue_id = v.id WHERE v.status = 'Available' AND v.category IN ('Event Hall', 'Hotel Room', 'Resort Villa') ORDER BY v.category, v.name, hr.room_type");
        if (!$stmt || !$stmt->execute()) return [];
    }
    $result = $stmt->get_result();
    $catalog = [];
    while ($row = $result->fetch_assoc()) {
        $id = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) continue;
        $catalog[] = [
            'id' => (int)$id,
            'category' => (string)$row['category'],
            'name' => (string)$row['venue_name'],
            'room_type' => is_string($row['room_type'] ?? null) ? (string)$row['room_type'] : '',
            'room_group_id' => filter_var($row['room_group_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null,
        ];
    }
    $stmt->close();
    // Keep the complete validated identity list for server-side selection.
    // Prompt construction applies its own bounded, query-aware projection.
    return receptionist_ai_compact_venue_catalog($catalog, 5000);
}

function receptionist_ai_compact_venue_catalog(array $catalog, int $limit = 80, ?string $query = null, array $context = []): array
{
    $unique = [];
    foreach ($catalog as $venue) {
        if (!is_array($venue)) continue;
        $id = filter_var($venue['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) continue;
        $category = (string)($venue['category'] ?? '');
        if (!in_array($category, ['Event Hall', 'Hotel Room', 'Resort Villa'], true)) continue;
        $group = filter_var($venue['room_group_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $key = implode('|', [(int)$id, $category, $group === false ? '' : (int)$group]);
        if (isset($unique[$key])) continue;
        $unique[$key] = [
            'id' => (int)$id,
            'category' => $category,
            'name' => receptionist_ai_safe_catalog_text($venue['name'] ?? ''),
            'room_type' => receptionist_ai_safe_catalog_text($venue['room_type'] ?? ''),
            'room_group_id' => $group === false ? null : (int)$group,
        ];
    }
    $items = array_values($unique);
    if ($query !== null || $context !== []) {
        $normalizedQuery = strtolower((string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $query ?? ''));
        $queryTokens = preg_split('/\s+/', trim($normalizedQuery), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $activeVenueId = filter_var($context['active_venue_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $activeGroupId = filter_var($context['active_room_group_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $scored = [];
        foreach ($items as $position => $venue) {
            $name = strtolower((string)($venue['name'] ?? ''));
            $roomType = strtolower((string)($venue['room_type'] ?? ''));
            $category = strtolower((string)($venue['category'] ?? ''));
            $score = 0;
            if ($activeVenueId !== false && (int)$venue['id'] === (int)$activeVenueId) $score += 1000;
            if ($activeGroupId !== false && (int)($venue['room_group_id'] ?? 0) === (int)$activeGroupId) $score += 500;
            if ($normalizedQuery !== '' && $name !== '' && str_contains($normalizedQuery, $name)) $score += 800;
            if ($normalizedQuery !== '' && $roomType !== '' && str_contains($normalizedQuery, $roomType)) $score += 500;
            foreach ($queryTokens as $token) {
                if ($token === '') continue;
                if (in_array($token, preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY), true)) $score += 80;
                elseif (in_array($token, preg_split('/\s+/', $roomType, -1, PREG_SPLIT_NO_EMPTY), true)) $score += 60;
                elseif (in_array($token, preg_split('/\s+/', $category, -1, PREG_SPLIT_NO_EMPTY), true)) $score += 20;
                elseif (strlen($token) >= 5) {
                    foreach (preg_split('/\s+/', trim($name . ' ' . $roomType), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $candidate) {
                        if (abs(strlen($candidate) - strlen($token)) <= 1 && levenshtein($token, $candidate) <= 1) { $score += 25; break; }
                    }
                }
            }
            $scored[] = ['score' => $score, 'position' => $position, 'key' => implode('|', [(string)$venue['id'], (string)$venue['category'], (string)($venue['room_group_id'] ?? '')]), 'venue' => $venue];
        }
        usort($scored, static function (array $left, array $right): int {
            $score = $right['score'] <=> $left['score'];
            if ($score !== 0) return $score;
            return $left['position'] <=> $right['position'];
        });
        $items = array_map(static fn(array $entry): array => $entry['venue'], $scored);
    }
    return array_slice($items, 0, max(1, min(5000, $limit)));
}

function receptionist_ai_safe_catalog_text($value): string
{
    if (!is_string($value)) return '';
    $value = trim((string)preg_replace('/\s+/', ' ', $value));
    return preg_match('/[\x00-\x1F\x7F]/', $value) === 1 ? '' : substr($value, 0, 160);
}

function receptionist_ai_catalog_venue(array $catalog, $value, ?string $category = null, $roomGroupId = null): ?array
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) return null;
    $group = null;
    if ($roomGroupId !== null && $roomGroupId !== '') {
        $group = filter_var($roomGroupId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($group === false) return null;
    }
    // Hotel venue ids can represent several room identities. Never silently
    // select the first row when the catalog carries room groups.
    if ($category === 'Hotel Room' && $group === null && receptionist_ai_hotel_group_required($catalog, $id)) return null;
    foreach ($catalog as $venue) {
        if ((int)($venue['id'] ?? 0) !== (int)$id) continue;
        if ($category !== null && ($venue['category'] ?? null) !== $category) continue;
        if ($group !== null && (int)($venue['room_group_id'] ?? 0) !== (int)$group) continue;
        return $venue;
    }
    return null;
}

function receptionist_ai_hotel_group_required(array $catalog, int $venueId): bool
{
    foreach ($catalog as $venue) {
        if ((int)($venue['id'] ?? 0) !== $venueId || ($venue['category'] ?? null) !== 'Hotel Room') continue;
        if (filter_var($venue['room_group_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) return true;
    }
    return false;
}

function receptionist_ai_capacity_max(mysqli $conn, string $category): ?int
{
    if (!in_array($category, ['Event Hall', 'Hotel Room', 'Resort Villa'], true)) return null;
    try {
        $stmt = $conn->prepare("SELECT MAX(CASE WHEN v.category = 'Event Hall' THEN eh.max_capacity WHEN v.category = 'Resort Villa' THEN vi.max_capacity WHEN v.category = 'Hotel Room' THEN hr.max_capacity END) AS max_capacity FROM venues v LEFT JOIN event_halls eh ON eh.venue_id = v.id LEFT JOIN villas vi ON vi.venue_id = v.id LEFT JOIN hotel_rooms hr ON hr.venue_id = v.id WHERE v.status = 'Available' AND v.category = ?");
    } catch (Throwable $error) {
        return null;
    }
    if (!$stmt) return null;
    $stmt->bind_param('s', $category);
    if (!$stmt->execute()) { $stmt->close(); return null; }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $max = filter_var($row['max_capacity'] ?? null, FILTER_VALIDATE_INT);
    return $max !== false && $max > 0 ? $max : null;
}

function receptionist_ai_validate_slots(mysqli $conn, $raw, array $base = [], ?array $catalog = null): array
{
    if (!is_array($raw)) throw new InvalidArgumentException('Invalid receptionist slots.');
    $source = receptionist_ai_merge_slots($base, $raw);
    $slots = [];
    $intent = $source['intent'] ?? null;
    if ($intent !== null && !in_array($intent, ['Event Hall', 'Hotel Room', 'Resort Villa'], true)) throw new InvalidArgumentException('Invalid receptionist venue category.');
    if ($intent !== null) $slots['intent'] = $intent;
    foreach (['occasion' => ['wedding', 'celebration', 'corporate', 'other'], 'purpose' => ['relaxation', 'family', 'private'], 'preference' => ['save', 'best_fit', 'comfort']] as $key => $allowed) {
        if (($source[$key] ?? null) === null || $source[$key] === '') continue;
        if (!in_array($source[$key], $allowed, true)) throw new InvalidArgumentException('Invalid receptionist preference.');
        $slots[$key] = $source[$key];
    }
    if (array_key_exists('group_size', $source) && $source['group_size'] !== null && $source['group_size'] !== '') {
        $count = filter_var($source['group_size'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($count === false || $count > 10000) throw new InvalidArgumentException('Invalid guest count.');
        $max = $intent !== null ? receptionist_ai_capacity_max($conn, $intent) : null;
        if ($max !== null && $count > $max) throw new InvalidArgumentException('Guest count exceeds the active venue capacity.');
        $slots['group_size'] = (int)$count;
    }
    foreach (['start_date', 'end_date'] as $key) {
        if (($source[$key] ?? null) === null || $source[$key] === '') continue;
        $date = receptionist_ai_canonical_date($source[$key]);
        if ($date === null) throw new InvalidArgumentException('Invalid date.');
        $slots[$key] = $date;
    }
    if (isset($slots['start_date'], $slots['end_date']) && $slots['end_date'] <= $slots['start_date']) throw new InvalidArgumentException('Checkout must be after check-in.');
    if (array_key_exists('active_room_group_id', $source) && $source['active_room_group_id'] !== null && $source['active_room_group_id'] !== '') {
        $groupId = filter_var($source['active_room_group_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($groupId === false) throw new InvalidArgumentException('Invalid hotel room identity.');
    } else {
        $groupId = null;
    }
    if (array_key_exists('active_venue_id', $source) && $source['active_venue_id'] !== null && $source['active_venue_id'] !== '') {
        $catalog ??= receptionist_ai_public_venue_catalog($conn);
        $venue = receptionist_ai_catalog_venue($catalog, $source['active_venue_id'], $intent, $groupId);
        if ($venue === null) throw new InvalidArgumentException('Invalid or mismatched venue.');
        $slots['active_venue_id'] = (int)$venue['id'];
        if ($venue['room_group_id'] !== null) $slots['active_room_group_id'] = (int)$venue['room_group_id'];
    } elseif ($groupId !== null) {
        throw new InvalidArgumentException('Hotel room identity needs a venue.');
    }
    return $slots;
}

/**
 * Prepare deterministic knowledge slots with the same validation used by the
 * public endpoint. Kept pure (aside from the supplied connection/catalog) so
 * intent-switch behavior can be regression-tested without bootstrapping HTTP.
 */
function receptionist_ai_prepare_knowledge(mysqli $conn, array $answer, array $baseSlots, array $venueCatalog): array
{
    $knowledgePatch = is_array($answer['slots'] ?? null) ? $answer['slots'] : [];
    // Deterministic reset answers are replacements, not empty patches. This
    // prevents the validator's normal context merge from restoring stale
    // occasion/date/venue details from the previous flow.
    $knowledgeValidationBase = ($answer['reset_context'] ?? false) === true ? [] : $baseSlots;
    if (isset($knowledgePatch['intent'], $knowledgeValidationBase['intent']) && $knowledgePatch['intent'] !== $knowledgeValidationBase['intent']) {
        foreach (['occasion', 'purpose', 'preference', 'group_size', 'start_date', 'end_date', 'active_venue_id', 'active_room_group_id'] as $key) unset($knowledgeValidationBase[$key]);
    }
    $answer['slots'] = receptionist_ai_validate_slots($conn, $knowledgePatch, $knowledgeValidationBase, $venueCatalog);
    $answer['missing_slots'] = is_array($answer['missing_slots'] ?? null) ? $answer['missing_slots'] : [];
    $answer['quick_replies'] = array_slice(array_values(array_filter($answer['quick_replies'] ?? [], 'is_string')), 0, 4);
    return $answer;
}

function receptionist_ai_missing_slots(array $slots): array
{
    $intent = $slots['intent'] ?? null;
    if ($intent === null) return ['intent'];
    $missing = [];
    if (!array_key_exists('group_size', $slots)) $missing[] = 'group_size';
    if ($intent === 'Event Hall' && !array_key_exists('occasion', $slots)) $missing[] = 'occasion';
    if ($intent === 'Resort Villa' && !array_key_exists('purpose', $slots)) $missing[] = 'purpose';
    if ($intent === 'Hotel Room' && !array_key_exists('preference', $slots)) $missing[] = 'preference';
    return $missing;
}

function receptionist_ai_action_missing_slots(string $action, array $slots): array
{
    if ($action === 'social') return [];
    if ($action === 'venue') {
        $venueMissing = [];
        if (!array_key_exists('intent', $slots)) $venueMissing[] = 'intent';
        if (!array_key_exists('active_venue_id', $slots)) $venueMissing[] = 'active_venue_id';
        return $venueMissing;
    }
    $missing = receptionist_ai_missing_slots($slots);
    if ($action === 'availability' && !array_key_exists('start_date', $slots)) $missing[] = 'start_date';
    if ($action === 'availability' && ($slots['intent'] ?? null) === 'Hotel Room' && !array_key_exists('end_date', $slots)) $missing[] = 'end_date';
    return array_values(array_unique($missing));
}

function receptionist_ai_shortlist_faq(array $faqs, string $message, int $limit = 5): array
{
    $normalizedMessage = strtolower((string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $message));
    $terms = preg_split('/\s+/', trim($normalizedMessage), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $stopWords = ['the', 'and', 'how', 'what', 'are', 'can', 'for', 'is', 'my', 'with', 'about', 'please', 'may', 'you', 'your', 'this', 'that', 'all'];
    $terms = array_values(array_filter($terms, static fn(string $term): bool => strlen($term) >= 3 && !in_array($term, $stopWords, true)));
    $scored = [];
    foreach ($faqs as $faq) {
        $phraseText = strtolower((string)($faq['category'] ?? '') . ' ' . (string)$faq['question'] . ' ' . implode(' ', $faq['phrases'] ?? []));
        $answerText = strtolower((string)$faq['answer']);
        $haystack = $phraseText . ' ' . $answerText;
        $haystackTokens = preg_split('/\s+/', trim((string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $haystack)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $score = 0;
        foreach ($terms as $term) {
            if (str_contains($phraseText, $term)) $score += 3;
            elseif (str_contains($answerText, $term)) $score++;
            elseif (strlen($term) >= 4) foreach ($haystackTokens as $candidate) {
                if (abs(strlen($candidate) - strlen($term)) <= 2 && levenshtein($term, $candidate) <= 1) { $score++; break; }
            }
        }
        if ($score > 0) $scored[] = ['score' => $score, 'faq' => $faq];
    }
    usort($scored, static function (array $left, array $right): int {
        $score = $right['score'] <=> $left['score'];
        return $score !== 0 ? $score : strcmp((string)($left['faq']['id'] ?? ''), (string)($right['faq']['id'] ?? ''));
    });
    $boundedLimit = max(1, min(5, $limit));
    if (!$scored) {
        // Generic help (for example “Support FAQs”) still needs an approved,
        // bounded shortlist so the model can choose only known FAQ ids.
        return array_slice(array_values(array_filter($faqs, 'is_array')), 0, $boundedLimit);
    }
    return array_map(static fn(array $entry): array => $entry['faq'], array_slice($scored, 0, $boundedLimit));
}

function receptionist_ai_system_prompt(array $faqs, array $context, string $language = 'en', array $venueCatalog = [], array $knowledge = [], ?string $message = null): string
{
    $faqLines = array_map(static fn(array $faq): string => json_encode(['id' => $faq['id'], 'category' => $faq['category'], 'question' => $faq['question'], 'phrases' => $faq['phrases']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $faqs);
    $venueLines = array_map(static fn(array $venue): string => json_encode($venue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), receptionist_ai_compact_venue_catalog($venueCatalog, 80, $message, $context));
    $knowledgeLines = array_map(static fn(array $fact): string => json_encode($fact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), array_slice($knowledge, 0, 8));
    $currentDate = date('Y-m-d');
    return "The current date is {$currentDate}. You are Sevilla360's warm professional virtual receptionist. The requested response language is {$language}; always use it for normal, fallback, contact, unsupported, and action copy. Return JSON only with keys language, action, reply, faq_id, slots, quick_replies. Allowed action: ask, social, faq, recommend, venue, availability, contact, unsupported. Use action social for greetings (hi, hello, hey), identity questions (who are you, what is this), thank-you messages, goodbyes, and other conversational messages that do not require factual resort data. Your reply will be shown directly for social, so keep it warm, brief, and helpful. You are the virtual receptionist for M.I. Sevilla Resort & Events Place (Sevilla360). Never mention specific prices, capacities, rates, or invented facts in social replies; gently guide the visitor toward venue exploration instead. Allowed language: en, fil, taglish. Use only the provided FAQ ids for factual FAQ answers; never invent prices, capacities, availability, booking/payment/account facts, or URLs. Public knowledge facts below are bounded approved references, not permission to invent or alter numeric values; factual replies are composed by the server. Use action contact for unknown resort facts. Slots may contain only intent (Event Hall, Hotel Room, Resort Villa), occasion (wedding, celebration, corporate, other), purpose (relaxation, family, private), group_size (positive integer), preference (save, best_fit, comfort), start_date/end_date (YYYY-MM-DD), active_venue_id, and active_room_group_id. Only use a venue id and room group id from the authoritative catalog below, and keep its category consistent with intent. Do not submit bookings. Keep reply concise and provide at most 4 short quick replies. FAQ shortlist: " . implode("\n", $faqLines) . "\nAuthoritative public venue catalog: " . implode("\n", $venueLines) . "\nBounded approved public knowledge: " . implode("\n", $knowledgeLines) . "\nCurrent safe context: " . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function receptionist_ai_normalize_output(array $payload, mysqli $conn, array $baseSlots, array $faqs, string $fallbackLanguage = 'en', ?array $venueCatalog = null): array
{
    if (!in_array($payload['language'] ?? null, ['en', 'fil', 'taglish'], true)) throw new InvalidArgumentException('Invalid receptionist language.');
    $language = in_array($fallbackLanguage, ['en', 'fil', 'taglish'], true) ? $fallbackLanguage : $payload['language'];
    $action = $payload['action'] ?? null;
    if (!in_array($action, ['ask', 'social', 'faq', 'recommend', 'venue', 'availability', 'contact', 'unsupported'], true)) throw new InvalidArgumentException('Invalid receptionist action.');
    $rawSlots = is_array($payload['slots'] ?? null) ? $payload['slots'] : [];
    $validationBase = $baseSlots;
    $forcedMissing = [];
    $candidateIntent = $rawSlots['intent'] ?? ($baseSlots['intent'] ?? null);
    $candidateVenueId = $rawSlots['active_venue_id'] ?? ($baseSlots['active_venue_id'] ?? null);
    $candidateGroupId = $rawSlots['active_room_group_id'] ?? ($baseSlots['active_room_group_id'] ?? null);
    $candidateVenue = filter_var($candidateVenueId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (isset($rawSlots['intent'], $baseSlots['intent']) && $rawSlots['intent'] !== null && $rawSlots['intent'] !== '' && $rawSlots['intent'] !== $baseSlots['intent']) {
        // An explicit category change is an intent reset. Do not carry
        // occasion/preference/date/venue fields from the previous flow.
        $validationBase = [];
    }
    if ($venueCatalog === null && $candidateVenue !== false) $venueCatalog = receptionist_ai_public_venue_catalog($conn);
    $venueCatalogForValidation = $venueCatalog;
    if ($venueCatalogForValidation === null) $venueCatalogForValidation = [];
    if ($action === 'venue' && $candidateIntent === 'Hotel Room' && $candidateVenue !== false && ($candidateGroupId === null || $candidateGroupId === '')
        && receptionist_ai_hotel_group_required($venueCatalogForValidation, (int)$candidateVenue)) {
        // A model action without room identity is incomplete, not permission
        // to pick an arbitrary room. Ask for the identity explicitly.
        $action = 'ask';
        $forcedMissing[] = 'active_room_group_id';
        unset($rawSlots['active_venue_id'], $validationBase['active_venue_id']);
    }
    $slots = receptionist_ai_validate_slots($conn, $rawSlots, $validationBase, $venueCatalog);
    $faqId = $payload['faq_id'] ?? null;
    $faq = null;
    if ($action === 'faq') {
        if (!is_string($faqId) || ($faq = receptionist_faq_find($faqs, $faqId)) === null) throw new InvalidArgumentException('Invalid FAQ selection.');
    }
    $reply = receptionist_faq_text($payload['reply'] ?? '', 1200);
    if ($reply === null) throw new InvalidArgumentException('Invalid receptionist reply.');
    if (preg_match('/(?:https?:\/\/|www\.)/i', $reply) === 1) throw new InvalidArgumentException('Unsafe receptionist reply.');
    $quickReplies = [];
    if (is_array($payload['quick_replies'] ?? null)) {
        foreach ($payload['quick_replies'] as $quick) {
            if (count($quickReplies) >= 4) break;
            $clean = receptionist_faq_text($quick, 80);
            if ($clean !== null && preg_match('/(?:https?:\/\/|www\.)/i', $clean) === 1) throw new InvalidArgumentException('Unsafe receptionist quick reply.');
            if ($clean !== null && !in_array($clean, $quickReplies, true)) $quickReplies[] = $clean;
        }
    }
    $missingForAction = array_values(array_unique(array_merge($forcedMissing, receptionist_ai_action_missing_slots($action, $slots))));
    if (in_array($action, ['recommend', 'availability', 'venue'], true) && $missingForAction) {
        $action = 'ask';
    }
    if ($faq !== null) $reply = $faq['answer'];
    $safeReplies = [
        'en' => [
            'ask' => 'Sure! Let me help you find the perfect venue. What are you looking for — an event hall, hotel room, or our resort villa?',
            'recommend' => 'I’ll use those details — great choices! Let me pull up the best options for you.',
            'venue' => 'Let me open that venue for you so you can take a closer look!',
            'availability' => 'I’ll check the selected dates for you — no reservation is made at this step.',
            'contact' => 'Great question! I want to make sure you get the right answer. You can reach our team for booking questions, venue details, or help with an existing reservation.',
            'unsupported' => 'I\'m best at helping with venues, bookings, and resort info! For anything else, our team would love to help — feel free to reach out to us.',
        ],
        'fil' => [
            'ask' => 'Sige! Tulungan kita mahanap ang tamang venue. Ano ang hinahanap mo — event hall, hotel room, o resort villa namin?',
            'recommend' => 'Magagandang pagpipilian! Hanapin ko ang pinakabagay na options para sa iyo.',
            'venue' => 'Buksan ko ang venue na iyan para mas makita mo ang mga detalye!',
            'availability' => 'Tingnan ko ang mga petsa na iyan para sa iyo — wala pang reservation sa hakbang na ito.',
            'contact' => 'Magandang tanong! Gusto kong matiyak na makuha mo ang tamang sagot. Pwede mong tawagan ang team namin para sa booking questions, venue details, o tulong sa existing reservation.',
            'unsupported' => 'Mas makakatulong ako sa venues, bookings, at resort info! Para sa iba, pwede mong kontakin ang team namin.',
        ],
        'taglish' => [
            'ask' => 'Sure! Let me help you find the perfect spot. Ano ang hanap mo — event hall, hotel room, or our resort villa?',
            'recommend' => 'Great choices! Let me pull up the best options for you.',
            'venue' => 'Let me open that venue for you para mas makita mo ang details!',
            'availability' => 'Let me check those dates for you — wala pang reservation sa step na ito.',
            'contact' => 'Great question! Gusto kong ma-sure na makuha mo ang right answer. You can reach our team for booking questions, venue details, or help sa existing reservation.',
            'unsupported' => 'I\'m best at helping with venues, bookings, and resort info! For anything else, feel free to reach out to our team.',
        ],
    ];
    // Preserve useful clarifying questions, but keep factual answers on the
    // deterministic public-knowledge/approved-FAQ path.
    $safeAsk = preg_match('/\A(?:what|which|when|where|who|why|how|would|could|can|do|does|did|is|are|will|may|please|tell me|ano|alin|sino|kailan|saan|paano|bakit|ilan|ilang|anong|gaano|maaari|pwede)\b[^.!?]{0,239}\?\z/iu', $reply) === 1
        && preg_match('/(?:₱|\bPHP\s*\d|\d|\b(?:price|prices|rate|rates|cost|capacity|available|availability|include|includes|amenities|wifi|parking|payment|cancell?ation|policy|policies|address|phone|email|there\s+(?:is|are)|we\s+have|our\s+(?:rooms?|venues?))\b)/i', $reply) !== 1;
    if ($action === 'ask' && !$safeAsk) $reply = $safeReplies[$language]['ask'];
    elseif ($action !== 'social' && $action !== 'ask' && isset($safeReplies[$language][$action])) $reply = $safeReplies[$language][$action];

    if ($action === 'social' || $action === 'ask') {
        if (preg_match('/₱|\bPHP\s*\d|\bper\s+(?:night|day|person|pax|head|event)\b|\b\d{3,}[,.]?\d*\s*(?:pesos?|php)\b/i', $reply) === 1) {
            if ($action === 'social') {
                $reply = match ($language) {
                    'fil' => 'Kumusta! Ako ang virtual receptionist ng M.I. Sevilla Resort & Events Place. Paano kita matutulungan?',
                    'taglish' => 'Hello! Ako ang virtual receptionist ng M.I. Sevilla Resort & Events Place. How can I help you?',
                    default => 'Hello! I\'m the virtual receptionist for M.I. Sevilla Resort & Events Place. How can I help you today?',
                };
            } else {
                $reply = $safeReplies[$language]['ask']; // Fallback to safe ask
            }
        }
    }
    return [
        'language' => $language,
        'action' => $action,
        'reply' => $reply,
        'faq_id' => $faq['id'] ?? null,
        'slots' => $slots,
        'missing_slots' => $missingForAction ?: receptionist_ai_missing_slots($slots),
        'quick_replies' => $quickReplies,
    ];
}
