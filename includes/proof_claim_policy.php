<?php
declare(strict_types=1);

/**
 * Pure policy for the agreed proof-claim transitions. This is deliberately not
 * wired into booking writes until expiry and second-rejection policy is set.
 * Callers must acquire inventory locks and verify the claim before persisting
 * any transition; uploaded proof is never treated as verified payment.
 */
function proof_claim_transition(array $claim, string $event, DateTimeImmutable $at, string $eventId): array
{
    if ($eventId === '' || strlen($eventId) > 100) throw new InvalidArgumentException('A transition identifier is required.');
    if (($claim['last_event_id'] ?? null) === $eventId) return $claim;

    $state = (string)($claim['state'] ?? 'open');
    $next = $claim;
    $timestamp = $at->setTimezone(new DateTimeZone('Asia/Manila'));
    if ($state === 'open' && $event === 'submit_first_proof') {
        $next['state'] = 'pending_review';
        $next['first_submitted_at'] = $timestamp->format('Y-m-d H:i:s');
        $next['hold_expires_at'] = $timestamp->modify('+24 hours')->format('Y-m-d H:i:s');
        $next['remaining_resubmissions'] = 1;
    } elseif ($state === 'pending_review' && $event === 'reject_first_proof') {
        $next['state'] = 'retry_allowed';
        $next['hold_expires_at'] = $timestamp->modify('+24 hours')->format('Y-m-d H:i:s');
    } elseif ($state === 'retry_allowed' && $event === 'submit_retry_proof' && (int)($claim['remaining_resubmissions'] ?? 0) === 1) {
        $next['state'] = 'retry_pending_review';
        $next['remaining_resubmissions'] = 0;
        // Resubmitting does not restart the rejection-based clock.
    } elseif (in_array($state, ['pending_review', 'retry_pending_review'], true) && $event === 'approve_verified_payment') {
        $next['state'] = 'confirmed';
        $next['hold_expires_at'] = null;
    } else {
        // In particular: no guessed second-rejection or unreviewed-expiry rule.
        throw new LogicException('Proof-claim transition is unsupported or awaiting policy.');
    }
    $next['last_event_id'] = $eventId;
    return $next;
}
