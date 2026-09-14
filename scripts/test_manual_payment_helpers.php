<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/manual_payment.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$throws = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
    } catch (Throwable $error) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};

$baseBooking = ['total_amount' => '1000.00', 'amount_paid' => '0.00', 'payment_scheme' => '100% Full'];
$assert(manual_payment_expected_amount_cents($baseBooking) === 100000, '100% scheme amount should be the full total.');
$assert(manual_payment_expected_amount_cents(array_replace($baseBooking, ['payment_scheme' => '50% Downpayment'])) === 50000, '50% scheme amount should be half the total.');
$assert(manual_payment_expected_amount_cents(array_replace($baseBooking, ['payment_scheme' => '20% Reservation'])) === 20000, '20% scheme amount should be one fifth of the total.');
$assert(manual_payment_expected_amount_cents(array_replace($baseBooking, ['amount_paid' => '250.00', 'payment_scheme' => '20% Reservation'])) === 75000, 'A later payment should request the full remaining balance.');
$throws(static fn() => manual_payment_expected_amount_cents(array_replace($baseBooking, ['payment_scheme' => 'To Be Arranged'])), 'Unfinalized Event Hall pricing should not be payable.');
$throws(static fn() => manual_payment_expected_amount_cents(array_replace($baseBooking, ['total_amount' => '0.00'])), 'Zero-value bookings should not be payable.');

$fingerprint = manual_payment_reference_fingerprint('GCash', ' ab-12.34 ');
$assert($fingerprint === manual_payment_reference_fingerprint('GCash', 'AB 12-34'), 'Reference fingerprint should normalize case and separators.');
$assert($fingerprint !== manual_payment_reference_fingerprint('Maya', 'AB 12-34'), 'Reference fingerprints should be scoped by method.');
$assert(manual_payment_validate_reference('AB/123') === 'AB123', 'Slash-separated references should normalize safely.');
$throws(static fn() => manual_payment_validate_reference('abc'), 'Too-short references should be rejected.');
$throws(static fn() => manual_payment_validate_reference('AB!123'), 'Unsupported reference punctuation should be rejected.');
$throws(static fn() => manual_payment_reference_fingerprint('Cash', 'AB1234'), 'Customer cash claims should be rejected.');

$historyRows = [
    ['transaction_id' => 'MANUAL-GCASH:INTERNAL-7', 'manual_reference' => 'GCash-Reference-77', 'payment_method' => 'GCash', 'amount' => '125.50', 'payment_date' => '2025-01-02 10:00:00'],
    ['transaction_id' => 'ONLINE-ORDER-9', 'manual_reference' => null, 'payment_method' => 'Card', 'amount' => '50.00', 'payment_date' => '2025-01-03 10:00:00'],
    ['transaction_id' => 'MANUAL-MAYA:INTERNAL-8', 'manual_reference' => null, 'payment_method' => 'Maya', 'amount' => '25.00', 'payment_date' => '2025-01-04 10:00:00'],
];
$history = manual_payment_format_history($historyRows);
$assert(count($history) === 3, 'Multiple payment records should remain separate history entries.');
$assert($history[0]['payment_method'] === 'GCash' && $history[0]['transaction_reference'] === 'GCash-Reference-77', 'Manual payment history should show its submitted reference and method, never the internal key.');
$assert($history[0]['transaction_id'] === 'GCash-Reference-77', 'The legacy transaction_id field should remain display-safe.');
$assert($history[1]['transaction_reference'] === 'ONLINE-ORDER-9' && $history[1]['payment_method'] === 'Card', 'Ordinary transactions should fall back to payments.transaction_id.');
$assert($history[2]['transaction_reference'] === null, 'Unmapped internal manual keys must not be presented as references.');

