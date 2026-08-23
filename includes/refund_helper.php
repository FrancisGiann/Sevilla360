<?php
function get_refund_fee_percent(mysqli $conn): float {
    $percent = 3.0;
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'refund_fee_percent' LIMIT 1");
    if ($stmt && $stmt->execute()) {
        $value = $stmt->get_result()->fetch_assoc()['setting_value'] ?? null;
        if ($value !== null && is_numeric($value) && is_finite((float)$value)) $percent = (float)$value;
    }
    return max(0.0, min(100.0, round($percent, 4)));
}

function calculate_refund_breakdown(mysqli $conn, float $amountPaid): array {
    $amountPaid = max(0.0, round($amountPaid, 2));
    $feePercent = get_refund_fee_percent($conn);
    $fee = $amountPaid > 0 ? round($amountPaid * $feePercent / 100, 2) : 0.0;
    return ['fee_percent' => $feePercent, 'fee' => $fee, 'refund' => max(0.0, round($amountPaid - $fee, 2))];
}

function record_cancellation_history(mysqli $conn, int $bookingId, ?int $cancellationId, string $action, string $reason, float $refundAmount, float $feeDeducted, float $feePercent, ?string $adminReply, ?int $actorUserId): void {
    $allowed = ['requested', 'reopened', 'rejected', 'processed', 'cancelled'];
    if (!in_array($action, $allowed, true)) throw new RuntimeException('Invalid cancellation history action.');
    $stmt = $conn->prepare('INSERT INTO cancellation_history (booking_id, cancellation_id, action, reason, refund_amount, fee_deducted, fee_percent, admin_reply, actor_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) throw new RuntimeException('Unable to record cancellation history.');
    $stmt->bind_param('iissdddsi', $bookingId, $cancellationId, $action, $reason, $refundAmount, $feeDeducted, $feePercent, $adminReply, $actorUserId);
    if (!$stmt->execute()) throw new RuntimeException('Unable to record cancellation history.');
}
?>
