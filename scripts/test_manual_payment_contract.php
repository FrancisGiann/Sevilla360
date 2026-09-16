<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$customerCancel = $read('actions/user/request_cancel.php');
$adminStatus = $read('actions/admin/update_booking_status.php');
$manualPayment = $read('includes/manual_payment.php');
$customerDashboard = $read('user_dashboard.php');
$paymentDetailsEndpoint = $read('actions/user/get_manual_payment_details.php');
$manualPaymentSettingsStart = strpos($manualPayment, 'function manual_payment_load_instructions(');
$manualPaymentSettingsEnd = strpos($manualPayment, 'function manual_payment_decode_instructions(', $manualPaymentSettingsStart === false ? 0 : $manualPaymentSettingsStart);
$manualPaymentSettings = $manualPaymentSettingsStart === false || $manualPaymentSettingsEnd === false
    ? ''
    : substr($manualPayment, $manualPaymentSettingsStart, $manualPaymentSettingsEnd - $manualPaymentSettingsStart);
$bookingSubmissionEndpoint = $read('actions/bookings/submit_online.php');
$proofSubmissionEndpoint = $read('actions/user/submit_manual_payment.php');
$paymentClient = $read('assets/js/manual_payment.js');
$paymentCss = $read('assets/css/manual_payment.css');
$paymentModal = $read('includes/partials/manual_payment_modal.php');
$bookingClient = $read('assets/js/booking.js');
$dashboardClient = $read('assets/js/user_dashboard.js');
$checks = [];

$unpaidBranchStart = strpos($customerCancel, '// SCENARIO B: They haven\'t paid anything yet! Instantly cancel it.');
$unpaidBranchEnd = strpos($customerCancel, '// Clean up any pending reschedule request', $unpaidBranchStart === false ? 0 : $unpaidBranchStart);
$unpaidCancelBranch = $unpaidBranchStart === false || $unpaidBranchEnd === false
    ? ''
    : substr($customerCancel, $unpaidBranchStart, $unpaidBranchEnd - $unpaidBranchStart);
$customerRejectPosition = strpos($unpaidCancelBranch, 'manual_payment_reject_pending_for_terminal_booking');
$customerCancelUpdatePosition = strpos($unpaidCancelBranch, "UPDATE bookings SET booking_status = 'Cancelled'");
$checks['unpaid customer cancellation closes pending proof before cancelling in the same path'] = str_contains($customerCancel, "require_once __DIR__ . '/../../includes/manual_payment.php';")
    && $customerRejectPosition !== false
    && $customerCancelUpdatePosition !== false
    && $customerRejectPosition < $customerCancelUpdatePosition
    && str_contains($unpaidCancelBranch, 'null, \'Customer cancelled the unpaid booking before payment proof review.\'');
$checks['paid customer cancellation keeps its refund-request path separate'] = strpos($customerCancel, 'if ($amount_paid > 0) {') !== false
    && strpos($customerCancel, 'if ($amount_paid > 0) {') < $unpaidBranchStart
    && !str_contains(substr($customerCancel, strpos($customerCancel, 'if ($amount_paid > 0) {'), $unpaidBranchStart - strpos($customerCancel, 'if ($amount_paid > 0) {')), 'manual_payment_reject_pending_for_terminal_booking');

$eventBranchStart = strpos($adminStatus, "elseif (\$action === 'finalize_event_invoice')");
$eventBranchEnd = strpos($adminStatus, "elseif (\$action === 'reschedule')", $eventBranchStart === false ? 0 : $eventBranchStart);
$eventBranch = $eventBranchStart === false || $eventBranchEnd === false
    ? ''
    : substr($adminStatus, $eventBranchStart, $eventBranchEnd - $eventBranchStart);
$quoteGuardPosition = strpos($eventBranch, 'manual_payment_assert_no_pending_for_quote');
$firstQuoteMutation = strpos($eventBranch, 'reallocate_event_hall_addons(');
$checks['Event Hall quote mutations guard pending proof under the locked booking transaction'] = $quoteGuardPosition !== false
    && $firstQuoteMutation !== false
    && $quoteGuardPosition < $firstQuoteMutation
    && strpos($adminStatus, '$stmt_booking_lock') < $eventBranchStart;
$checks['Event Hall quote guard blocks only when an actual pending proof exists'] = str_contains($manualPayment, "WHERE booking_id = ? AND status = 'pending' LIMIT 1 FOR UPDATE")
    && str_contains($manualPayment, "if (\$stmt->get_result()->num_rows > 0)")
    && str_contains($manualPayment, 'Approve or reject it before changing the Event Hall quote.');