$retentionNow = new DateTimeImmutable('2026-09-13 10:00:00');
$assert(!manual_payment_proof_retention_expired('approved', '2025-09-13 10:00:00', $retentionNow), 'A terminal proof exactly one year old remains available at the boundary.');
$assert(manual_payment_proof_retention_expired('approved', '2025-09-13 09:59:59', $retentionNow), 'A terminal proof older than one year expires.');
$assert(manual_payment_proof_retention_expired('rejected', '2025-09-13 09:59:59', $retentionNow), 'Rejected proofs use the same one-year retention boundary.');
$assert(!manual_payment_proof_retention_expired('pending', '2020-01-01 00:00:00', $retentionNow), 'Pending proof files are never considered expired.');
$assert(manual_payment_proof_retention_expired('rejected', null, $retentionNow), 'Malformed terminal records without a review timestamp fail closed.');
$assert(!manual_payment_proof_retention_expired('pending', null, $retentionNow), 'Pending proofs with no review timestamp remain retained.');
$leapYearCutoff = new DateTimeImmutable('2025-02-28 10:00:00');
$assert(!manual_payment_proof_retention_expired('approved', '2024-02-29 10:00:00', $leapYearCutoff), 'Calendar-year retention clamps the leap-day cutoff consistently with SQL date arithmetic.');

$retryFingerprint = manual_payment_reference_fingerprint('GCash', 'ABCD-1234');
$assert(manual_payment_submission_retry_decision(null, 71) === 'insert', 'A new normalized reference should create a submission.');
$assert(manual_payment_submission_retry_decision(['booking_id' => 71, 'status' => 'rejected'], 71) === 'resubmit', 'A rejected same-booking fingerprint should be reusable for corrected proof.');
$throws(static fn() => manual_payment_submission_retry_decision(['booking_id' => 72, 'status' => 'rejected'], 71), 'A rejected fingerprint must not be reused across bookings.');
$throws(static fn() => manual_payment_submission_retry_decision(['booking_id' => 71, 'status' => 'pending'], 71), 'A pending fingerprint must remain locked against resubmission.');
$throws(static fn() => manual_payment_submission_retry_decision(['booking_id' => 71, 'status' => 'approved'], 71), 'An approved fingerprint must remain permanently used.');
$assert(strlen($retryFingerprint) === 64, 'Normalized resubmission fingerprints remain SHA-256 scoped values.');
$pendingSubmission = ['id' => 12, 'booking_id' => 71, 'status' => 'pending'];
$assert(manual_payment_submission_decision(null, null, 71) === 'insert', 'A booking without a pending proof keeps the normal first-submission path.');
$assert(manual_payment_submission_decision($pendingSubmission, null, 71) === 'replace_pending', 'A unique new reference may replace the same locked pending row.');
$assert(manual_payment_submission_decision($pendingSubmission, ['id' => 12, 'booking_id' => 71, 'status' => 'pending'], 71) === 'replace_pending', 'Keeping the existing pending reference may replace that same row.');
$assert(manual_payment_submission_decision(null, ['id' => 13, 'booking_id' => 71, 'status' => 'rejected'], 71) === 'resubmit', 'Rejected retry behavior remains available when no proof is pending.');
$throws(static fn() => manual_payment_submission_decision($pendingSubmission, ['id' => 13, 'booking_id' => 71, 'status' => 'approved'], 71), 'A reference used by another approved payment cannot be used during replacement.');
$throws(static fn() => manual_payment_submission_decision(['id' => 12, 'booking_id' => 71, 'status' => 'approved'], null, 71), 'An approved submission cannot enter the pending replacement path.');
$throws(static fn() => manual_payment_submission_decision(['id' => 12, 'booking_id' => 72, 'status' => 'pending'], null, 71), 'A pending submission from another booking cannot be replaced.');

