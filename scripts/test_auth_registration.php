<?php
/** Static regression checks for the accessible registration email indicator. */

function auth_registration_test_assert(bool $condition, string $message): void
{
    if (!$condition) exit("Registration auth test failed: {$message}\n");
}

function auth_registration_test_contains(string $source, string $needle, string $message): void
{
    auth_registration_test_assert(strpos($source, $needle) !== false, $message);
}

$auth_source = file_get_contents(__DIR__ . '/../auth.php');
$js_source = file_get_contents(__DIR__ . '/../assets/js/auth.js');
$css_source = file_get_contents(__DIR__ . '/../assets/css/auth.css');
$server_source = file_get_contents(__DIR__ . '/../actions/auth/register_process.php');

auth_registration_test_assert($auth_source !== false, 'registration markup can be inspected');
auth_registration_test_assert($js_source !== false, 'registration validation script can be inspected');
auth_registration_test_assert($css_source !== false, 'registration styles can be inspected');
auth_registration_test_assert($server_source !== false, 'registration endpoint can be inspected');

auth_registration_test_assert(
    preg_match('/<label\s+for="reg-email">\s*EMAIL ADDRESS\s*<\/label>/', $auth_source) === 1,
    'registration email label is associated with the input'
);
foreach ([
    'autocomplete="email"',
    'aria-describedby="err-email"',
    'aria-errormessage="err-email"',
    'aria-invalid="false"',
    'id="err-email" role="status" aria-live="polite"',
] as $markup_contract) {
    auth_registration_test_contains($auth_source, $markup_contract, "markup includes {$markup_contract}");
}

foreach ([
    'Email address is required.',
    'Enter a valid email address, such as name@example.com.',
    'Email address looks valid.',
    'function isRegistrationEmailFormatValid(value, nativeTypeMismatch = false)',
    'email.validity.typeMismatch',
    'value.indexOf(\'@\')',
    'value.lastIndexOf(\'@\')',
    'labels.length < 2',
    'label.startsWith(\'-\')',
    'label.endsWith(\'-\')',
    'labels[labels.length - 1]',
    'function updateRegistrationEmailState',
    "email.addEventListener('blur'",
    "email.addEventListener('input'",
    "updateRegistrationEmailState({ normalize: true })",
    'if (!emailIsValid) email.focus();',
    'const value = email.value.trim();',
    'if (normalize && isValid) email.value = value;',
] as $js_contract) {
    auth_registration_test_contains($js_source, $js_contract, "validation includes {$js_contract}");
}

foreach (['.form-control.email-invalid', '.form-control.email-valid', '.email-feedback-invalid', '.email-feedback-valid'] as $css_contract) {
    auth_registration_test_contains($css_source, $css_contract, "styles include {$css_contract}");
}

auth_registration_test_contains($server_source, '$email = trim((string)($_POST[\'email\'] ?? \'\'));', 'server-side email trimming remains enabled');
auth_registration_test_contains($server_source, '!filter_var($email, FILTER_VALIDATE_EMAIL)', 'server-side email validation remains authoritative');

foreach ([
    'name+tag@example.co.uk' => true,
    'test@gmail..com' => false,
    'a..b@example.com' => false,
    'test@-gmail.com' => false,
    'test@gmail-.com' => false,
    'user@localhost' => false,
] as $fixture => $expected_valid) {
    $actual_valid = filter_var($fixture, FILTER_VALIDATE_EMAIL) !== false;
    auth_registration_test_assert(
        $actual_valid === $expected_valid,
        "PHP email validation fixture parity ({$fixture})"
    );
}

echo "Registration auth accessibility checks passed\n";