$checks['zero configured payment methods are a valid details response'] = str_contains($paymentDetailsEndpoint, "'expected_amount' => manual_payment_expected_amount(\$booking)")
    && str_contains($paymentDetailsEndpoint, "'payment_due_at' => \$booking['payment_due_at']")
    && str_contains($paymentDetailsEndpoint, "'methods' => \$methods")
    && !str_contains($paymentDetailsEndpoint, "if (!\$methods) throw")
    && str_contains($manualPaymentSettings, 'if (!$stmt) throw new RuntimeException(')
    && str_contains($manualPaymentSettings, 'if (!$stmt->execute()) {')
    && str_contains($manualPaymentSettings, 'Unable to load customer payment methods.');
$checks['booking id and proof amount remain server-owned'] = str_contains($bookingSubmissionEndpoint, "\$pricing = calculate_booking_price(")
    && str_contains($bookingSubmissionEndpoint, "\$true_total = \$pricing['true_total'];")
    && str_contains($bookingSubmissionEndpoint, "echo 'Success|' . \$ref_no . '|' . (int)\$booking_id;")
    && !str_contains($bookingSubmissionEndpoint, '$_POST[\'total_amount\']')
    && !str_contains($bookingSubmissionEndpoint, '$_POST[\'base_amount\']')
    && str_contains($proofSubmissionEndpoint, "'expected_amount' => \$expectedAmount")
    && str_contains($proofSubmissionEndpoint, '$expectedAmount = manual_payment_expected_amount($booking);');
$checks['proof submission retains CSRF, ownership, deadline, and private receipt guards'] = str_contains($proofSubmissionEndpoint, 'hash_equals($_SESSION[\'csrf_token\'], $csrf)')
    && str_contains($proofSubmissionEndpoint, 'WHERE b.id = ? AND c.user_id = ? FOR UPDATE')
    && str_contains($proofSubmissionEndpoint, 'payment window has expired')
    && str_contains($proofSubmissionEndpoint, 'manual_payment_store_proof((string)$proof[\'tmp_name\'])')
    && str_contains($proofSubmissionEndpoint, 'manual_payment_submission_decision($pendingSubmission, $existing, (int)$bookingId)')
    && str_contains($manualPayment, 'A payment proof is already awaiting review.');
$checks['payment-proof submission emits in-app and realtime admin signals without outbound mail'] = str_contains($proofSubmissionEndpoint, 'create_user_notification(')
    && str_contains($proofSubmissionEndpoint, "realtime_enqueue_event(\$conn, 'admin', 'payment.proof_submitted'")
    && !preg_match('/\b(?:mail|send_[a-z_]+|PHPMailer|mailer)\s*\(/i', $proofSubmissionEndpoint);
$checks['receipt conversion prefers efficient formats while leaving QR conversion unchanged'] = str_contains($manualPayment, 'function manual_payment_resize_proof_image(GdImage $image, int $maximumEdge = 1920)')
    && str_contains($manualPayment, 'imagewebp($image, $stagingPath, 82)')
    && str_contains($manualPayment, 'imagejpeg($flattened, $stagingPath, 82)')
    && str_contains($manualPayment, 'manual_payment_apply_jpeg_orientation($image, manual_payment_jpeg_orientation($temporaryPath))')
    && str_contains($manualPayment, 'imagecopyresampled($resized, $image')
    && str_contains($manualPayment, 'return manual_payment_store_verified_image($temporaryPath, $directory, 0644);');
$checks['pending replacement locks and updates one row, then removes its old file only after commit'] = str_contains($proofSubmissionEndpoint, "WHERE booking_id = ? AND status = 'pending' ORDER BY id FOR UPDATE")
    && str_contains($proofSubmissionEndpoint, 'count($pendingRows) > 1')
    && str_contains($proofSubmissionEndpoint, 'manual_payment_submission_decision($pendingSubmission, $existing, (int)$bookingId)')
    && str_contains($proofSubmissionEndpoint, "WHERE id = ? AND booking_id = ? AND status = 'pending'")
    && str_contains($proofSubmissionEndpoint, 'reference_fingerprint = ?')
    && str_contains($proofSubmissionEndpoint, 'submitted_at = NOW()')
    && str_contains($proofSubmissionEndpoint, '\'replacement\' => $decision === \'replace_pending\'')
    && strpos($proofSubmissionEndpoint, '$conn->commit()') < strpos($proofSubmissionEndpoint, '@unlink(manual_payment_proof_file_path($previousProofFilename))')
    && str_contains($proofSubmissionEndpoint, 'if ($stored !== null) @unlink($stored[\'path\']);')
    && str_contains($proofSubmissionEndpoint, 'Customer replaced pending ')
    && str_contains($proofSubmissionEndpoint, 'Payment Proof Replaced');
