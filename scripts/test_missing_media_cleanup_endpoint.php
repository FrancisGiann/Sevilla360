<?php
declare(strict_types=1);

/** Local-only HTTP integration checks for authenticated missing-source cleanup. */
$root = dirname(__DIR__);
require_once $root . '/config/db_connect.php';
require_once $root . '/includes/media_helper.php';

$identity = $conn->query('SELECT DATABASE() AS db_name, @@hostname AS host_name')->fetch_assoc();
$configuredHost = strtolower((string)($_ENV['DB_HOST'] ?? 'localhost'));
if (($identity['db_name'] ?? '') !== 'sevilla360'
    || !in_array($configuredHost, ['localhost', '127.0.0.1', '::1'], true)
    || strtolower((string)($identity['host_name'] ?? '')) !== strtolower((string)gethostname())) {
    throw new RuntimeException('Missing-media endpoint integration checks are restricted to the local sevilla360 database.');
}

$sourceRelative = 'assets/uploads/venue_miradeth_1st_floor_family_room_superior_360_3e9a483d9846f078.jpg';
$sourceAbsolute = media_cms_upload_file_path($sourceRelative, true);
if (media_cms_source_file_status($sourceRelative) !== 'available') {
    throw new RuntimeException('A known local panorama fixture is unavailable.');
}
$sourceHash = hash_file('sha256', $sourceAbsolute);
$sourceMtime = filemtime($sourceAbsolute);
$tempDir = sys_get_temp_dir() . '/sevilla-missing-media-endpoint-' . bin2hex(random_bytes(6));
if (!mkdir($tempDir, 0700)) throw new RuntimeException('Unable to create temporary endpoint-test directory.');

$server = null;
$sessionId = 'cmscleanup' . bin2hex(random_bytes(8));
$csrf = bin2hex(random_bytes(24));
$token = bin2hex(random_bytes(8));
$slot = 'venue_cms_cleanup_test_' . $token . '_360';
$missingRelative = 'assets/uploads/cms-missing-test-' . $token . '.jpg';
$missingAbsolute = media_cms_upload_file_path($missingRelative);
$mediaIds = [];
$hotspotIds = [];
$restoredTestSource = false;
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};
$insertMedia = static function (mysqli $connection, string $fileName, string $filePath, string $slotAssignment): int {
    $type = '360';
    $stmt = $connection->prepare('INSERT INTO media_cms (file_name, file_path, media_type, slot_assignment, is_primary) VALUES (?, ?, ?, ?, 0)');
    if (!$stmt) throw new RuntimeException('Could not create disposable media rows.');
    $stmt->bind_param('ssss', $fileName, $filePath, $type, $slotAssignment);
    if (!$stmt->execute()) throw new RuntimeException('Could not create disposable media rows.');
    return (int)$connection->insert_id;
};
$insertHotspot = static function (mysqli $connection, int $mediaId, string $type, ?int $targetId, ?int $targetIndex): int {
    $title = 'Temporary cleanup integration pin';
    $description = 'Temporary test fixture';
    $x = 0.1;
    $y = 0.2;
    $z = 0.9;
    $stmt = $connection->prepare('INSERT INTO showroom_hotspots (media_id, type, title, description, position_x, position_y, position_z, target_media_id, target_pano_index) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) throw new RuntimeException('Could not create disposable panorama links.');
    $stmt->bind_param('isssdddii', $mediaId, $type, $title, $description, $x, $y, $z, $targetId, $targetIndex);
    if (!$stmt->execute()) throw new RuntimeException('Could not create disposable panorama links.');
    return (int)$connection->insert_id;
};
$post = static function (int $port, string $sessionId, string $csrf, array $payload): array {
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nX-CSRF-Token: {$csrf}\r\nCookie: " . session_name() . "={$sessionId}\r\n",
        'content' => json_encode($payload, JSON_THROW_ON_ERROR),
        'ignore_errors' => true,
        'timeout' => 8,
    ]]);
    $body = file_get_contents('http://127.0.0.1:' . $port . '/actions/admin/delete_media.php', false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if (!preg_match('/\s(\d{3})\s/', $statusLine, $match)) throw new RuntimeException('Missing-media endpoint returned no HTTP status.');
    $data = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($data)) throw new RuntimeException('Missing-media endpoint returned invalid JSON.');
    return [(int)$match[1], $data];
};
$countRows = static function (mysqli $connection, string $sql, string $types, array $params): int {
    $stmt = $connection->prepare($sql);
    if (!$stmt) throw new RuntimeException('Could not inspect disposable endpoint fixtures.');
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) throw new RuntimeException('Could not inspect disposable endpoint fixtures.');
    return (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
};

