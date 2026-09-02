<?php
/**
 * Central policy for passwords that are about to be stored as new hashes.
 * Existing hashes are intentionally not checked against this policy.
 */
const PASSWORD_POLICY_MIN_BYTES = 8;
const PASSWORD_POLICY_MAX_BYTES = 72;

function password_policy_message(): string
{
    return 'Use 8–72 characters with a capital letter, lowercase letter, number, and symbol (like ! or @).';
}

/**
 * Validate a password before hashing it.
 *
 * Length is measured in bytes because password hashing implementations may
 * enforce byte limits. The character classes are deliberately ASCII-based;
 * any byte outside ASCII letters and digits satisfies the symbol requirement.
 *
 * @return array{valid: bool, message: string}
 */
function password_policy_validate(string $password): array
{
    $message = password_policy_message();
    $length = strlen($password);

    if (strpos($password, "\0") !== false
        || $length < PASSWORD_POLICY_MIN_BYTES
        || $length > PASSWORD_POLICY_MAX_BYTES
        || preg_match('/[A-Z]/', $password) !== 1
        || preg_match('/[a-z]/', $password) !== 1
        || preg_match('/[0-9]/', $password) !== 1
        || preg_match('/[^A-Za-z0-9]/', $password) !== 1
    ) {
        return ['valid' => false, 'message' => $message];
    }

    return ['valid' => true, 'message' => ''];
}