$checks['customer payment details permit replacement safely and prefill only method/reference metadata'] = str_contains($paymentDetailsEndpoint, 'SELECT status, payment_method, transaction_reference, rejection_reason, submitted_at')
    && str_contains($paymentDetailsEndpoint, '\'submission_mode\' => $isPendingReplacement ? \'replace_pending\'')
    && str_contains($paymentDetailsEndpoint, 'if (!$isPendingReplacement &&')
    && !str_contains($paymentDetailsEndpoint, 'proof_filename')
    && str_contains($paymentClient, "this.submitButtonLabel = 'Replace proof'")
    && str_contains($paymentClient, "this.title.textContent = 'Replace payment proof'")
    && str_contains($paymentClient, 'latestSubmission.transaction_reference')
    && str_contains($paymentModal, 'name="receipt_image"')
    && str_contains($paymentModal, 'required');
$checks['both payment endpoints block proof submit/replacement during pending cancellation or reschedule'] = str_contains($paymentDetailsEndpoint, "EXISTS (SELECT 1 FROM cancellations cx WHERE cx.booking_id = b.id AND cx.status = 'Pending') AS pending_cancel_request")
    && str_contains($paymentDetailsEndpoint, "EXISTS (SELECT 1 FROM reschedule_requests rr WHERE rr.booking_id = b.id AND rr.status = 'Pending') AS pending_reschedule_request")
    && str_contains($paymentDetailsEndpoint, 'if ((int)$booking[\'pending_cancel_request\'] === 1)')
    && str_contains($paymentDetailsEndpoint, 'if ((int)$booking[\'pending_reschedule_request\'] === 1)')
    && str_contains($proofSubmissionEndpoint, "EXISTS (SELECT 1 FROM cancellations cx WHERE cx.booking_id = b.id AND cx.status = 'Pending') AS pending_cancel_request")
    && str_contains($proofSubmissionEndpoint, "EXISTS (SELECT 1 FROM reschedule_requests rr WHERE rr.booking_id = b.id AND rr.status = 'Pending') AS pending_reschedule_request")
    && str_contains($proofSubmissionEndpoint, "SELECT id FROM cancellations WHERE booking_id = ? AND status = 'Pending' LIMIT 1 FOR UPDATE")
    && str_contains($proofSubmissionEndpoint, "SELECT id FROM reschedule_requests WHERE booking_id = ? AND status = 'Pending' ORDER BY id LIMIT 1 FOR UPDATE")
    && str_contains($proofSubmissionEndpoint, 'if ((int)$booking[\'pending_cancel_request\'] === 1)')
    && str_contains($proofSubmissionEndpoint, 'if ((int)$booking[\'pending_reschedule_request\'] === 1)')
    && strpos($proofSubmissionEndpoint, 'WHERE b.id = ? AND c.user_id = ? FOR UPDATE') < strpos($proofSubmissionEndpoint, '$cancelRequestLock = $conn->prepare(')
    && strpos($proofSubmissionEndpoint, '$rescheduleRequestLock = $conn->prepare(') < strpos($proofSubmissionEndpoint, 'if ((int)$booking[\'pending_cancel_request\'] === 1)')
    && str_contains($proofSubmissionEndpoint, 'Wait for the cancellation or refund request to be reviewed')
    && str_contains($proofSubmissionEndpoint, 'Wait for the reschedule request to be reviewed');
$checks['dashboard exposes replacement actions for pending proofs in overview and booking table'] = str_contains($customerDashboard, 'if (!empty($booking[\'manual_payment_pending\'])) return \'Replace proof\';')
    && str_contains($customerDashboard, 'if (!$is_completed && $can_submit_manual_payment($b))')
    && str_contains($customerDashboard, '!empty($booking[\'cancel_pending\'])')
    && str_contains($customerDashboard, '($booking[\'cancel_status\'] ?? \'\') === \'Pending\'')
    && str_contains($customerDashboard, '!empty($booking[\'resched_pending\'])')
    && str_contains($customerDashboard, '($booking[\'resched_status\'] ?? \'\') === \'Pending\'')
    && str_contains($customerDashboard, 'manual_payment_action_label($upcoming_booking)')
    && str_contains($customerDashboard, 'manual_payment_action_label($b)')
    && str_contains($customerDashboard, 'if (!$hasPendingProof &&');
$checks['payment client renders server values before handling the empty methods state'] = strpos($paymentClient, "this.booking.textContent = data.reference_no.trim()") < strpos($paymentClient, 'if (!this.methods.length)')
    && strpos($paymentClient, 'this.amount.textContent = new Intl.NumberFormat') < strpos($paymentClient, 'if (!this.methods.length)')
    && strpos($paymentClient, 'this.deadline.textContent = this.formatDeadline') < strpos($paymentClient, 'if (!this.methods.length)')
    && str_contains($paymentClient, 'The resort has not configured payment methods yet. Your booking is reserved; contact the resort')
    && str_contains($paymentClient, "{ contact: true }");
