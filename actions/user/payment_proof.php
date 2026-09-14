<?php
require_once __DIR__ . '/../../includes/session_init.php';
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['customer', 'staff', 'admin'], true)) {
    http_response_code(401);
    exit;
}
$submissionId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$submissionId) { http_response_code(404); exit; }
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/manual_payment.php';
try {
    $sql = "SELECT m.proof_filename, m.proof_mime, m.proof_size_bytes, m.proof_sha256, m.status, m.reviewed_at, NOW() AS checked_at FROM manual_payment_submissions m JOIN bookings b ON b.id = m.booking_id JOIN customers c ON c.id = b.customer_id WHERE m.id = ?";
    if (($_SESSION['role'] ?? '') === 'customer') $sql .= ' AND c.user_id = ?';
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Not found.');
    if (($_SESSION['role'] ?? '') === 'customer') {
        $userId = (int)$_SESSION['user_id'];
        $stmt->bind_param('ii', $submissionId, $userId);
    } else {
        $stmt->bind_param('i', $submissionId);
    }
    if (!$stmt->execute()) throw new RuntimeException('Not found.');
    $proof = $stmt->get_result()->fetch_assoc();
    if (!$proof || manual_payment_proof_retention_expired(
        (string)$proof['status'],
        isset($proof['reviewed_at']) ? (string)$proof['reviewed_at'] : null,
        new DateTimeImmutable((string)$proof['checked_at'])
    )) throw new RuntimeException('Not found.');
    if (!$proof || !preg_match('/\A[a-f0-9]{48}\.(?:jpg|png|webp)\z/D', (string)$proof['proof_filename'])) throw new RuntimeException('Not found.');
    $path = manual_payment_proof_file_path((string)$proof['proof_filename']);
    $storedSize = filesize($path);
    $storedHash = hash_file('sha256', $path);
    if ($storedSize === false || $storedSize !== (int)$proof['proof_size_bytes'] || !is_string($storedHash) || !hash_equals((string)$proof['proof_sha256'], $storedHash)) throw new RuntimeException('Not found.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || $mime !== $proof['proof_mime']) throw new RuntimeException('Not found.');
    $extension = match ($mime) { 'image/jpeg' => 'jpg', 'image/png' => 'png', default => 'webp' };
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Content-Disposition: inline; filename="payment-proof.' . $extension . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
} catch (Throwable $e) {
    http_response_code(404);
}
