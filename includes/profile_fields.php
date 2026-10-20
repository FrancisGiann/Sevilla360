<?php
declare(strict_types=1);

/** Validate profile fields before they reach database column limits. */
function profile_input_text(mixed $value, string $label, int $maximum, bool $required = true): string
{
    if (!is_string($value) || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
        throw new InvalidArgumentException("Enter a valid {$label}.");
    }
    $value = trim($value);
    if ($required && $value === '') throw new InvalidArgumentException("{$label} cannot be empty.");
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : preg_match_all('/./us', $value);
    if ($length > $maximum) throw new InvalidArgumentException("{$label} must be {$maximum} characters or fewer.");
    return $value;
}

function profile_input_phone(mixed $value): string
{
    $phone = profile_input_text($value, 'Contact number', 20, false);
    if ($phone !== '' && preg_match('/\A[0-9+().\- ]+\z/D', $phone) !== 1) {
        throw new InvalidArgumentException('Contact number may contain only numbers, spaces, and + ( ) . - characters.');
    }
    return $phone;
}
