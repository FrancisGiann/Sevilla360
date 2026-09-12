<?php
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => false, 'message' => 'Online checkout is no longer available. Submit payment proof from your dashboard.']);
