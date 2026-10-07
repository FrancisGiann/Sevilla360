<?php
declare(strict_types=1);

function venue_normalize_time_value(string $raw, string $default, bool $allowEmpty, string $fieldName = 'Time'): string
{
    $value = trim($raw);
    if ($value === '' && $allowEmpty) {
        $value = $default;
    }

    if (!preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?\z/D', $value)) {
        throw new InvalidArgumentException("{$fieldName} must be a valid time.");
    }

    return strlen($value) === 5 ? $value . ':00' : $value;
}
