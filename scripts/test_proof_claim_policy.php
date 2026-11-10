<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/proof_claim_policy.php';

$checks = 0;
$assert = static function (bool $condition, string $name) use (&$checks): void {
    if (!$condition) throw new RuntimeException($name);
    $checks++;
};
$rejects = static function (callable $operation): bool {
    try { $operation(); } catch (LogicException $error) { return true; }
    return false;
};
$firstAt = new DateTimeImmutable('2026-10-25 09:00:00', new DateTimeZone('Asia/Manila'));
$first = proof_claim_transition(['state' => 'open'], 'submit_first_proof', $firstAt, 'proof:1');
$assert($first['state'] === 'pending_review' && $first['hold_expires_at'] === '2026-10-26 09:00:00', 'first proof starts 24-hour claim');
$assert($first['first_submitted_at'] === '2026-10-25 09:00:00' && $first['remaining_resubmissions'] === 1, 'one retry entitlement');
$assert(proof_claim_transition($first, 'submit_first_proof', $firstAt, 'proof:1') === $first, 'same event id is idempotent');
$assert($rejects(static fn() => proof_claim_transition($first, 'submit_retry_proof', $firstAt, 'proof:2')), 'retry before rejection blocked');

$rejectAt = $firstAt->modify('+3 hours');
$rejected = proof_claim_transition($first, 'reject_first_proof', $rejectAt, 'review:1');
$assert($rejected['state'] === 'retry_allowed' && $rejected['hold_expires_at'] === '2026-10-26 12:00:00', 'first rejection resets 24-hour claim');
$assert($rejected['first_submitted_at'] === $first['first_submitted_at'], 'first proof time preserved');
$retry = proof_claim_transition($rejected, 'submit_retry_proof', $rejectAt->modify('+1 hour'), 'proof:2');
$assert($retry['state'] === 'retry_pending_review' && $retry['remaining_resubmissions'] === 0, 'retry spent exactly once');
$assert($retry['hold_expires_at'] === $rejected['hold_expires_at'], 'resubmission does not reset clock');
$assert($rejects(static fn() => proof_claim_transition($retry, 'submit_retry_proof', $rejectAt, 'proof:3')), 'third proof blocked');
$assert($rejects(static fn() => proof_claim_transition($retry, 'reject_retry_proof', $rejectAt, 'review:2')), 'second rejection awaits policy');
$assert($rejects(static fn() => proof_claim_transition($retry, 'expire_unreviewed', $rejectAt, 'expiry:1')), 'unreviewed expiry awaits policy');
$confirmed = proof_claim_transition($retry, 'approve_verified_payment', $rejectAt, 'review:approved');
$assert($confirmed['state'] === 'confirmed' && $confirmed['hold_expires_at'] === null, 'verified payment confirms without temporary hold');
echo "Proof claim policy: {$checks} checks passed.\n";
