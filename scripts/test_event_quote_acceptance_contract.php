<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$endpoint = file_get_contents($root . '/actions/user/accept_event_quote.php');
if (!is_string($endpoint)) {
    throw new RuntimeException('Unable to read Event Hall acceptance contract sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($endpoint, '$statement = $store->db->prepare($updateSql)')
    && str_contains($endpoint, '$statement->bind_param(')
    && str_contains($endpoint, '$statement->affected_rows')
    && str_contains($endpoint, '$updatedRows !== 1')
    && !str_contains($endpoint, '$store->db->affected_rows'),
    'Acceptance verifies the prepared UPDATE statement affected-row count.');

$assert(str_contains($endpoint, '$storedQuoteVersion === 0')
    && str_contains($endpoint, '(int)$quoteVersion === 1')
    && str_contains($endpoint, '$legacyFinalizedQuote =')
    && str_contains($endpoint, 'UPDATE bookings SET quote_version=1,quote_accepted=1 WHERE id=? AND quote_version=0 AND quote_accepted=0'),
    'An eligible legacy stored version 0 accepts only displayed/submitted version 1 and upgrades it atomically.');

$staleGuard = strpos($endpoint, 'if (($storedQuoteVersion !== (int)$quoteVersion && !$legacyFinalizedQuote)');
$legacyBranch = strpos($endpoint, 'if ($legacyFinalizedQuote) {');
$assert($staleGuard !== false && $legacyBranch !== false && $staleGuard < $legacyBranch,
    'A genuinely stale quote version is rejected before either acceptance UPDATE.');

preg_match_all('/UPDATE bookings SET ([^\"]+)/', $endpoint, $updates);
$updatesDoNotResetDeadline = count($updates[1]) === 2;
foreach ($updates[1] ?? [] as $update) {
    $setClause = explode(' WHERE ', $update, 2)[0];
    if (str_contains($setClause, 'payment_due_at')) $updatesDoNotResetDeadline = false;
}
$assert($updatesDoNotResetDeadline && str_contains($endpoint, "'payment_due_at' => (string)\$booking['payment_due_at']"),
    'Both acceptance paths preserve and return the originally finalized payment deadline.');

echo "Event Hall quote acceptance contract checks passed ({$assertions} assertions).\n";
