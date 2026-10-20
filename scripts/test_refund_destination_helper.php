<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/refund_helper.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$throws = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
    } catch (InvalidArgumentException $error) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};

$gcash = normalize_refund_destination([
    'method' => 'GCash',
    'account_name' => '  Maria   Dela Cruz ',
    'account_identifier' => '09 17-123-4567',
    'bank_name' => 'ignored for wallets',
]);
$assert($gcash === [
    'method' => 'GCash',
    'account_name' => 'Maria Dela Cruz',
    'account_identifier' => '+639171234567',
    'bank_name' => null,
], 'GCash destinations trim names, normalize common local mobile format, and discard irrelevant bank data.');

$maya = normalize_refund_destination([
    'method' => 'Maya',
    'account_name' => 'Jo Santos',
    'account_identifier' => '+63 917-123-4567',
]);
$assert($maya['account_identifier'] === '+639171234567' && $maya['bank_name'] === null, 'Maya accepts +63 mobile input and stores the same canonical identifier.');
$assert(normalize_refund_wallet_identifier('63917 123 4567') === '+639171234567', 'Country-code mobile input is normalized consistently.');
$assert(normalize_refund_wallet_identifier('9171234567') === '+639171234567', 'Ten-digit domestic mobile input without the leading zero is accepted.');

$bank = normalize_refund_destination([
    'method' => 'Bank Transfer',
    'account_name' => '  José   Reyes ',
    'account_identifier' => ' 0012-3456 AB ',
    'bank_name' => '  Bank   of Manila ',
]);
$assert($bank['account_name'] === 'José Reyes' && $bank['account_identifier'] === '0012-3456 AB' && $bank['bank_name'] === 'Bank of Manila', 'Bank transfers preserve allowed account characters and normalize bounded text fields.');
$assert(refund_destination_is_valid([
    'refund_destination_method' => 'Bank Transfer',
    'refund_destination_account_name' => 'Maria Cruz',
    'refund_destination_account_identifier' => '00123456789',
    'refund_destination_bank_name' => 'Metro Bank',
]), 'A complete persisted destination passes the same backend validation used before processing.');
$assert(!refund_destination_is_valid([
    'refund_destination_method' => null,
    'refund_destination_account_name' => null,
    'refund_destination_account_identifier' => null,
    'refund_destination_bank_name' => null,
]), 'Legacy rows without destination details fail closed.');

$masked = mask_refund_destination_identifier('+639171234567');
$customerDestination = refund_destination_for_customer([
    'refund_destination_method' => 'GCash',
    'refund_destination_account_name' => 'Maria Dela Cruz',
    'refund_destination_account_identifier' => '+639171234567',
    'refund_destination_bank_name' => null,
]);
$assert($masked === '•••• 4567' && $customerDestination['masked_identifier'] === $masked, 'Customer-safe destinations expose only the final four identifier characters.');
$assert(!str_contains(json_encode($customerDestination, JSON_THROW_ON_ERROR), '+639171234567'), 'Customer-safe JSON never contains the full wallet identifier.');

$refund = calculate_refund_breakdown(1250.555);
$assert($refund === ['fee_percent' => 0.0, 'fee' => 0.0, 'refund' => 1250.56], 'New paid refunds return the full rounded paid amount without consulting the retired processing-fee setting.');
$assert(calculate_refund_breakdown(0.0) === ['fee_percent' => 0.0, 'fee' => 0.0, 'refund' => 0.0]
    && calculate_refund_breakdown(-30.0)['refund'] === 0.0, 'Zero and negative paid amounts cannot create negative refund estimates.');