$root = sys_get_temp_dir() . '/sevilla360-manual-payment-test-' . bin2hex(random_bytes(8));
$proofDirectory = $root . '/private';
if (!mkdir($proofDirectory, 0700, true)) throw new RuntimeException('Unable to create isolated test directory.');
$autoCreateProofDirectory = $root . '/auto-created';
$oldConfiguredProofPath = $_ENV['PAYMENT_PROOF_DIR'] ?? null;
$oldServerProofPath = $_SERVER['PAYMENT_PROOF_DIR'] ?? null;
$oldGetenvProofPath = getenv('PAYMENT_PROOF_DIR');
$_ENV['PAYMENT_PROOF_DIR'] = $proofDirectory;
$_SERVER['PAYMENT_PROOF_DIR'] = $proofDirectory;
putenv('PAYMENT_PROOF_DIR=' . $proofDirectory);
$assert(manual_payment_proof_directory() === realpath($proofDirectory), 'Configured proof storage should resolve to its real private directory.');
$createdProofDirectory = manual_payment_proof_directory($autoCreateProofDirectory);
$assert($createdProofDirectory === realpath($autoCreateProofDirectory) && (fileperms($createdProofDirectory) & 0777) === 0700, 'A safe external proof directory should be created owner-only.');
$throws(static fn() => manual_payment_proof_directory(dirname(__DIR__) . '/storage/private/payment_proofs'), 'Project-tree proof storage should be rejected.');
$throws(static fn() => manual_payment_proof_directory('/tmp'), 'Broad temporary-directory proof storage should be rejected.');
$throws(static fn() => manual_payment_proof_directory('relative/payment-proofs'), 'Relative proof storage paths should be rejected.');
$temporaryFiles = [];
$assertStoredImage = static function (array $stored, int $width, int $height, string $message) use ($assert): void {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $info = @getimagesize($stored['path']);
    $mime = $finfo->file($stored['path']);
    $expectedExtension = $mime === 'image/webp' ? 'webp' : 'jpg';
    $assert($stored['mime'] === $mime && $stored['size'] === filesize($stored['path'])
        && $stored['sha256'] === hash_file('sha256', $stored['path'])
        && str_ends_with($stored['filename'], '.' . $expectedExtension)
        && $info && (int)$info[0] === $width && (int)$info[1] === $height,
        $message . ' Stored filename, MIME, size, hash, and dimensions should describe the final file.');
};
$cleanup = static function () use (&$temporaryFiles, $proofDirectory, $autoCreateProofDirectory, $root, $oldConfiguredProofPath, $oldServerProofPath, $oldGetenvProofPath): void {
    foreach ($temporaryFiles as $path) if (is_file($path)) @unlink($path);
    if (is_dir($proofDirectory)) {
        foreach (new DirectoryIterator($proofDirectory) as $file) if ($file->isFile()) @unlink($file->getPathname());
        @rmdir($proofDirectory);
    }
    @rmdir($autoCreateProofDirectory);
    @rmdir($root);
    if ($oldConfiguredProofPath === null) unset($_ENV['PAYMENT_PROOF_DIR']); else $_ENV['PAYMENT_PROOF_DIR'] = $oldConfiguredProofPath;
    if ($oldServerProofPath === null) unset($_SERVER['PAYMENT_PROOF_DIR']); else $_SERVER['PAYMENT_PROOF_DIR'] = $oldServerProofPath;
    if ($oldGetenvProofPath === false) putenv('PAYMENT_PROOF_DIR'); else putenv('PAYMENT_PROOF_DIR=' . $oldGetenvProofPath);
};
register_shutdown_function($cleanup);

$image = imagecreatetruecolor(8, 8);
if ($image === false) throw new RuntimeException('GD could not create the test image.');
$red = imagecolorallocate($image, 180, 30, 30);
imagefill($image, 0, 0, $red);

$jpeg = $root . '/receipt.jpg';
imagejpeg($image, $jpeg, 82);
$temporaryFiles[] = $jpeg;
$storedJpeg = manual_payment_store_proof($jpeg);
$temporaryFiles[] = $storedJpeg['path'];
$assert(preg_match('/\A[a-f0-9]{48}\.(?:jpg|webp)\z/D', $storedJpeg['filename']) === 1, 'Stored receipt name should be randomized and extension-bound.');
$assert($storedJpeg['mime'] === (function_exists('imagewebp') ? 'image/webp' : 'image/jpeg'), 'Receipts should prefer WebP when GD supports it and fall back to JPEG otherwise.');
$assertStoredImage($storedJpeg, 8, 8, 'A small receipt should not be upscaled.');
$assert(manual_payment_proof_file_path($storedJpeg['filename']) === $storedJpeg['path'], 'Stored proof filenames should resolve only beneath the configured private directory.');
$throws(static fn() => manual_payment_proof_file_path('../not-a-proof.jpg'), 'Path traversal proof filenames should be rejected.');
$assert((fileperms($storedJpeg['path']) & 0777) === 0600, 'Private receipt file permissions should be owner-only.');

