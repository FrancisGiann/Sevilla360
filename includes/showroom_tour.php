<?php
declare(strict_types=1);

function showroom_tour_is_admin(?string $role): bool
{
    return $role === 'admin';
}

function showroom_tour_is_panorama(?string $media_type): bool
{
    return $media_type === '360';
}

/** @return array{media_id:int,view:?array{x:float,y:float,z:float,fov:float}} */
function showroom_tour_validate_view_request(stdClass $payload): array
{
    $data = get_object_vars($payload);
    $media_id = $data['media_id'] ?? null;
    if (!is_int($media_id) || $media_id < 1) {
        throw new InvalidArgumentException('media_id must be a positive integer.');
    }
    if (!array_key_exists('view', $data)) {
        throw new InvalidArgumentException('view must be an object or null.');
    }
    if ($data['view'] === null) {
        return ['media_id' => $media_id, 'view' => null];
    }
    if (!$data['view'] instanceof stdClass) {
        throw new InvalidArgumentException('view must be an object or null.');
    }

    $view = get_object_vars($data['view']);
    foreach (['x', 'y', 'z', 'fov'] as $field) {
        if (!array_key_exists($field, $view) || (!is_int($view[$field]) && !is_float($view[$field])) || !is_finite((float)$view[$field])) {
            throw new InvalidArgumentException('View coordinates and FOV must be finite numbers.');
        }
    }

    $x = (float)$view['x'];
    $y = (float)$view['y'];
    $z = (float)$view['z'];
    $fov = (float)$view['fov'];
    $vector_length = sqrt(($x * $x) + ($y * $y) + ($z * $z));
    if (max(abs($x), abs($y), abs($z)) > 10000 || !is_finite($vector_length) || $vector_length < 0.1 || $vector_length > 10000) {
        throw new InvalidArgumentException('View coordinates are outside the supported range.');
    }
    if ($fov < 30 || $fov > 100) {
        throw new InvalidArgumentException('FOV must be between 30 and 100 degrees.');
    }

    return ['media_id' => $media_id, 'view' => ['x' => $x, 'y' => $y, 'z' => $z, 'fov' => $fov]];
}

function showroom_tour_validate_arrow_rotation(mixed $rotation): int
{
    if (!is_int($rotation) || $rotation < 0 || $rotation > 359) {
        throw new InvalidArgumentException('Arrow rotation must be an integer from 0 to 359.');
    }
    return $rotation;
}

/** Group eligible hotel showroom venues by room type without changing their stable venue keys. */
function showroom_tour_group_hotel_venues(array $venues): array
{
    $grouped = [];
    foreach ($venues as $venue_id => $venue) {
        if (!is_array($venue)) continue;
        $room_type = trim((string)($venue['room_type'] ?? ''));
        $group_label = $room_type !== '' ? $room_type : 'Other room types';
        $grouped[$group_label][$venue_id] = $venue;
    }

    uksort($grouped, static fn($left, $right): int => strnatcasecmp((string)$left, (string)$right));
    foreach ($grouped as &$room_venues) {
        uasort($room_venues, static fn(array $left, array $right): int => strnatcasecmp(
            (string)($left['venue_name'] ?? ''),
            (string)($right['venue_name'] ?? '')
        ));
    }
    unset($room_venues);

    return $grouped;
}

/** Resolve a hotspot target against stable media IDs, with an id-order legacy fallback only when no stable ID exists. */
function showroom_tour_resolve_target_media_id(
    int $source_media_id,
    mixed $stable_target_media_id,
    mixed $legacy_target_index,
    array $legacy_media_ids,
    array $venue_media_ids
): ?int {
    if ($stable_target_media_id !== null) {
        $target_id = filter_var($stable_target_media_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $target_id && $target_id !== $source_media_id && in_array($target_id, array_map('intval', $venue_media_ids), true)
            ? (int)$target_id
            : null;
    }

    $index = filter_var($legacy_target_index, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($index === false || !array_key_exists((int)$index, $legacy_media_ids)) return null;
    $target_id = (int)$legacy_media_ids[(int)$index];
    return $target_id > 0 && $target_id !== $source_media_id && in_array($target_id, array_map('intval', $venue_media_ids), true)
        ? $target_id
        : null;
}
