<?php
declare(strict_types=1);

const MANUAL_PAYMENT_MAX_PROOF_BYTES = 5242880;
const MANUAL_PAYMENT_METHODS = [
    'gcash' => 'GCash',
    'maya' => 'Maya',
    'bank_transfer' => 'Bank Transfer',
];

function manual_payment_deadline_hours(mysqli $conn): int
{
    $result = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'manual_payment_deadline_hours' LIMIT 1");
    $value = $result instanceof mysqli_result ? $result->fetch_assoc()['setting_value'] ?? null : null;
    return is_string($value) && ctype_digit($value) && (int)$value >= 1 && (int)$value <= 168 ? (int)$value : 24;
}

function manual_payment_load_instructions(mysqli $conn): array
{
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'manual_payment_instructions' LIMIT 1");
    if (!$stmt) throw new RuntimeException('Unable to load customer payment methods.');
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to load customer payment methods.');
    }
    $result = $stmt->get_result();
    if (!$result) {
        $stmt->close();
        throw new RuntimeException('Unable to load customer payment methods.');
    }
    $raw = $result->fetch_assoc()['setting_value'] ?? '';
    $stmt->close();
    return manual_payment_decode_instructions((string)$raw);
}

function manual_payment_decode_instructions(string $raw): array
{
    $defaults = [
        'gcash' => ['enabled' => false, 'account_name' => '', 'account_number' => '', 'details' => '', 'qr_path' => ''],
        'maya' => ['enabled' => false, 'account_name' => '', 'account_number' => '', 'details' => '', 'qr_path' => ''],
        'bank_transfer' => ['enabled' => false, 'account_name' => '', 'account_number' => '', 'details' => '', 'qr_path' => ''],
    ];
    $saved = json_decode((string)$raw, true);
    if (!is_array($saved)) return $defaults;
    foreach ($defaults as $key => $default) {
        $method = $saved[$key] ?? null;
        if (!is_array($method)) continue;
        $defaults[$key] = [
            'enabled' => ($method['enabled'] ?? false) === true || ($method['enabled'] ?? '') === '1' || ($method['enabled'] ?? '') === 'true',
            'account_name' => trim((string)($method['account_name'] ?? '')),
            'account_number' => trim((string)($method['account_number'] ?? '')),
            'details' => trim((string)($method['details'] ?? '')),
            'qr_path' => manual_payment_safe_qr_path((string)($method['qr_path'] ?? '')),
        ];
    }
    return $defaults;
}