$expiredName = str_repeat('a', 48) . '.jpg';
$pendingName = str_repeat('b', 48) . '.png';
$missingName = str_repeat('c', 48) . '.webp';
$expiredPath = $proofDirectory . DIRECTORY_SEPARATOR . $expiredName;
$pendingPath = $proofDirectory . DIRECTORY_SEPARATOR . $pendingName;
file_put_contents($expiredPath, 'expired test proof');
file_put_contents($pendingPath, 'pending test proof');
$cleanupStats = manual_payment_cleanup_proof_files([
    ['proof_filename' => $expiredName, 'status' => 'approved', 'reviewed_at' => '2025-09-13 09:59:59'],
    ['proof_filename' => $pendingName, 'status' => 'pending', 'reviewed_at' => '2020-01-01 00:00:00'],
    ['proof_filename' => $missingName, 'status' => 'rejected', 'reviewed_at' => '2025-09-13 09:59:59'],
], $retentionNow);
$assert($cleanupStats['deleted'] === 1 && !is_file($expiredPath), 'Cleanup removes only an eligible expired proof file.');
$assert($cleanupStats['missing'] === 1, 'Cleanup treats an already-missing proof file as harmless.');
$assert($cleanupStats['skipped'] === 1 && is_file($pendingPath), 'Cleanup retains pending proof files.');

$png = $root . '/receipt.png';
imagepng($image, $png);
$temporaryFiles[] = $png;
$storedPng = manual_payment_store_proof($png);
$temporaryFiles[] = $storedPng['path'];
$assertStoredImage($storedPng, 8, 8, 'A PNG receipt should be safely converted.');

if (function_exists('imagewebp')) {
    $webp = $root . '/receipt.webp';
    if (imagewebp($image, $webp, 80)) {
        $temporaryFiles[] = $webp;
        $storedWebp = manual_payment_store_proof($webp);
        $temporaryFiles[] = $storedWebp['path'];
        $assertStoredImage($storedWebp, 8, 8, 'A WebP receipt should be accepted and re-encoded.');
    }
}
imagedestroy($image);

$oversizedForResize = imagecreatetruecolor(2048, 1024);
if (!$oversizedForResize instanceof GdImage) throw new RuntimeException('GD could not create the resize test image.');
imagefill($oversizedForResize, 0, 0, imagecolorallocate($oversizedForResize, 20, 80, 160));
$largeJpeg = $root . '/large-receipt.jpg';
imagejpeg($oversizedForResize, $largeJpeg, 82);
imagedestroy($oversizedForResize);
$temporaryFiles[] = $largeJpeg;
$resizedProof = manual_payment_store_proof($largeJpeg);
$temporaryFiles[] = $resizedProof['path'];
$assertStoredImage($resizedProof, 1920, 960, 'An oversized receipt should be proportionally reduced to a 1920px longest edge.');

$fourMiBReceipt = $root . '/four-mib-receipt.jpg';
$fourMiBBytes = file_get_contents($jpeg);
if ($fourMiBBytes === false || file_put_contents($fourMiBReceipt, $fourMiBBytes . str_repeat("\0", 4194304 - strlen($fourMiBBytes))) !== 4194304) {
    throw new RuntimeException('Unable to create the 4 MiB input test receipt.');
}
$temporaryFiles[] = $fourMiBReceipt;
$assert(filesize($fourMiBReceipt) === 4194304, 'A receipt input of 4 MiB should be accepted by the configured input cap.');
$fourMiBStored = manual_payment_store_proof($fourMiBReceipt);
$temporaryFiles[] = $fourMiBStored['path'];
$assertStoredImage($fourMiBStored, 8, 8, 'A 4 MiB JPEG source should be accepted and stored as a compact decoded image.');