$checks['details failures replace loading placeholders and provide retry recovery'] = str_contains($paymentClient, "this.booking.textContent = 'Unavailable'")
    && str_contains($paymentClient, "this.amount.textContent = 'Unavailable'")
    && str_contains($paymentClient, "this.deadline.textContent = 'Unavailable'")
    && str_contains($paymentClient, "{ retry: true, contact: true }")
    && str_contains($paymentModal, 'manual-payment-retry');
$checks['proof submission blocks repeats and continues to use CSRF-protected endpoint'] = str_contains($paymentClient, 'if (this.isSubmitting || !this.detailsReady || !this.methods.length) return;')
    && str_contains($paymentClient, "headers: { 'X-CSRF-Token': this.options.csrfToken || '', Accept: 'application/json' }")
    && str_contains($paymentClient, "actions/user/submit_manual_payment.php")
    && str_contains($paymentClient, 'new FormData(this.form)');
$checks['shared payment dialog preserves accessible semantics and dashboard controller'] = str_contains($paymentModal, 'role="dialog" aria-modal="true"')
    && str_contains($paymentModal, 'aria-labelledby="manual-payment-title"')
    && str_contains($paymentClient, "event.key === 'Escape'")
    && str_contains($paymentClient, "event.key !== 'Tab'")
    && str_contains($dashboardClient, "window.ManualPayment?.create({ csrfToken, openModal, closeModal })");
$checks['payment QR is centered below instructions with same-page zoom and save actions'] = str_contains($paymentModal, 'id="manual-payment-qr-panel"')
    && str_contains($paymentModal, 'id="manual-payment-qr-view"')
    && str_contains($paymentModal, 'aria-controls="manual-payment-qr-lightbox"')
    && str_contains($paymentModal, 'id="manual-payment-qr-lightbox"')
    && str_contains($paymentModal, 'role="region" aria-label="Enlarged payment QR code"')
    && !str_contains($paymentModal, 'target="_blank"')
    && str_contains($paymentModal, 'id="manual-payment-qr-close"')
    && str_contains($paymentModal, 'id="manual-payment-qr-save"')
    && str_contains($paymentClient, 'GCash’s QR scanner')
    && str_contains($paymentClient, 'this.qrLightboxStage.appendChild(this.qr)')
    && str_contains($paymentClient, 'event.target === this.qrLightbox')
    && str_contains($paymentClient, 'this.qrLightboxStage?.contains(event.target) && !this.qr.contains(event.target)')
    && str_contains($paymentClient, 'this.closeQrZoom()')
    && str_contains($paymentClient, 'event.stopImmediatePropagation()')
    && str_contains($paymentClient, "document.addEventListener('keydown', (event) => this.handleKeydown(event), true)")
    && str_contains($paymentClient, 'this.qrZoomInvoker = this.qrView')
    && str_contains($paymentClient, "this.qr.classList.remove('is-zoomed')")
    && str_contains($paymentClient, "if (!this.modal.classList.contains('active')) this.clearQr()")
    && str_contains($paymentCss, '.manual-payment-qr-panel {')
    && str_contains($paymentCss, 'object-fit: contain;')
    && str_contains($paymentCss, 'height: min(52dvh, 440px);')
    && str_contains($paymentCss, 'width: 44px;')
    && str_contains($paymentCss, 'height: 44px;')
    && str_contains($paymentCss, 'max-height: min(82dvh, 900px);')
    && str_contains($paymentCss, '@media (prefers-reduced-motion: reduce)');
$checks['QR actions reset the single dynamic image and use only same-origin vetted paths'] = str_contains($paymentClient, "this.qrSave.removeAttribute('download')")
    && str_contains($paymentClient, "this.qr.removeAttribute('src')")
    && str_contains($paymentClient, "this.qrView.setAttribute('aria-expanded', 'false')")
    && str_contains($paymentClient, "this.qrGuidance.textContent = ''")
    && str_contains($paymentClient, "^assets\\/uploads\\/payment-qrs\\/[a-f0-9]{48}\\.(jpg|png|webp)$")
    && str_contains($paymentClient, "gcash-payment-qr")
    && str_contains($paymentClient, "maya-payment-qr")
    && str_contains($paymentClient, "bank-transfer-payment-qr");
$proofResponsePosition = strpos($paymentClient, "if (!response.ok || !result?.success)");
$afterProofPosition = strpos($paymentClient, 'this.options.onSubmitted(result)');
$checks['booking proof success routes to My Bookings only after submission'] = str_contains($bookingClient, "onSubmitted: () => {")
    && str_contains($bookingClient, "window.location.assign('user_dashboard.php')")
    && $proofResponsePosition !== false
    && $afterProofPosition !== false
    && $afterProofPosition > $proofResponsePosition;

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . '|' . $label . "\n";
    if (!$passed) $failed++;
}
exit($failed === 0 ? 0 : 1);