/** Resolve private proof storage without ever defaulting to the document root. */
function manual_payment_proof_directory(?string $configuredPath = null, bool $create = true): string
{
    if ($configuredPath === null) {
        $configuredPath = (string)($_ENV['PAYMENT_PROOF_DIR'] ?? $_SERVER['PAYMENT_PROOF_DIR'] ?? getenv('PAYMENT_PROOF_DIR') ?: '');
    }
    if ($configuredPath === '' || $configuredPath[0] !== '/' || str_contains($configuredPath, "\0") || str_contains($configuredPath, '\\') || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $configuredPath)) {
        throw new RuntimeException('Private payment-proof storage is not configured with a safe absolute path.');
    }
    $parts = explode('/', trim($configuredPath, '/'));
    $safeTemporaryLeaf = count($parts) === 2 && $parts[0] === 'tmp' && preg_match('/\Asevilla360-payment-proofs-[a-f0-9]{32}\z/D', $parts[1]) === 1;
    if ((count($parts) < 3 && !$safeTemporaryLeaf) || in_array('', $parts, true)) throw new RuntimeException('Private payment-proof storage path is too broad.');
    $candidate = '/' . implode('/', $parts);
    $broadPaths = ['/', '/tmp', '/var', '/var/www', '/var/www/html', '/home', '/root', '/usr', '/opt', '/etc', '/mnt', '/media', '/run', '/dev', '/proc', '/sys'];
    if (in_array($candidate, $broadPaths, true)) throw new RuntimeException('Private payment-proof storage path is too broad.');

    $projectRoot = realpath(dirname(__DIR__));
    if ($projectRoot === false) throw new RuntimeException('Application root could not be resolved.');
    $documentRoots = [$projectRoot];
    $documentRoot = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($documentRoot !== '' && $documentRoot[0] === '/') {
        $realDocumentRoot = realpath($documentRoot);
        if ($realDocumentRoot !== false && $realDocumentRoot !== '/') $documentRoots[] = rtrim($realDocumentRoot, '/');
    }

    $probe = $candidate;
    $missing = [];
    while (!file_exists($probe) && !is_link($probe)) {
        $parent = dirname($probe);
        if ($parent === $probe) break;
        $missing[] = basename($probe);
        $probe = $parent;
    }
    $realParent = realpath($probe);
    if ($realParent === false || !is_dir($realParent)) throw new RuntimeException('Private payment-proof storage parent is unavailable.');
    $resolvedCandidate = rtrim($realParent, '/') . ($missing ? '/' . implode('/', array_reverse($missing)) : '');
    $resolvedCandidate = $resolvedCandidate === '' ? '/' : $resolvedCandidate;
    $assertOutsideRoots = static function (string $path) use ($documentRoots): void {
        foreach ($documentRoots as $root) {
            $root = rtrim($root, '/');
            if ($root !== '' && ($path === $root || str_starts_with($path, $root . '/'))) {
                throw new RuntimeException('Payment proofs must be stored outside the application and document roots.');
            }
        }
    };
    $assertOutsideRoots($candidate);
    $assertOutsideRoots($resolvedCandidate);

    if (file_exists($candidate) && !is_dir($candidate)) throw new RuntimeException('Private payment-proof path is not a directory.');
    if (is_link($candidate)) throw new RuntimeException('Private payment-proof storage cannot be a symlink.');
    if (!is_dir($candidate)) {
        if (!$create || !mkdir($candidate, 0700, true)) {
            if (!$create || !is_dir($candidate)) throw new RuntimeException('Private payment-proof storage is unavailable.');
        }
        @chmod($candidate, 0700);
    }
    $realDirectory = realpath($candidate);
    if ($realDirectory === false || !is_dir($realDirectory) || $realDirectory !== $resolvedCandidate || is_link($candidate) || !is_writable($realDirectory)) {
        throw new RuntimeException('Private payment-proof storage is unavailable or unsafe.');
    }
    $permissions = fileperms($realDirectory);
    if ($permissions === false || ($permissions & 0007) !== 0) throw new RuntimeException('Private payment-proof storage must not be accessible to other users.');
    return $realDirectory;
}

function manual_payment_proof_file_path(string $filename, bool $mustExist = true): string
{
    if (!preg_match('/\A[a-f0-9]{48}\.(?:jpg|png|webp)\z/D', $filename)) throw new RuntimeException('Invalid payment-proof filename.');
    $directory = manual_payment_proof_directory(null, $mustExist);
    $path = $directory . DIRECTORY_SEPARATOR . $filename;
    if (!$mustExist) {
        if (is_link($path) || (file_exists($path) && (!is_file($path) || dirname((string)realpath($path)) !== $directory))) {
            throw new RuntimeException('Payment-proof path is invalid.');
        }
        return $path;
    }
    $realPath = realpath($path);
    if ($realPath === false || !is_file($realPath) || dirname($realPath) !== $directory || is_link($path)) throw new RuntimeException('Payment-proof file is unavailable.');
    return $realPath;
}

function manual_payment_safe_qr_path(string $path): string
{
    return preg_match('~\Aassets/uploads/payment-qrs/[a-f0-9]{48}\.(?:jpg|png|webp)\z~D', $path) ? $path : '';
}

function manual_payment_enabled_methods(mysqli $conn): array
{
    return array_filter(manual_payment_load_instructions($conn), static fn(array $method): bool => $method['enabled']);
}

function manual_payment_expected_amount_cents(array $booking): int
{
    $totalCents = (int)round((float)($booking['total_amount'] ?? 0) * 100);
    $paidCents = (int)round((float)($booking['amount_paid'] ?? 0) * 100);
    $balanceCents = max(0, $totalCents - $paidCents);
    if ($balanceCents <= 0) throw new RuntimeException('This booking has no payment balance remaining.');
    if ($paidCents > 0) return $balanceCents;

    $scheme = (string)($booking['payment_scheme'] ?? '');
    $percentage = match ($scheme) {
        '100% Full' => 100,
        '50% Downpayment' => 50,
        '20% Reservation' => 20,
        default => 0,
    };
    if ($percentage === 0 || $totalCents <= 0) throw new RuntimeException('This booking is not ready to accept payment.');
    return min($balanceCents, (int)round($totalCents * $percentage / 100));
}

