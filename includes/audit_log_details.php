<?php

/** Decode only the small, event-specific audit detail allowlist used by the UI. */
function audit_log_safe_details(?string $json, ?string $eventType): array
{
    if (!is_string($json) || trim($json) === '' || !is_string($eventType) || $eventType === '') {
        return [];
    }

    try {
        $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        return [];
    }
    if (!is_array($decoded) || array_is_list($decoded)) {
        return [];
    }

    $allowedFields = match ($eventType) {
        'authentication.login_success' => ['role'],
        'payment.manual_proof_approved' => ['booking_reference', 'payment_method', 'amount', 'submission_id', 'payment_id'],
        'payment.manual_proof_rejected' => ['booking_reference', 'payment_method', 'amount', 'submission_id', 'reason'],
        'booking.management_action' => ['booking_reference', 'decision'],
        'booking.refund_processed' => ['booking_reference', 'booking_id', 'cancellation_id', 'refund_amount', 'decision', 'refund_transaction_reference_masked'],
        'booking.refund_rejected' => ['booking_reference', 'booking_id', 'cancellation_id', 'refund_amount', 'decision', 'reason'],
        default => [],
    };

    $safe = [];
    foreach ($allowedFields as $field) {
        if (!array_key_exists($field, $decoded)) {
            continue;
        }
        $value = $decoded[$field];
        if (in_array($field, ['amount', 'refund_amount'], true)) {
            if ((is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) && is_finite((float)$value) && (float)$value >= 0) {
                $safe[$field] = (float)$value;
            }
            continue;
        }
        if (in_array($field, ['submission_id', 'payment_id', 'booking_id', 'cancellation_id'], true)) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false) {
                $safe[$field] = $id;
            }
            continue;
        }
        if (!is_string($value)) {
            continue;
        }

        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        $value = trim($value);
        $maxLength = $field === 'reason' ? 500 : ($field === 'booking_reference' ? 80 : 160);
        if ($field === 'payment_method' && !in_array($value, ['GCash', 'Maya', 'Bank Transfer', 'Cash'], true)) {
            continue;
        }
        if ($field === 'role' && !in_array($value, ['admin', 'staff'], true)) {
            continue;
        }
        if ($field === 'decision') {
            $allowedDecisions = match ($eventType) {
                'booking.refund_processed' => ['processed'],
                'booking.refund_rejected' => ['rejected'],
                default => ['confirm', 'cancel', 'finalize_event_invoice', 'add_payment', 'reschedule', 'reject_reschedule', 'refund', 'reject_refund'],
            };
            if (!in_array($value, $allowedDecisions, true)) {
                continue;
            }
        }
        if ($field === 'refund_transaction_reference_masked' && !preg_match('/\A\*{4,12}[A-Za-z0-9]{0,4}\z/D', $value)) {
            continue;
        }
        if ($value !== '') {
            if (preg_match('/\A.{0,' . $maxLength . '}/us', $value, $truncated)) {
                $value = $truncated[0];
            }
            $safe[$field] = $value;
        }
    }

    return $safe;
}
