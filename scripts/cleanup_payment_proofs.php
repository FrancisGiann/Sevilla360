<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/manual_payment.php';

const PAYMENT_PROOF_CLEANUP_BATCH_SIZE = 100;

try {
    $proofDirectory = manual_payment_proof_directory(null, false);
    $cursorPath = $proofDirectory . DIRECTORY_SEPARATOR . '.cleanup-cursor';
    $lockPath = $proofDirectory . DIRECTORY_SEPARATOR . '.cleanup.lock';
    if (is_link($cursorPath) || is_link($lockPath)) throw new RuntimeException('Payment-proof cleanup state is unsafe.');

    $lock = fopen($lockPath, 'c');
    if ($lock === false) throw new RuntimeException('Unable to open payment-proof cleanup lock.');
    @chmod($lockPath, 0600);
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        fwrite(STDOUT, "Payment-proof cleanup is already running.\n");
        exit(0);
    }

    $cursorFile = fopen($cursorPath, 'c+');
    if ($cursorFile === false) throw new RuntimeException('Unable to open payment-proof cleanup cursor.');
    @chmod($cursorPath, 0600);
    $cursorValue = stream_get_contents($cursorFile);
    $lastId = is_string($cursorValue) && preg_match('/\A\d+\z/D', trim($cursorValue))
        ? (int)trim($cursorValue)
        : 0;

    $stmt = $conn->prepare(
        "SELECT id, proof_filename, status, reviewed_at
         FROM manual_payment_submissions
         WHERE status IN ('approved', 'rejected')
           AND reviewed_at IS NOT NULL
           AND reviewed_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)
           AND id > ?
         ORDER BY id ASC
         LIMIT ?"
    );
    if (!$stmt) throw new RuntimeException('Unable to prepare payment-proof cleanup.');
    $limit = PAYMENT_PROOF_CLEANUP_BATCH_SIZE;
    $stmt->bind_param('ii', $lastId, $limit);
    if (!$stmt->execute()) throw new RuntimeException('Unable to select expired payment proofs.');
    $proofRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $stats = manual_payment_cleanup_proof_files($proofRows);
    $nextCursor = count($proofRows) === PAYMENT_PROOF_CLEANUP_BATCH_SIZE
        ? (int)end($proofRows)['id']
        : 0;
    rewind($cursorFile);
    if (!ftruncate($cursorFile, 0) || fwrite($cursorFile, (string)$nextCursor) === false || !fflush($cursorFile)) {
        throw new RuntimeException('Unable to persist payment-proof cleanup cursor.');
    }
    printf(
        "Payment-proof cleanup: selected=%d deleted=%d missing=%d skipped=%d failed=%d next_cursor=%d\n",
        count($proofRows),
        $stats['deleted'],
        $stats['missing'],
        $stats['skipped'],
        $stats['failed'],
        $nextCursor
    );
    fclose($cursorFile);
    flock($lock, LOCK_UN);
    fclose($lock);
    exit($stats['failed'] === 0 ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Payment-proof cleanup failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