function manual_payment_validate_reference(string $reference): string
{
    $reference = trim($reference);
    if (strlen($reference) < 4 || strlen($reference) > 64 || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9 ._\/-]{2,62}[A-Za-z0-9]\z/D', $reference)) {
        throw new RuntimeException('Enter a 4–64 character reference using letters, numbers, spaces, dots, underscores, slashes, or hyphens.');
    }
    $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $reference) ?? '');
    if (strlen($normalized) < 4 || strlen($normalized) > 64) throw new RuntimeException('Enter a valid transaction reference.');
    return $normalized;
}

function manual_payment_reference_fingerprint(string $method, string $reference): string
{
    if (!in_array($method, MANUAL_PAYMENT_METHODS, true)) throw new InvalidArgumentException('Unsupported payment method.');
    return hash('sha256', strtoupper($method) . '|' . manual_payment_validate_reference($reference));
}

function manual_payment_submission_retry_decision(?array $existing, int $bookingId): string
{
    if ($existing === null) return 'insert';
    if ((int)($existing['booking_id'] ?? 0) !== $bookingId) throw new RuntimeException('That transaction reference has already been submitted for another booking.');
    return match ((string)($existing['status'] ?? '')) {
        'rejected' => 'resubmit',
        'pending' => throw new RuntimeException('A payment proof is already awaiting review.'),
        'approved' => throw new RuntimeException('That transaction reference has already been approved.'),
        default => throw new RuntimeException('That transaction reference cannot be reused.'),
    };
}

function manual_payment_expected_amount(array $booking): float
{
    return manual_payment_expected_amount_cents($booking) / 100;
}

/** Verify and re-encode an uploaded image into a caller-selected, server-owned directory. */
function manual_payment_store_verified_image(string $temporaryPath, string $directory, int $permissions): array
{
    if (!is_file($temporaryPath) || !is_readable($temporaryPath)) throw new RuntimeException('Choose a JPEG, PNG, or WebP receipt image.');
    $sourceSize = filesize($temporaryPath);
    if ($sourceSize === false || $sourceSize < 1 || $sourceSize > MANUAL_PAYMENT_MAX_PROOF_BYTES) throw new RuntimeException('Receipt images must be 5 MiB or smaller.');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($temporaryPath);
    $allowed = ['image/jpeg' => ['jpg', IMAGETYPE_JPEG], 'image/png' => ['png', IMAGETYPE_PNG], 'image/webp' => ['webp', IMAGETYPE_WEBP]];
    if (!isset($allowed[$mime])) throw new RuntimeException('Only real JPEG, PNG, and WebP receipt images are accepted.');
    $imageInfo = @getimagesize($temporaryPath);
    if (!$imageInfo || ($imageInfo['mime'] ?? '') !== $mime || $imageInfo[2] !== $allowed[$mime][1]) throw new RuntimeException('The uploaded file is not a valid supported image.');
    $width = (int)$imageInfo[0];
    $height = (int)$imageInfo[1];
    if ($width < 1 || $height < 1 || $width > 4096 || $height > 4096 || $width * $height > 12000000) throw new RuntimeException('Receipt image dimensions are too large. Maximum size is 4096 × 4096 pixels.');
    if (!function_exists('imagecreatefromstring')) throw new RuntimeException('Image verification is unavailable on this server.');
    $contents = file_get_contents($temporaryPath);
    if ($contents === false) throw new RuntimeException('Unable to read the uploaded image.');
    $image = @imagecreatefromstring($contents);
    unset($contents);
    if ($image === false) throw new RuntimeException('The uploaded image could not be decoded.');

    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        imagedestroy($image);
        throw new RuntimeException('Image storage is unavailable.');
    }
    $realDirectory = realpath($directory);
    if ($realDirectory === false || !is_dir($realDirectory) || !is_writable($realDirectory)) {
        imagedestroy($image);
        throw new RuntimeException('Image storage is unavailable.');
    }
    $name = bin2hex(random_bytes(24)) . '.' . $allowed[$mime][0];
    $destination = rtrim($realDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
    $saved = match ($mime) {
        'image/jpeg' => imagejpeg($image, $destination, 88),
        'image/png' => imagepng($image, $destination, 6),
        'image/webp' => function_exists('imagewebp') && imagewebp($image, $destination, 88),
    };
    imagedestroy($image);
    if (!$saved || !is_file($destination)) {
        @unlink($destination);
        throw new RuntimeException('Unable to store the verified receipt image.');
    }
    chmod($destination, $permissions);
    clearstatcache(true, $destination);
    $storedSize = filesize($destination);
    $storedMime = $finfo->file($destination);
    $storedInfo = @getimagesize($destination);
    $sha256 = hash_file('sha256', $destination);
    if ($storedSize === false || $storedSize < 1 || $storedSize > MANUAL_PAYMENT_MAX_PROOF_BYTES || $storedMime !== $mime || !$storedInfo || ($storedInfo['mime'] ?? '') !== $mime || !is_string($sha256)) {
        @unlink($destination);
        throw new RuntimeException('The verified image could not be stored safely.');
    }
    return ['filename' => $name, 'mime' => $mime, 'size' => (int)$storedSize, 'sha256' => $sha256, 'path' => $destination];
}

