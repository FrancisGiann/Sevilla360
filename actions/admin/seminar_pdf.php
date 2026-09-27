<?php
require_once __DIR__ . '/../../includes/session_init.php';
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)) { http_response_code(401); exit('Unauthorized'); }
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/seminars.php';
$seminarId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$type = $_GET['type'] ?? '';
if ($seminarId === false || !in_array($type, ['rooms', 'room', 'list'], true)) { http_response_code(422); exit('Invalid PDF request.'); }
$roomId = null;
if ($type === 'room') {
    $roomId = filter_var($_GET['room_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($roomId === false) { http_response_code(422); exit('Invalid room request.'); }
}
$stmt = $conn->prepare("SELECT s.name,s.status,s.hall_start_date,s.hall_end_date,s.hotel_check_in,s.hotel_check_out,v.name AS hall_name FROM seminars s JOIN venues v ON v.id=s.hall_venue_id WHERE s.id=?");
$stmt->bind_param('i', $seminarId); $stmt->execute(); $seminar = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$seminar) { http_response_code(404); exit('Seminar not found.'); }
if ($seminar['status'] !== 'finalized') { http_response_code(409); exit('PDFs are available only after finalization.'); }
$stmt = $conn->prepare("SELECT sr.venue_id,COALESCE(NULLIF(TRIM(h.floor_label), ''), sr.floor_label) AS floor_label,v.name,h.room_number,h.room_type,h.max_capacity FROM seminar_reservations sr JOIN venues v ON v.id=sr.venue_id JOIN hotel_rooms h ON h.venue_id=sr.venue_id WHERE sr.seminar_id=? AND sr.resource_kind='room' ORDER BY v.name,h.room_number,sr.venue_id");
$stmt->bind_param('i', $seminarId); $stmt->execute(); $rooms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
$stmt = $conn->prepare('SELECT a.full_name,a.gender,a.location,a.assigned_venue_id FROM seminar_attendees a WHERE a.seminar_id=? ORDER BY a.full_name COLLATE utf8mb4_unicode_ci,a.id');
$stmt->bind_param('i', $seminarId); $stmt->execute(); $attendees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
if ($type === 'room') {
    $selectedRoom = seminar_occupied_pdf_room($rooms, $attendees, (int)$roomId);
    if ($selectedRoom === null) { http_response_code(404); exit('Room sheet not found.'); }
    $rooms = [$selectedRoom];
}
if (!is_file(__DIR__ . '/../../vendor/autoload.php')) { http_response_code(503); exit('PDF generation is unavailable.'); }
require_once __DIR__ . '/../../vendor/autoload.php';
if (!class_exists(Dompdf\Dompdf::class)) { http_response_code(503); exit('PDF generation is unavailable.'); }
$assetRoot = realpath(__DIR__ . '/../../assets');
$dompdfRoot = realpath(__DIR__ . '/../../vendor/dompdf/dompdf');
if ($assetRoot === false || $dompdfRoot === false) { http_response_code(503); exit('PDF generation is unavailable.'); }
$renderType = $type === 'list' ? 'list' : 'rooms';
$html = seminar_render_pdf_html($seminar, $rooms, $attendees, $renderType);
$dompdf = new Dompdf\Dompdf([
    'isRemoteEnabled' => false,
    'isPhpEnabled' => false,
    'chroot' => [$assetRoot, $dompdfRoot],
]);
$dompdf->loadHtml($html, 'UTF-8'); $dompdf->setPaper('A4', 'portrait'); $dompdf->render();
$filename = 'seminar-' . (int)$seminarId . '-' . $type . ($type === 'room' ? '-' . (int)$roomId : '') . '.pdf';
$dompdf->stream($filename, ['Attachment' => false]);
