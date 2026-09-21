<?php

const EVENT_BUNDLE_DISCOUNT_SETTING_KEY = 'event_hall_bundle_discount_percent';
const EVENT_BUNDLE_DISCOUNT_DEFAULT_PERCENT = 20.0;

/**
 * Parse an administrator-supplied bundle discount percentage.
 *
 * A null return means the value is not a finite number in the inclusive
 * 0..100 range. Decimal percentages are supported for pricing precision.
 */
function parse_event_bundle_discount_percent($value): ?float
{
    if (is_array($value) || is_object($value) || is_bool($value) || !is_scalar($value)) {
        return null;
    }

    $raw = trim((string)$value);
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }

    $percent = (float)$raw;
    if (!is_finite($percent) || $percent < 0 || $percent > 100) {
        return null;
    }

    return round($percent, 2);
}

/**
 * Normalize a stored setting safely. Malformed legacy values use the default.
 */
function normalize_event_bundle_discount_percent($value): float
{
    return parse_event_bundle_discount_percent($value) ?? EVENT_BUNDLE_DISCOUNT_DEFAULT_PERCENT;
}

function event_bundle_discount_rate($percent): float
{
    return normalize_event_bundle_discount_percent($percent) / 100;
}

function format_event_bundle_discount_percent($percent): string
{
    $formatted = number_format(normalize_event_bundle_discount_percent($percent), 2, '.', '');
    return rtrim(rtrim($formatted, '0'), '.');
}

function event_bundle_discount_label($percent): string
{
    return 'Event Hall + Hotel Bundle Discount (' . format_event_bundle_discount_percent($percent) . '%)';
}

function load_event_bundle_discount_percent($conn): float
{
    $default = EVENT_BUNDLE_DISCOUNT_DEFAULT_PERCENT;
    if (!$conn) return $default;

    $key = EVENT_BUNDLE_DISCOUNT_SETTING_KEY;
    $stmt = $conn->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
    if (!$stmt) return $default;
    $stmt->bind_param('s', $key);
    if (!$stmt->execute()) {
        $stmt->close();
        return $default;
    }
    $value = $stmt->get_result()->fetch_assoc()['setting_value'] ?? null;
    $stmt->close();

    return normalize_event_bundle_discount_percent($value);
}