/** Store a customer receipt only in the configured private directory. */
function manual_payment_store_proof(string $temporaryPath): array
{
    return manual_payment_store_verified_image($temporaryPath, manual_payment_proof_directory(), 0600);
}

/** QR codes are intentionally public instructions, not customer proof. */
function manual_payment_store_qr_image(string $temporaryPath): array
{
    $projectRoot = realpath(dirname(__DIR__));
    if ($projectRoot === false) throw new RuntimeException('Application root could not be resolved.');
    $directory = $projectRoot . '/assets/uploads/payment-qrs';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) throw new RuntimeException('QR image storage is unavailable.');
    @chmod($directory, 0755);
    return manual_payment_store_verified_image($temporaryPath, $directory, 0644);
}

/** Mark all outstanding proofs terminal when the locked booking is cancelled/refunded. */
function manual_payment_reject_pending_for_terminal_booking(mysqli $conn, int $bookingId, ?int $reviewerUserId, string $reason): int
{
    $reason = trim($reason);
    if ($reason === '' || strlen($reason) > 500 || preg_match('/[\x00-\x1F\x7F]/', $reason)) throw new InvalidArgumentException('Invalid system rejection reason.');
    $stmt = $conn->prepare("UPDATE manual_payment_submissions SET status = 'rejected', rejection_reason = ?, reviewer_user_id = ?, reviewed_at = NOW() WHERE booking_id = ? AND status = 'pending'");
    if (!$stmt) throw new RuntimeException('Unable to close pending payment proofs.');
    $stmt->bind_param('sii', $reason, $reviewerUserId, $bookingId);
    if (!$stmt->execute()) throw new RuntimeException('Unable to close pending payment proofs.');
    return $stmt->affected_rows;
}

/** Call after the booking row is locked; quote edits must wait for proof review. */
function manual_payment_assert_no_pending_for_quote(mysqli $conn, int $bookingId): void
{
    $stmt = $conn->prepare("SELECT id FROM manual_payment_submissions WHERE booking_id = ? AND status = 'pending' LIMIT 1 FOR UPDATE");
    if (!$stmt) throw new RuntimeException('Unable to check payment proof review status.');
    $stmt->bind_param('i', $bookingId);
    if (!$stmt->execute()) throw new RuntimeException('Unable to check payment proof review status.');
    if ($stmt->get_result()->num_rows > 0) {
        throw new RuntimeException('A customer payment proof is awaiting review. Approve or reject it before changing the Event Hall quote.');
    }
}

