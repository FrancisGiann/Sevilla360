<?php
/** Advisory poll for an active booking screen. Submission and payment acquisition recheck under locks. */
require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/selection_availability.php';
header('Content-Type: application/json; charset=utf-8');
try {
    if (($_SESSION['role'] ?? '') !== 'customer') throw new RuntimeException('Sign in to check the selected room.');
    $start = is_string($_GET['start_date'] ?? null) ? $_GET['start_date'] : '';
    $end = is_string($_GET['end_date'] ?? null) ? $_GET['end_date'] : '';
    foreach ([$start, $end] as $date) {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new RuntimeException('Choose valid dates.');
    }
    if ($end < $start) throw new RuntimeException('Choose valid dates.');
    $category = (string)($_GET['category'] ?? '');
    if (!in_array($category, ['Hotel Room', 'Resort Villa'], true)) throw new RuntimeException('Choose a room or villa.');
    $venueId = filter_var($_GET['venue_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$venueId && $category === 'Hotel Room') $venueId = (int)($_SESSION['locked_venue_id'] ?? 0);
    if (!$venueId && $category === 'Resort Villa') {
        $name = is_string($_GET['room_name'] ?? null) ? trim($_GET['room_name']) : '';
        $lookup = $conn->prepare('SELECT id FROM venues WHERE category = ? AND name = ? LIMIT 1');
        $lookup->bind_param('ss', $category, $name);
        if (!$lookup->execute()) throw new RuntimeException('Unable to check the selected villa.');
        $venueId = (int)($lookup->get_result()->fetch_assoc()['id'] ?? 0);
    }
    if (!$venueId) throw new RuntimeException('Choose a specific room or villa.');
    if ($category === 'Hotel Room' && $end <= $start) throw new RuntimeException('Choose a checkout after check-in.');
    echo json_encode(['success'=>true,'state'=>booking_selection_availability_state($conn, (int)$venueId, $category, $start, $end, session_id())]);
} catch (Throwable $error) {
    http_response_code(422);
    echo json_encode(['success'=>false,'message'=>$error instanceof RuntimeException ? $error->getMessage() : 'Availability could not be checked.']);
}
