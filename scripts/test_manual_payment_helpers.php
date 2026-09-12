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

$retryFingerprint = manual_payment_reference_fingerprint('GCash', 'ABCD-1234');
$assert(manual_payment_submission_retry_decision(null, 71) === 'insert', 'A new normalized reference should create a submission.');
$assert(manual_payment_submission_retry_decision(['booking_id' => 71, 'status' => 'rejected'], 71) === 'resubmit', 'A rejected same-booking fingerprint should be reusable for corrected proof.');
$throws(static fn() => manual_payment_submission_retry_decision(['booking_id' => 72, 'status' => 'rejected'], 71), 'A rejected fingerprint must not be reused across bookings.');
$throws(static fn() => manual_payment_submission_retry_decision(['booking_id' => 71, 'status' => 'pending'], 71), 'A pending fingerprint must remain locked against resubmission.');
$throws(static fn() => manual_payment_submission_retry_decision(['booking_id' => 71, 'status' => 'approved'], 71), 'An approved fingerprint must remain permanently used.');
$assert(strlen($retryFingerprint) === 64, 'Normalized resubmission fingerprints remain SHA-256 scoped values.');

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
$assert(preg_match('/\A[a-f0-9]{48}\.jpg\z/D', $storedJpeg['filename']) === 1, 'Stored receipt name should be randomized and extension-bound.');
$assert($storedJpeg['mime'] === 'image/jpeg' && hash_file('sha256', $storedJpeg['path']) === $storedJpeg['sha256'], 'Stored JPEG metadata/hash should match its bytes.');
$assert(manual_payment_proof_file_path($storedJpeg['filename']) === $storedJpeg['path'], 'Stored proof filenames should resolve only beneath the configured private directory.');
$throws(static fn() => manual_payment_proof_file_path('../not-a-proof.jpg'), 'Path traversal proof filenames should be rejected.');
$assert((fileperms($storedJpeg['path']) & 0777) === 0600, 'Private receipt file permissions should be owner-only.');

$png = $root . '/receipt.png';
imagepng($image, $png);
$temporaryFiles[] = $png;
$storedPng = manual_payment_store_proof($png);
$temporaryFiles[] = $storedPng['path'];
$assert($storedPng['mime'] === 'image/png', 'Valid PNG receipts should be accepted.');

if (function_exists('imagewebp')) {
    $webp = $root . '/receipt.webp';
    if (imagewebp($image, $webp, 80)) {
        $temporaryFiles[] = $webp;
        $storedWebp = manual_payment_store_proof($webp);
        $temporaryFiles[] = $storedWebp['path'];
        $assert($storedWebp['mime'] === 'image/webp', 'Valid WebP receipts should be accepted.');
    }
}
imagedestroy($image);

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
