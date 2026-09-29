<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$showroom = (string)file_get_contents($root . '/assets/js/showroom.js');
$fetchDates = (string)file_get_contents($root . '/actions/bookings/fetch_dates.php');
$hotelAvailability = (string)file_get_contents($root . '/actions/bookings/get_room_availability.php');

$slice = static function (string $source, string $start, string $end): string {
    $startAt = strpos($source, $start);
    if ($startAt === false) return '';
    $endAt = strpos($source, $end, $startAt + strlen($start));
    return $endAt === false ? '' : substr($source, $startAt, $endAt - $startAt);
};

$availabilityRequest = $slice($showroom, 'const requestVenueAvailability = async', 'const renderHotelRecommendationList =');
$availabilityFlow = $slice($showroom, 'const checkGuideAvailability = async', 'const appendFact =');
$checks = [
    'selected event availability is sent by venue id and the endpoint scopes that id to its category' => str_contains($availabilityRequest, 'params.set("venue_id", String(room.venue_id))')
        && str_contains($fetchDates, 'SELECT id, status FROM venues WHERE id = ? AND category = ? LIMIT 1')
        && str_contains($fetchDates, "in_array(\$room_type, ['Event Hall', 'Resort Villa'], true)"),
    'selected hotel availability is sent by room group id to the exact group endpoint' => str_contains($availabilityRequest, 'params.set("room_group_id", String(room.room_group_id))')
        && str_contains($availabilityRequest, 'actions/bookings/get_room_availability.php')
        && str_contains($hotelAvailability, "\$groupIds = [(int)\$groupId]"),
    'selected venue checks stay exact while unselected searches retain category-wide behavior' => strpos($availabilityFlow, 'if (activeVenueId > 0)') !== false
        && strpos($availabilityFlow, 'await checkSelectedVenueAvailability(room, startDate, startDate)') !== false
        && strpos($availabilityFlow, 'await requestHotelRecommendations()') !== false
        && strpos($availabilityFlow, 'venuesForCategory(receptionistState.activeCategory)') !== false,
    'selected venue result reports only endpoint-confirmed availability and retains accessible actions' => str_contains($availabilityFlow, 'const checkSelectedVenueAvailability = async')
        && str_contains($availabilityFlow, 'guideState.availabilityStatus = available ? "confirmed" : "none"')
        && str_contains($availabilityFlow, 'This venue is available')
        && str_contains($availabilityFlow, 'This venue is unavailable')
        && str_contains($availabilityFlow, 'data-receptionist-room": room.id')
        && str_contains($availabilityFlow, 'focusFirstChoice();'),
    'selected venue failures stay non-affirmative and stale responses are discarded' => str_contains($availabilityFlow, 'const isCurrent = () => token === guideState.availabilityRequestToken')
        && substr_count($availabilityFlow, 'if (!isCurrent()) return;') >= 2
        && str_contains($availabilityFlow, 'guideState.availabilityStatus = "error"')
        && str_contains($availabilityFlow, 'renderAvailabilityError();'),
    'server rejects invalid or missing exact event ids rather than falling back to a duplicate name' => str_contains($fetchDates, "\$venue_id === false")
        && str_contains($fetchDates, "WHERE id = ? AND category = ? LIMIT 1")
        && str_contains($fetchDates, 'Selected venue is no longer available.'),
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . " — {$name}\n";
    if (!$passed) $failed[] = $name;
}
if ($failed) exit(1);
echo "All selected receptionist availability contract checks passed.\n";