try {
    $missingId = $insertMedia($conn, 'missing-' . $token . '.jpg', $missingRelative, $slot);
    $mediaIds[] = $missingId;
    $validSourceA = $insertMedia($conn, 'valid-source-a-' . $token . '.jpg', $sourceRelative, $slot);
    $mediaIds[] = $validSourceA;
    $validSourceB = $insertMedia($conn, 'valid-source-b-' . $token . '.jpg', $sourceRelative, $slot);
    $mediaIds[] = $validSourceB;
    $ownPin = $insertHotspot($conn, $missingId, 'info', null, null);
    $incomingPin = $insertHotspot($conn, $validSourceA, 'nav', $missingId, null);
    $validPin = $insertHotspot($conn, $validSourceB, 'info', null, null);
    $hotspotIds = [$ownPin, $incomingPin, $validPin];

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errorMessage);
    if (!$socket) throw new RuntimeException('Unable to reserve an endpoint-test port.');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int)substr(strrchr((string)$address, ':'), 1);

    ini_set('session.save_path', $tempDir);
    session_id($sessionId);
    if (!session_start()) throw new RuntimeException('Unable to create the isolated admin test session.');
    $_SESSION = [
        'logged_in' => true,
        'role' => 'admin',
        'csrf_token' => $csrf,
        'auth_started_at' => time(),
        'last_user_activity' => time(),
    ];
    session_write_close();

    $serverLog = $tempDir . '/server.log';
    $server = proc_open([
        PHP_BINARY,
        '-d', 'session.save_path=' . $tempDir,
        '-S', '127.0.0.1:' . $port,
        '-t', $root,
    ], [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']], $pipes, $root);
    if (!is_resource($server)) throw new RuntimeException('Unable to start the local endpoint-test server.');
    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $probe = @fsockopen('127.0.0.1', $port, $probeError, $probeMessage, 0.1);
        if (is_resource($probe)) { fclose($probe); $ready = true; break; }
        usleep(100000);
    }
    if (!$ready) throw new RuntimeException('Local endpoint-test server did not start.');

    [$mixedStatus, $mixed] = $post($port, $sessionId, $csrf, ['action' => 'preview_missing', 'ids' => [$missingId, $validSourceA]]);
    $assert($mixedStatus === 409 && ($mixed['success'] ?? true) === false, 'mixed missing and readable selections are rejected');
    $assert($countRows($conn, 'SELECT COUNT(*) AS c FROM media_cms WHERE id IN (?, ?)', 'ii', [$missingId, $validSourceA]) === 2,
        'mixed-source rejection leaves both media rows untouched');

    [$previewStatus, $preview] = $post($port, $sessionId, $csrf, ['action' => 'preview_missing', 'id' => $missingId]);
    $plan = $preview['plan'] ?? [];
    $assert($previewStatus === 200 && ($preview['can_remove'] ?? false) === true
        && (int)($plan['source_hotspots'] ?? -1) === 1 && (int)($plan['incoming_hotspots'] ?? -1) === 1,
        'preview reports the missing panorama and exact own/incoming hotspot counts');

    $movePin = $conn->prepare('UPDATE showroom_hotspots SET media_id = ? WHERE id = ?');
    if (!$movePin) throw new RuntimeException('Could not change the disposable stale-plan fixture.');
    $movePin->bind_param('ii', $validSourceB, $incomingPin);
    if (!$movePin->execute()) throw new RuntimeException('Could not change the disposable stale-plan fixture.');
    [$staleStatus, $stale] = $post($port, $sessionId, $csrf, ['action' => 'remove_missing', 'id' => $missingId, 'expected' => $plan]);
    $assert($staleStatus === 409 && ($stale['success'] ?? true) === false,
        'same-count hotspot ownership change invalidates the preview fingerprint');
    $assert($countRows($conn, 'SELECT COUNT(*) AS c FROM media_cms WHERE id = ?', 'i', [$missingId]) === 1
        && $countRows($conn, 'SELECT COUNT(*) AS c FROM showroom_hotspots WHERE id = ?', 'i', [$incomingPin]) === 1,
        'stale-plan rejection rolls back media and hotspot mutations');

    [$freshStatus, $freshPreview] = $post($port, $sessionId, $csrf, ['action' => 'preview_missing', 'id' => $missingId]);
    $freshPlan = $freshPreview['plan'] ?? [];
    $assert($freshStatus === 200 && ($freshPreview['can_remove'] ?? false) === true,
        'fresh preview remains available after stale-plan rollback');
    $restoredTestSource = file_put_contents($missingAbsolute, 'temporary restored-source guard') !== false;
    $assert($restoredTestSource, 'reappeared-source guard fixture is created at its exact test path');
    [$restoredStatus, $restored] = $post($port, $sessionId, $csrf, ['action' => 'remove_missing', 'id' => $missingId, 'expected' => $freshPlan]);
    $assert($restoredStatus === 409 && ($restored['success'] ?? true) === false
        && $countRows($conn, 'SELECT COUNT(*) AS c FROM media_cms WHERE id = ?', 'i', [$missingId]) === 1,
        'source restoration between preview and removal is rechecked and keeps the row');
    @unlink($missingAbsolute);
    $restoredTestSource = false;

    $legacyPin = $insertHotspot($conn, $validSourceA, 'nav', null, 0);
    $hotspotIds[] = $legacyPin;
    [$legacyStatus, $legacyPreview] = $post($port, $sessionId, $csrf, ['action' => 'preview_missing', 'id' => $missingId]);
    $assert($legacyStatus === 200 && ($legacyPreview['can_remove'] ?? true) === false
        && (int)($legacyPreview['plan']['legacy_references'] ?? 0) === 1,
        'external legacy positional navigation blocks missing-record cleanup');
    $deleteLegacy = $conn->prepare('DELETE FROM showroom_hotspots WHERE id = ?');
    if (!$deleteLegacy) throw new RuntimeException('Could not reset the disposable legacy-link fixture.');
    $deleteLegacy->bind_param('i', $legacyPin);
    if (!$deleteLegacy->execute()) throw new RuntimeException('Could not reset the disposable legacy-link fixture.');

    [$removePreviewStatus, $removePreview] = $post($port, $sessionId, $csrf, ['action' => 'preview_missing', 'id' => $missingId]);
    $removePlan = $removePreview['plan'] ?? [];
    $assert($removePreviewStatus === 200 && ($removePreview['can_remove'] ?? false) === true,
        'cleanup can proceed after the unsafe positional link is removed');
    [$removeStatus, $removed] = $post($port, $sessionId, $csrf, ['action' => 'remove_missing', 'id' => $missingId, 'expected' => $removePlan]);
    $assert($removeStatus === 200 && ($removed['success'] ?? false) === true,
        'confirmed cleanup removes the selected missing media record');
    $assert($countRows($conn, 'SELECT COUNT(*) AS c FROM media_cms WHERE id IN (?, ?, ?)', 'iii', $mediaIds) === 2,
        'successful cleanup retains both readable panorama rows in the slot');
    $assert($countRows($conn, 'SELECT COUNT(*) AS c FROM showroom_hotspots WHERE id IN (?, ?)', 'ii', [$ownPin, $incomingPin]) === 0
        && $countRows($conn, 'SELECT COUNT(*) AS c FROM showroom_hotspots WHERE id = ?', 'i', [$validPin]) === 1,
        'cleanup removes selected and incoming pins while preserving the unrelated valid-scene pin');
    $assert(is_file($sourceAbsolute) && hash_file('sha256', $sourceAbsolute) === $sourceHash && filemtime($sourceAbsolute) === $sourceMtime,
        'missing-only cleanup never unlinks or changes the shared source image');

    $guardPin = $insertHotspot($conn, $validSourceB, 'nav', $validSourceA, null);
    $hotspotIds[] = $guardPin;
    [$normalStatus, $normal] = $post($port, $sessionId, $csrf, ['id' => $validSourceA]);
    $assert($normalStatus === 200 && ($normal['success'] ?? true) === false
        && ($normal['message'] ?? '') === 'This panorama is targeted by a navigation hotspot and cannot be deleted.',
        'ordinary panorama deletion still rejects a stable incoming hotspot target');
    $assert($countRows($conn, 'SELECT COUNT(*) AS c FROM media_cms WHERE id = ?', 'i', [$validSourceA]) === 1,
        'ordinary deletion guard leaves the readable panorama row in place');

    echo "PASS|$checks local HTTP missing-media cleanup endpoint checks\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    try {
        if ($mediaIds) {
            $idPlaceholders = implode(',', array_fill(0, count($mediaIds), '?'));
            $types = str_repeat('i', count($mediaIds));
            $deleteHotspots = $conn->prepare("DELETE FROM showroom_hotspots WHERE media_id IN ($idPlaceholders) OR target_media_id IN ($idPlaceholders)");
            if ($deleteHotspots) {
                $values = array_merge($mediaIds, $mediaIds);
                $refs = [];
                foreach ($values as &$value) $refs[] = &$value;
                unset($value);
                $deleteHotspots->bind_param($types . $types, ...$refs);
                $deleteHotspots->execute();
            }
            $deleteMedia = $conn->prepare("DELETE FROM media_cms WHERE id IN ($idPlaceholders)");
            if ($deleteMedia) { $deleteMedia->bind_param($types, ...$mediaIds); $deleteMedia->execute(); }
        }
    } catch (Throwable $cleanupError) {
        error_log('Missing-media endpoint fixture cleanup failed: ' . get_class($cleanupError));
    }
    if ($restoredTestSource) @unlink($missingAbsolute);
    foreach (glob($tempDir . '/*') ?: [] as $path) @unlink($path);
    @rmdir($tempDir);
    if (is_file($sourceAbsolute) && hash_file('sha256', $sourceAbsolute) !== $sourceHash) {
        error_log('Shared source panorama changed during missing-media endpoint test.');
    }
}