$throws(static fn() => normalize_refund_destination(['method' => 'Cash', 'account_name' => 'Maria Cruz', 'account_identifier' => '09171234567']), 'Only the three controlled refund methods are accepted.');
$throws(static fn() => normalize_refund_destination(['method' => 'GCash', 'account_name' => 'A', 'account_identifier' => '09171234567']), 'Account holder names have a minimum length.');
$throws(static fn() => normalize_refund_destination(['method' => 'Maya', 'account_name' => "Maria\nCruz", 'account_identifier' => '09171234567']), 'Control characters are rejected from names.');
$throws(static fn() => normalize_refund_destination(['method' => 'GCash', 'account_name' => str_repeat('N', 121), 'account_identifier' => '09171234567']), 'Account holder names are bounded.');
$throws(static fn() => normalize_refund_destination(['method' => 'Bank Transfer', 'account_name' => 'Maria Cruz', 'account_identifier' => '12345678', 'bank_name' => "Metro\tBank"]), 'Control characters are rejected from bank names.');
$throws(static fn() => normalize_refund_destination(['method' => 'Bank Transfer', 'account_name' => 'Maria Cruz', 'account_identifier' => '12345678', 'bank_name' => str_repeat('B', 101)]), 'Bank names are bounded.');
$throws(static fn() => normalize_refund_wallet_identifier('0912345678'), 'Wallet numbers with invalid length are rejected.');
$throws(static fn() => normalize_refund_wallet_identifier('+63917123abcd'), 'Wallet identifiers must contain a valid Philippine mobile number.');
$throws(static fn() => normalize_refund_destination(['method' => 'Bank Transfer', 'account_name' => 'Maria Cruz', 'account_identifier' => '1234/5678', 'bank_name' => 'Metro Bank']), 'Bank account identifiers reject characters outside alphanumerics, spaces, and hyphens.');
$throws(static fn() => normalize_refund_destination(['method' => 'Bank Transfer', 'account_name' => 'Maria Cruz', 'account_identifier' => '12345678']), 'Bank transfers require a bank name.');
$throws(static fn() => normalize_refund_destination(['method' => 'Bank Transfer', 'account_name' => 'Maria Cruz', 'account_identifier' => str_repeat('1', 65), 'bank_name' => 'Metro Bank']), 'Bank account identifiers are bounded.');
$throws(static fn() => normalize_refund_destination(['method' => 'GCash', 'account_name' => ['Maria'], 'account_identifier' => '09171234567']), 'Non-string names are rejected rather than coerced.');

$bankInput = ['method' => 'Bank Transfer', 'account_name' => 'QA Holder', 'bank_name' => 'QA Bank', 'account_identifier' => '00001234'];
foreach (['0', '01', 'A01', '00001234', '001-234 AB', '-001 AB', str_repeat('0', 64)] as $identifier) {
    $actual = normalize_refund_destination(array_replace($bankInput, ['account_identifier' => $identifier]));
    $assert(is_string($actual['account_identifier']) && $actual['account_identifier'] === $identifier, 'Generic bank identifiers retain their exact string and leading zeros without provider-specific minimum lengths.');
}
$assert(normalize_refund_destination(array_replace($bankInput, ['account_identifier' => '  001  234-AB  ']))['account_identifier'] === '001 234-AB', 'Bank identifier formatting normalizes boundary and repeated spaces without numeric conversion.');
foreach (['', '   ', '---', ' - - ', '123/456', '+639171234567', "001\t234", "\n001234", "001234\r", "001\x00234", "001\x7F234", str_repeat('1', 65)] as $identifier) {
    $throws(static fn() => normalize_refund_destination(array_replace($bankInput, ['account_identifier' => $identifier])), 'Empty, unsupported, control-containing, separator-only or overlong bank identifiers are rejected.');
}
foreach (['method', 'account_name', 'bank_name', 'account_identifier'] as $field) {
    $missing = $bankInput;
    unset($missing[$field]);
    $throws(static fn() => normalize_refund_destination($missing), 'Bank transfers require every destination field.');
    foreach ([null, 123, ['value'], true] as $invalidType) {
        $invalid = array_replace($bankInput, [$field => $invalidType]);
        $throws(static fn() => normalize_refund_destination($invalid), 'Destination fields reject non-string values without coercion.');
        $assert(!refund_destination_is_valid($invalid), 'Persisted destination checks fail closed on malformed field types.');
    }
    $throws(static fn() => normalize_refund_destination(array_replace($bankInput, [$field => "\n" . $bankInput[$field]])), 'Leading controls cannot be silently trimmed from destination fields.');
}
foreach (['Maya Wallet', 'PayMaya', 'bank transfer', 'Unknown'] as $method) {
    $throws(static fn() => normalize_refund_destination(array_replace($bankInput, ['method' => $method])), 'Persisted refund methods retain their exact controlled values.');
}
foreach (['GCash', 'Maya'] as $method) {
    foreach (['09171234567', '9171234567', '639171234567', '+63 917-123-4567'] as $identifier) {
        $wallet = normalize_refund_destination(array_replace($bankInput, ['method' => $method, 'account_identifier' => $identifier]));
        $assert($wallet['method'] === $method && $wallet['account_identifier'] === '+639171234567' && $wallet['bank_name'] === null, 'Known wallet methods preserve their stored method and normalize Philippine mobile formats.');
    }
    foreach (['001234', '02123456789', '+129171234567', '091712345678', '0917123456', "\t09171234567", "0917\n1234567"] as $identifier) {
        $throws(static fn() => normalize_refund_destination(array_replace($bankInput, ['method' => $method, 'account_identifier' => $identifier])), 'Wallet destinations reject generic bank, landline, foreign, wrong-length and control-containing identifiers.');
    }
}
$throws(static fn() => normalize_refund_wallet_identifier("0917\t1234567"), 'Direct wallet normalization also rejects control characters.');

echo "Refund destination helper checks passed ({$assertions} assertions).\n";