/** Call inside a transaction after the booking row is already locked. */
function manual_payment_credit_locked(mysqli $conn, array $booking, float $amount, string $method, string $transactionId, ?int $approvedSubmissionId = null): array
{
    $bookingId = (int)$booking['id'];
    if (!in_array($method, ['Cash', 'GCash', 'Maya', 'Bank Transfer'], true)) throw new RuntimeException('Choose a supported payment method.');
    if (!is_finite($amount) || $amount <= 0) throw new RuntimeException('Payment amount must be greater than zero.');
    if ($transactionId === '' || strlen($transactionId) > 100 || preg_match('/[\x00-\x1F\x7F]/', $transactionId)) throw new RuntimeException('Enter a valid transaction reference.');
    if (!in_array((string)$booking['booking_status'], ['Pending', 'Confirmed'], true) || ($booking['payment_status'] ?? '') === 'Refunded' || (int)($booking['is_completed'] ?? 0) === 1) {
        throw new RuntimeException('This booking is no longer eligible for payment.');
    }
    if (($booking['venue_category'] ?? '') === 'Event Hall' && $booking['booking_status'] === 'Pending') throw new RuntimeException('Finalize the Event Hall invoice before recording payment.');

    $pendingSql = $approvedSubmissionId === null
        ? "SELECT id FROM manual_payment_submissions WHERE booking_id = ? AND status = 'pending' LIMIT 1 FOR UPDATE"
        : "SELECT id FROM manual_payment_submissions WHERE booking_id = ? AND status = 'pending' AND id <> ? LIMIT 1 FOR UPDATE";
    $pending = $conn->prepare($pendingSql);
    if (!$pending) throw new RuntimeException('Unable to check pending payment proof.');
    if ($approvedSubmissionId === null) $pending->bind_param('i', $bookingId);
    else $pending->bind_param('ii', $bookingId, $approvedSubmissionId);
    if (!$pending->execute()) throw new RuntimeException('Unable to check pending payment proof.');
    if ($pending->get_result()->num_rows > 0) throw new RuntimeException('A payment proof is awaiting review. Review it before collecting another payment.');

    $totalCents = (int)round((float)$booking['total_amount'] * 100);
    $paidCents = (int)round((float)$booking['amount_paid'] * 100);
    $amountCents = (int)round($amount * 100);
    $balanceCents = max(0, $totalCents - $paidCents);
    if ($amountCents <= 0 || $amountCents > $balanceCents) throw new RuntimeException('Payment exceeds the remaining balance of ₱' . number_format($balanceCents / 100, 2) . '.');
    $duplicate = $conn->prepare('SELECT id FROM payments WHERE transaction_id = ? LIMIT 1');
    if (!$duplicate) throw new RuntimeException('Unable to validate transaction reference.');
    $duplicate->bind_param('s', $transactionId);
    if (!$duplicate->execute()) throw new RuntimeException('Unable to validate transaction reference.');
    if ($duplicate->get_result()->num_rows > 0) throw new RuntimeException('This transaction reference has already been recorded.');

    $newPaidCents = $paidCents + $amountCents;
    $newStatus = $newPaidCents >= $totalCents && $totalCents > 0 ? 'Paid' : 'Partial';
    $newAmountPaid = $newPaidCents / 100;
    $stmt = $conn->prepare("INSERT INTO payments (booking_id, transaction_id, payment_method, amount, status) VALUES (?, ?, ?, ?, 'Success')");
    if (!$stmt) throw new RuntimeException('Unable to prepare the payment record.');
    $paidAmount = $amountCents / 100;
    $stmt->bind_param('issd', $bookingId, $transactionId, $method, $paidAmount);
    if (!$stmt->execute()) throw new RuntimeException('Unable to record the payment.');
    $paymentId = (int)$conn->insert_id;

    $stmt = $conn->prepare("UPDATE bookings SET payment_status = ?, amount_paid = ?, booking_status = 'Confirmed', payment_due_at = NULL WHERE id = ?");
    if (!$stmt) throw new RuntimeException('Unable to prepare the booking payment update.');
    $stmt->bind_param('sdi', $newStatus, $newAmountPaid, $bookingId);
    if (!$stmt->execute()) throw new RuntimeException('Unable to update the booking payment status.');
    return ['payment_id' => $paymentId, 'amount_paid' => $newAmountPaid, 'payment_status' => $newStatus, 'amount' => $paidAmount];
}

