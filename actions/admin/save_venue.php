<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db_connect.php';

function venue_response(bool $success, string $message, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

function venue_text(string $key, int $max, bool $required = false): string
{
    $value = trim((string)($_POST[$key] ?? ''));
    if ($required && $value === '') throw new InvalidArgumentException("{$key} is required.");
    if (mb_strlen($value) > $max) throw new InvalidArgumentException("{$key} is too long.");
    return $value;
}

function venue_int(string $key, int $min, ?int $max = null, bool $required = true): int
{
    $raw = trim((string)($_POST[$key] ?? ''));
    if ($raw === '' && !$required) return 0;
    if (!preg_match('/\A\d+\z/D', $raw)) throw new InvalidArgumentException("{$key} must be a whole number.");
    $value = (int)$raw;
    if ($value < $min || ($max !== null && $value > $max)) throw new InvalidArgumentException("{$key} is outside the allowed range.");
    return $value;
}

function venue_money(string $key): float
{
    $raw = trim((string)($_POST[$key] ?? ''));
    if (!preg_match('/\A(?:\d+(?:\.\d{1,2})?|\.\d{1,2})\z/D', $raw) || !is_finite((float)$raw)) {
        throw new InvalidArgumentException("{$key} must be a valid non-negative amount.");
    }
    $value = (float)$raw;
    if ($value > 100000000) throw new InvalidArgumentException("{$key} is too large.");
    return $value;
}

function venue_time(string $key, string $default): string
{
    $raw = trim((string)($_POST[$key] ?? $default));
    if (!preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?\z/D', $raw)) throw new InvalidArgumentException("{$key} must be a valid time.");
    return strlen($raw) === 5 ? $raw . ':00' : $raw;
}

function venue_exec(mysqli_stmt $stmt): void
{
    $stmt->execute();
    $stmt->close();
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') venue_response(false, 'Unauthorized access.', 403);
$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !is_string($client_csrf_token) || !hash_equals($_SESSION['csrf_token'], $client_csrf_token)) {
    venue_response(false, 'CSRF validation failed. Unauthorized request.', 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') venue_response(false, 'Invalid request method.', 405);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $venue_id_raw = trim((string)($_POST['venue_id'] ?? ''));
    $venue_id = 0;
    if ($venue_id_raw !== '') {
        if (!preg_match('/\A\d+\z/D', $venue_id_raw) || (int)$venue_id_raw < 1) throw new InvalidArgumentException('Invalid venue identifier.');
        $venue_id = (int)$venue_id_raw;
    }

    $allowed_categories = ['Event Hall', 'Hotel Room', 'Resort Villa'];
    $allowed_statuses = ['Available', 'Maintenance', 'Inactive'];
    $name = venue_text('name', 150, true);
    $category = trim((string)($_POST['category'] ?? ''));
    $status = trim((string)($_POST['status'] ?? ''));
    if (!in_array($category, $allowed_categories, true)) throw new InvalidArgumentException('Invalid venue category.');
    if (!in_array($status, $allowed_statuses, true)) throw new InvalidArgumentException('Invalid venue status.');
    $description = venue_text('description', 5000);
    $amenities = venue_text('amenities', 10000);
    $base_capacity = venue_int('base_capacity', 1, 100000);
    $max_capacity = venue_int('max_capacity', $base_capacity, 100000);
    $is_bulk = $category === 'Hotel Room' && !$venue_id && (string)($_POST['is_bulk'] ?? '') === '1';

    $conn->begin_transaction();
    if ($venue_id === 0) {
        $new_venue = $conn->prepare('INSERT INTO venues (category, name, status, description, amenities) VALUES (?, ?, ?, ?, ?)');
        $insert_venue = static function () use (&$new_venue, $category, $name, $status, $description, $amenities, $conn): int {
            $new_venue->bind_param('sssss', $category, $name, $status, $description, $amenities);
            $new_venue->execute();
            return (int)$conn->insert_id;
        };

        if ($is_bulk) {
            $quantity = venue_int('bulk_quantity', 1, 100);
            $start = trim((string)($_POST['bulk_start_number'] ?? ''));
            if (!preg_match('/\A([A-Za-z]+-)?(\d{1,6})\z/D', $start, $parts)) throw new InvalidArgumentException('Starting room number must be numeric or use a letter prefix.');
            $prefix = $parts[1] ?? '';
            $number = (int)$parts[2];
            if ($number + $quantity - 1 > 999999) throw new InvalidArgumentException('Bulk room range is too large.');
            $room_type = venue_text('room_type', 100, true);
            $bed_count = venue_int('bed_count', 1, $max_capacity);
            $nightly_rate = venue_money('nightly_rate');
            $extra_pax_rate = venue_money('extra_pax_rate');
            $check_in = venue_time('check_in_time', '14:00');
            $check_out = venue_time('check_out_time', '12:00');
            $room_stmt = $conn->prepare('INSERT INTO hotel_rooms (venue_id, room_type, room_number, bed_count, base_capacity, max_capacity, nightly_rate, extra_pax_rate, check_in_time, check_out_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            for ($offset = 0; $offset < $quantity; $offset++) {
                $new_id = $insert_venue();
                $formatted = $prefix . str_pad((string)($number + $offset), strlen($parts[2]), '0', STR_PAD_LEFT);
                $room_stmt->bind_param('issiiiddss', $new_id, $room_type, $formatted, $bed_count, $base_capacity, $max_capacity, $nightly_rate, $extra_pax_rate, $check_in, $check_out);
                $room_stmt->execute();
            }
            $room_stmt->close();
            $new_venue->close();
            $message = "{$quantity} hotel rooms created successfully!";
        } else {
            $new_id = $insert_venue();
            $new_venue->close();
            if ($category === 'Event Hall') {
                $base_rate = venue_money('base_rate');
                $capacity_theater = venue_int('capacity_theater', 0, 100000);
                $capacity_classroom = venue_int('capacity_classroom', 0, 100000);
                $capacity_banquet = venue_int('capacity_banquet', 0, 100000);
                $stmt = $conn->prepare('INSERT INTO event_halls (venue_id, base_capacity, max_capacity, base_rate, capacity_theater, capacity_classroom, capacity_banquet) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('iiidiii', $new_id, $base_capacity, $max_capacity, $base_rate, $capacity_theater, $capacity_classroom, $capacity_banquet);
                venue_exec($stmt);
            } elseif ($category === 'Hotel Room') {
                $room_type = venue_text('room_type', 100, true);
                $room_number = venue_text('room_number', 20, true);
                if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9 -]{0,19}\z/D', $room_number)) throw new InvalidArgumentException('Room number contains unsupported characters.');
                $bed_count = venue_int('bed_count', 1, $max_capacity);
                $nightly_rate = venue_money('nightly_rate');
                $extra_pax_rate = venue_money('extra_pax_rate');
                $check_in = venue_time('check_in_time', '14:00');
                $check_out = venue_time('check_out_time', '12:00');
                $stmt = $conn->prepare('INSERT INTO hotel_rooms (venue_id, room_type, room_number, bed_count, base_capacity, max_capacity, nightly_rate, extra_pax_rate, check_in_time, check_out_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('issiiiddss', $new_id, $room_type, $room_number, $bed_count, $base_capacity, $max_capacity, $nightly_rate, $extra_pax_rate, $check_in, $check_out);
                venue_exec($stmt);
            } else {
                $day_rate = venue_money('day_rate');
                $overnight_rate = venue_money('overnight_rate');
                $extra_pax_rate = venue_money('extra_pax_rate');
                $has_private_pool = isset($_POST['has_private_pool']) && (string)$_POST['has_private_pool'] === '1' ? 1 : 0;
                $day_check_in = venue_time('day_check_in_time', '07:00');
                $day_check_out = venue_time('day_check_out_time', '17:00');
                $overnight_check_in = venue_time('overnight_check_in_time', '14:00');
                $overnight_check_out = venue_time('overnight_check_out_time', '12:00');
                $day_inclusions = venue_text('day_stay_inclusions', 5000);
                $overnight_inclusions = venue_text('overnight_stay_inclusions', 5000);
                $stmt = $conn->prepare('INSERT INTO villas (venue_id, base_capacity, max_capacity, day_rate, overnight_rate, extra_pax_rate, has_private_pool, day_check_in_time, day_check_out_time, overnight_check_in_time, overnight_check_out_time, day_stay_inclusions, overnight_stay_inclusions) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('iiidddissssss', $new_id, $base_capacity, $max_capacity, $day_rate, $overnight_rate, $extra_pax_rate, $has_private_pool, $day_check_in, $day_check_out, $overnight_check_in, $overnight_check_out, $day_inclusions, $overnight_inclusions);
                venue_exec($stmt);
            }
            $message = 'New venue added successfully!';
        }
    } else {
        $lock = $conn->prepare('SELECT category FROM venues WHERE id = ? FOR UPDATE');
        $lock->bind_param('i', $venue_id);
        $lock->execute();
        $existing = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$existing || $existing['category'] !== $category) throw new InvalidArgumentException('Venue was not found or its category changed.');
        $stmt = $conn->prepare('UPDATE venues SET name = ?, status = ?, description = ?, amenities = ? WHERE id = ?');
        $stmt->bind_param('ssssi', $name, $status, $description, $amenities, $venue_id);
        venue_exec($stmt);

        if ($category === 'Event Hall') {
            $base_rate = venue_money('base_rate');
            $capacity_theater = venue_int('capacity_theater', 0, 100000);
            $capacity_classroom = venue_int('capacity_classroom', 0, 100000);
            $capacity_banquet = venue_int('capacity_banquet', 0, 100000);
            $stmt = $conn->prepare('UPDATE event_halls SET base_capacity = ?, max_capacity = ?, base_rate = ?, capacity_theater = ?, capacity_classroom = ?, capacity_banquet = ? WHERE venue_id = ?');
            $stmt->bind_param('iidiiii', $base_capacity, $max_capacity, $base_rate, $capacity_theater, $capacity_classroom, $capacity_banquet, $venue_id);
            venue_exec($stmt);
        } elseif ($category === 'Hotel Room') {
            $room_type = venue_text('room_type', 100, true);
            $room_number = venue_text('room_number', 20, true);
            if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9 -]{0,19}\z/D', $room_number)) throw new InvalidArgumentException('Room number contains unsupported characters.');
            $bed_count = venue_int('bed_count', 1, $max_capacity);
            $nightly_rate = venue_money('nightly_rate');
            $extra_pax_rate = venue_money('extra_pax_rate');
            $check_in = venue_time('check_in_time', '14:00');
            $check_out = venue_time('check_out_time', '12:00');
            $stmt = $conn->prepare('UPDATE hotel_rooms SET room_type = ?, room_number = ?, bed_count = ?, base_capacity = ?, max_capacity = ?, nightly_rate = ?, extra_pax_rate = ?, check_in_time = ?, check_out_time = ? WHERE venue_id = ?');
            $stmt->bind_param('ssiiiddssi', $room_type, $room_number, $bed_count, $base_capacity, $max_capacity, $nightly_rate, $extra_pax_rate, $check_in, $check_out, $venue_id);
            venue_exec($stmt);
        } else {
            $day_rate = venue_money('day_rate');
            $overnight_rate = venue_money('overnight_rate');
            $extra_pax_rate = venue_money('extra_pax_rate');
            $has_private_pool = isset($_POST['has_private_pool']) && (string)$_POST['has_private_pool'] === '1' ? 1 : 0;
            $day_check_in = venue_time('day_check_in_time', '07:00');
            $day_check_out = venue_time('day_check_out_time', '17:00');
            $overnight_check_in = venue_time('overnight_check_in_time', '14:00');
            $overnight_check_out = venue_time('overnight_check_out_time', '12:00');
            $day_inclusions = venue_text('day_stay_inclusions', 5000);
            $overnight_inclusions = venue_text('overnight_stay_inclusions', 5000);
            $stmt = $conn->prepare('UPDATE villas SET base_capacity = ?, max_capacity = ?, day_rate = ?, overnight_rate = ?, extra_pax_rate = ?, has_private_pool = ?, day_check_in_time = ?, day_check_out_time = ?, overnight_check_in_time = ?, overnight_check_out_time = ?, day_stay_inclusions = ?, overnight_stay_inclusions = ? WHERE venue_id = ?');
            $stmt->bind_param('iidddissssssi', $base_capacity, $max_capacity, $day_rate, $overnight_rate, $extra_pax_rate, $has_private_pool, $day_check_in, $day_check_out, $overnight_check_in, $overnight_check_out, $day_inclusions, $overnight_inclusions, $venue_id);
            venue_exec($stmt);
        }
        $message = 'Venue updated successfully!';
    }
    $conn->commit();
    venue_response(true, $message);
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) { }
    error_log('save_venue failed: ' . $e->getMessage());
    $client_message = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to save venue. Please review the fields and try again.';
    venue_response(false, $client_message, 400);
}