$orientationJpeg = $root . '/orientation-6.jpg';
$orientedSource = imagecreatetruecolor(60, 30);
if (!$orientedSource instanceof GdImage) throw new RuntimeException('GD could not create the EXIF orientation test image.');
imagefill($orientedSource, 0, 0, imagecolorallocate($orientedSource, 40, 120, 60));
imagejpeg($orientedSource, $orientationJpeg, 82);
imagedestroy($orientedSource);
$jpegBytes = file_get_contents($orientationJpeg);
if ($jpegBytes === false) throw new RuntimeException('Unable to read the EXIF orientation fixture.');
$tiff = "II" . pack('vV', 42, 8) . pack('v', 1) . pack('vvVvv', 0x0112, 3, 1, 6, 0) . pack('V', 0);
$app1Payload = "Exif\0\0" . $tiff;
$app1 = "\xFF\xE1" . pack('n', strlen($app1Payload) + 2) . $app1Payload;
$jpegBytes = substr($jpegBytes, 0, 2) . $app1 . substr($jpegBytes, 2);
if (file_put_contents($orientationJpeg, $jpegBytes) === false) throw new RuntimeException('Unable to write the EXIF orientation fixture.');
$temporaryFiles[] = $orientationJpeg;
if (function_exists('exif_read_data')) {
    $assert(manual_payment_jpeg_orientation($orientationJpeg) === 6, 'Valid JPEG EXIF orientation should be read.');
    $orientationStored = manual_payment_store_proof($orientationJpeg);
    $temporaryFiles[] = $orientationStored['path'];
    $assertStoredImage($orientationStored, 30, 60, 'A phone image with EXIF orientation 6 should be stored upright.');

    $malformedTiff = substr($tiff, 0, 18) . pack('v', 9) . substr($tiff, 20);
    $malformedPayload = "Exif\0\0" . $malformedTiff;
    $malformedApp1 = "\xFF\xE1" . pack('n', strlen($malformedPayload) + 2) . $malformedPayload;
    $malformedOrientation = substr($jpegBytes, 0, 2) . $malformedApp1 . substr($jpegBytes, 2 + strlen($app1));
    $malformedOrientationPath = $root . '/orientation-malformed.jpg';
    file_put_contents($malformedOrientationPath, $malformedOrientation);
    $temporaryFiles[] = $malformedOrientationPath;
    $assert(manual_payment_jpeg_orientation($malformedOrientationPath) === 1, 'Out-of-range EXIF orientation should fail safely to encoded pixel order.');
    $malformedStored = manual_payment_store_proof($malformedOrientationPath);
    $temporaryFiles[] = $malformedStored['path'];
    $assertStoredImage($malformedStored, 60, 30, 'Malformed EXIF orientation should not rotate or corrupt a valid JPEG receipt.');
}

$transparent = imagecreatetruecolor(2, 2);
if (!$transparent instanceof GdImage) throw new RuntimeException('GD could not create the transparency fallback test image.');
imagealphablending($transparent, false);
imagesavealpha($transparent, true);
imagefill($transparent, 0, 0, imagecolorallocatealpha($transparent, 255, 0, 0, 127));
$flattened = manual_payment_flatten_proof_image($transparent);
$flattenedPixel = imagecolorat($flattened, 0, 0);
$assert((($flattenedPixel >> 16) & 255) === 255 && (($flattenedPixel >> 8) & 255) === 255 && ($flattenedPixel & 255) === 255 && (($flattenedPixel >> 24) & 127) === 0, 'JPEG fallback should flatten transparent areas onto opaque white.');
imagedestroy($flattened);
imagedestroy($transparent);

$plainText = $root . '/receipt.txt';
file_put_contents($plainText, 'not an image');
$temporaryFiles[] = $plainText;
$throws(static fn() => manual_payment_store_proof($plainText), 'Non-image uploads should be rejected.');

$svg = $root . '/receipt.svg';
file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
$temporaryFiles[] = $svg;
$throws(static fn() => manual_payment_store_proof($svg), 'SVG uploads should be rejected.');

$oversized = $root . '/oversized.bin';
$handle = fopen($oversized, 'wb');
ftruncate($handle, MANUAL_PAYMENT_MAX_PROOF_BYTES + 1);
fclose($handle);
$temporaryFiles[] = $oversized;
$throws(static fn() => manual_payment_store_proof($oversized), 'Uploads larger than 5 MiB should be rejected before decode.');

echo "Manual-payment helper tests passed ({$assertions} assertions).\n";