/** Cancel expired, wholly-unpaid online holds. Call from the CLI scheduler only. */
function manual_payment_expire_due_bookings(mysqli $conn, int $limit = 100): int
{
    require_once __DIR__ . '/booking_lifecycle.php';
    require_once __DIR__ . '/notifications.php';
    require_once __DIR__ . '/request_context.php';
    $limit = max(1, min(500, $limit));
    $completionSql = booking_completion_sql('b');
    $stmt = $conn->prepare("SELECT b.id FROM bookings b WHERE b.source = 'Online' AND b.booking_status IN ('Pending', 'Confirmed') AND b.payment_status = 'Unpaid' AND b.amount_paid = 0 AND b.payment_due_at <= NOW() AND NOT {$completionSql} AND NOT EXISTS (SELECT 1 FROM manual_payment_submissions mps WHERE mps.booking_id = b.id AND mps.status = 'pending') AND NOT (b.booking_status = 'Pending' AND EXISTS (SELECT 1 FROM venues v WHERE v.id = b.venue_id AND v.category = 'Event Hall')) ORDER BY b.payment_due_at, b.id LIMIT ?");
    if (!$stmt) throw new RuntimeException('Unable to find expired bookings.');
    $stmt->bind_param('i', $limit);
    if (!$stmt->execute()) throw new RuntimeException('Unable to find expired bookings.');
    $ids = array_map(static fn(array $row): int => (int)$row['id'], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $expired = 0;
    foreach ($ids as $bookingId) {
        if (!$conn->begin_transaction()) throw new RuntimeException('Unable to start an expiry transaction.');
        try {
            $lockedCompletionSql = booking_completion_sql('b');
            $stmtBooking = $conn->prepare("SELECT b.id, b.reference_no, b.booking_status, b.payment_status, b.amount_paid, b.payment_due_at, b.source, c.user_id, v.name AS venue_name, v.category AS venue_category, CASE WHEN {$lockedCompletionSql} THEN 1 ELSE 0 END AS is_completed FROM bookings b JOIN customers c ON c.id = b.customer_id JOIN venues v ON v.id = b.venue_id WHERE b.id = ? FOR UPDATE");
            if (!$stmtBooking) throw new RuntimeException('Unable to lock an expired booking.');
            $stmtBooking->bind_param('i', $bookingId);
            if (!$stmtBooking->execute()) throw new RuntimeException('Unable to lock an expired booking.');
            $booking = $stmtBooking->get_result()->fetch_assoc();
            if (!$booking || $booking['source'] !== 'Online' || !in_array($booking['booking_status'], ['Pending', 'Confirmed'], true) || (int)$booking['is_completed'] === 1 || ($booking['venue_category'] === 'Event Hall' && $booking['booking_status'] === 'Pending') || $booking['payment_status'] !== 'Unpaid' || (float)$booking['amount_paid'] !== 0.0 || empty($booking['payment_due_at']) || strtotime((string)$booking['payment_due_at']) > time()) {
                $conn->commit();
                continue;
            }
            $stmtPending = $conn->prepare("SELECT id FROM manual_payment_submissions WHERE booking_id = ? AND status = 'pending' LIMIT 1 FOR UPDATE");
            if (!$stmtPending) throw new RuntimeException('Unable to check pending payment proof.');
            $stmtPending->bind_param('i', $bookingId);
            if (!$stmtPending->execute()) throw new RuntimeException('Unable to check pending payment proof.');
            if ($stmtPending->get_result()->num_rows > 0) {
                $conn->commit();
                continue;
            }
            $stmtUpdate = $conn->prepare("UPDATE bookings SET booking_status = 'Cancelled', payment_due_at = NULL WHERE id = ? AND source = 'Online' AND booking_status IN ('Pending', 'Confirmed') AND payment_status = 'Unpaid' AND amount_paid = 0 AND payment_due_at <= NOW()");
            if (!$stmtUpdate) throw new RuntimeException('Unable to expire an unpaid booking.');
            $stmtUpdate->bind_param('i', $bookingId);
            if (!$stmtUpdate->execute()) throw new RuntimeException('Unable to expire an unpaid booking.');
            if ($stmtUpdate->affected_rows !== 1) {
                $conn->commit();
                continue;
            }
            $action = 'Expired unpaid online booking ' . $booking['reference_no'] . ' after payment deadline';
            $audit = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (NULL, 'Manual Payment Expiry', ?, 'System scheduler')");
            if (!$audit) throw new RuntimeException('Unable to record booking expiry.');
            $audit->bind_param('s', $action);
            if (!$audit->execute()) throw new RuntimeException('Unable to record booking expiry.');
            $userId = (int)$booking['user_id'];
            if ($userId > 0) {
                create_user_notification($conn, $userId, 'Booking Expired', 'Your unpaid online booking ' . $booking['reference_no'] . ' expired before payment proof was received. Choose new dates from the booking page if you would like to try again.');
                realtime_enqueue_event($conn, 'customer:' . $userId, 'booking.expired', ['booking_id' => $bookingId, 'reference_no' => (string)$booking['reference_no']]);
            }
            realtime_enqueue_event($conn, 'admin', 'booking.expired', ['booking_id' => $bookingId, 'reference_no' => (string)$booking['reference_no']]);
            if (!$conn->commit()) throw new RuntimeException('Unable to commit booking expiry.');
            $expired++;
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }
    }
    return $expired;
}
