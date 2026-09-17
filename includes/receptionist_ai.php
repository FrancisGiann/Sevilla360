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

final class ReceptionistGenericOpenAiProvider implements ReceptionistAiProviderInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly string $providerId = 'openrouter',
        private readonly string $siteUrl = '',
        private readonly string $siteName = 'Sevilla360'
    ) {}

    public function complete(array $messages, int $maxOutputTokens, int $timeoutSeconds): array
    {
        $result = $this->request($messages, $maxOutputTokens, $timeoutSeconds, true);
        if (!empty($result['_retry_without_structured_output'])) {
            unset($result['_retry_without_structured_output']);
            $result = $this->request($messages, $maxOutputTokens, $timeoutSeconds, false);
            if (is_array($result['diagnostic'] ?? null)) $result['diagnostic']['retried_without_response_format'] = true;
        }
        unset($result['_retry_without_structured_output']);
        return $result;
    }

    private function request(array $messages, int $maxOutputTokens, int $timeoutSeconds, bool $structured): array
    {
        if (!function_exists('curl_init')) return ['success' => false, 'error_class' => 'curl_unavailable'];
        $url = rtrim($this->baseUrl, '/') . '/chat/completions';
        $request = [
            'model' => $this->model,
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
        $handle = curl_init($url);
        if ($handle === false) return ['success' => false, 'error_class' => 'curl_init'];
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, max(1, $timeoutSeconds)),
            CURLOPT_TIMEOUT => max(1, $timeoutSeconds),
        ]);
        $raw = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
            $decodedError = is_string($raw) ? json_decode($raw, true) : null;
            $providerError = is_array($decodedError['error'] ?? null) ? $decodedError['error'] : (is_array($decodedError) ? $decodedError : []);
            $topLevelCode = is_array($decodedError) ? ($decodedError['code'] ?? null) : null;
            $errorCode = receptionist_ai_sanitize_provider_error_code($providerError['code'] ?? $topLevelCode);
            $errorMessage = is_string($providerError['message'] ?? null) ? strtolower($providerError['message']) : '';
            $formatRejected = receptionist_ai_should_retry_without_response_format($structured, $status, $errorCode, $errorMessage);
            $diagnostic = [
                'http_status' => $status > 0 ? $status : null,
                'provider_error_code' => $errorCode,
            ];
            $result = ['success' => false, 'error_class' => $status === 429 ? 'provider_rate_limit' : ($error !== '' ? 'provider_transport' : 'provider_http'), 'diagnostic' => $diagnostic];
            if ($formatRejected) $result['_retry_without_structured_output'] = true;
            return $result;
        }
        $decoded = json_decode($raw, true);
        $content = is_array($decoded) ? ($decoded['choices'][0]['message']['content'] ?? null) : null;
        if (!is_string($content) || trim($content) === '') return ['success' => false, 'error_class' => 'provider_schema', 'diagnostic' => ['http_status' => $status, 'provider_error_code' => null]];
        $content = trim($content);
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/\A```(?:json)?\s*|\s*```\z/i', '', $content) ?? $content;
        }
        $payload = json_decode(trim($content), true);
        return is_array($payload)
            ? ['success' => true, 'payload' => $payload]
            : ['success' => false, 'error_class' => 'invalid_json', 'diagnostic' => ['http_status' => $status, 'provider_error_code' => null]];
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
    
    // First try strict format
    $date = null;
    if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value)) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    } else {
        // Fallback to loose parsing for weak models
        try {
            $date = new DateTimeImmutable($value);
        } catch (Exception $e) {
            return null;
        }
    }
    
    if (!$date) return null;
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
    return $catalog;
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
    $stmt = $conn->prepare("SELECT MAX(CASE WHEN v.category = 'Event Hall' THEN eh.max_capacity WHEN v.category = 'Resort Villa' THEN vi.max_capacity WHEN v.category = 'Hotel Room' THEN hr.max_capacity END) AS max_capacity FROM venues v LEFT JOIN event_halls eh ON eh.venue_id = v.id LEFT JOIN villas vi ON vi.venue_id = v.id LEFT JOIN hotel_rooms hr ON hr.venue_id = v.id WHERE v.status = 'Available' AND v.category = ?");
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
    $source = array_replace($base, $raw);
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
    $terms = preg_split('/[^\p{L}\p{N}]+/u', strtolower($message), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $scored = [];
    foreach ($faqs as $faq) {
        $haystack = strtolower((string)($faq['category'] ?? '') . ' ' . (string)$faq['question'] . ' ' . (string)$faq['answer'] . ' ' . implode(' ', $faq['phrases'] ?? []));
        $score = 0;
        foreach ($terms as $term) if (strlen($term) >= 3 && str_contains($haystack, $term)) $score++;
        if ($score > 0) $scored[] = ['score' => $score, 'faq' => $faq];
    }
    usort($scored, static fn(array $left, array $right): int => $right['score'] <=> $left['score']);
    $boundedLimit = max(1, min(5, $limit));
    if (!$scored) {
        // Generic help (for example “Support FAQs”) still needs an approved,
        // bounded shortlist so the model can choose only known FAQ ids.
        return array_slice(array_values(array_filter($faqs, 'is_array')), 0, $boundedLimit);
    }
    return array_map(static fn(array $entry): array => $entry['faq'], array_slice($scored, 0, $boundedLimit));
}

function receptionist_ai_system_prompt(array $faqs, array $context, string $language = 'en', array $venueCatalog = [], array $knowledge = []): string
{
    $faqLines = array_map(static fn(array $faq): string => json_encode(['id' => $faq['id'], 'category' => $faq['category'], 'question' => $faq['question'], 'phrases' => $faq['phrases']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $faqs);
    $venueLines = array_map(static fn(array $venue): string => json_encode($venue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $venueCatalog);
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
    if ($action !== 'social' && $action !== 'ask' && isset($safeReplies[$language][$action])) $reply = $safeReplies[$language][$action];

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
