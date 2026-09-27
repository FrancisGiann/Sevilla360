<?php
require_once __DIR__ . '/../../includes/session_init.php';
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)) { http_response_code(401); exit('Unauthorized'); }
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="sevilla360-seminar-attendees-template.csv"');
header('X-Content-Type-Options: nosniff');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Name', 'Gender', 'Location', 'Contact']);
fputcsv($out, ['Sample Attendee', 'Female', 'Sevilla', '']);
fclose($out);
