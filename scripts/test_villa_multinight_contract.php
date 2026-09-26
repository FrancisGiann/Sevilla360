<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/booking_rules.php';
require_once __DIR__ . '/../includes/pricing.php';

function villa_test_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function villa_test_throws(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException($message);
}

final class VillaPricingResult
{
    public function __construct(private array $row) {}
    public function fetch_assoc(): array { return $this->row; }
}

final class VillaPricingStatement
{
    private int $venueId = 0;
    public function __construct(private string $query) {}
    public function bind_param(string $types, &...$values): bool
    {
        $this->venueId = (int)($values[0] ?? 0);
        return true;
    }
    public function execute(): bool { return true; }
    public function get_result(): VillaPricingResult
    {
        return new VillaPricingResult([
            'day_rate' => 3500,
            'overnight_rate' => 6500,
            'base_capacity' => 4,
            'max_capacity' => 6,
            'extra_pax_rate' => 500,
        ]);
    }
}

final class VillaPricingConnection
{
    public function prepare(string $query): VillaPricingStatement { return new VillaPricingStatement($query); }
}

$conn = new VillaPricingConnection();
$pricingCases = [
    ['one-night 4 guests', '2035-09-03', '2035-09-04', 4, 'Overnight', 6500.0, 6500.0],
    ['two-night 4 guests', '2035-09-03', '2035-09-05', 4, 'Overnight', 13000.0, 13000.0],
    ['one-night 5 guests', '2035-09-03', '2035-09-04', 5, 'Overnight', 6500.0, 7000.0],
    ['two-night 5 guests', '2035-09-03', '2035-09-05', 5, 'Overnight', 13000.0, 14000.0],
    ['day stay 4 guests', '2035-09-03', '2035-09-03', 4, 'Day Time Stay', 3500.0, 3500.0],
    ['day stay 5 guests', '2035-09-03', '2035-09-03', 5, 'Day Time Stay', 3500.0, 4000.0],
];
foreach ($pricingCases as [$label, $start, $end, $guests, $stayType, $base, $total]) {
    $result = calculate_booking_price($conn, 1, 'Resort Villa', $start, $end, $guests, $stayType);
    villa_test_assert($result['base_amount'] === $base, "$label base amount should be {$base}.");
    villa_test_assert($result['true_total'] === $total, "$label total should be {$total}.");
}

$oneNightStart = new DateTimeImmutable('2035-09-03');
$oneNightEnd = new DateTimeImmutable('2035-09-04');
$twoNightEnd = new DateTimeImmutable('2035-09-05');
validate_villa_stay_dates('Resort Villa', 'Overnight', $oneNightStart, $oneNightEnd);
validate_villa_stay_dates('Resort Villa', 'Overnight', $oneNightStart, $twoNightEnd);
validate_villa_stay_dates('Resort Villa', 'Day Time Stay', $oneNightStart, $oneNightStart);
villa_test_throws(static fn() => validate_villa_stay_dates('Resort Villa', 'Overnight', $oneNightStart, $oneNightStart), 'Same-day Overnight must be rejected.');
villa_test_throws(static fn() => validate_villa_stay_dates('Resort Villa', 'Day Time Stay', $oneNightStart, $oneNightEnd), 'Multi-date Day Time Stay must be rejected.');

$schedule = villa_breakfast_schedule('Overnight', $oneNightStart, $twoNightEnd);
villa_test_assert($schedule === ['count' => 2, 'first_morning' => '2035-09-04', 'last_morning' => '2035-09-05'], 'Two-night stay must include Tuesday and checkout Wednesday breakfast mornings.');
villa_test_assert(villa_breakfast_schedule('Overnight', $oneNightStart, $oneNightEnd) === ['count' => 1, 'first_morning' => '2035-09-04', 'last_morning' => '2035-09-04'], 'One-night stay must include breakfast on checkout morning.');
villa_test_assert(villa_breakfast_schedule('Day Time Stay', $oneNightStart, $oneNightStart)['count'] === 0, 'Day Time Stay has no overnight breakfast entitlement.');
$summary = villa_booking_detail_summary('Overnight', '2035-09-03', '2035-09-05', 'Breakfast for 4');
villa_test_assert(str_contains($summary, '2 nights') && str_contains($summary, 'Tue, Sep 4') && str_contains($summary, 'Wed, Sep 5'), 'Villa detail summary must identify the number of nights and each included breakfast morning.');

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$contracts = [
    'customer availability includes villa checkout dates, maintenance, and active locks' => str_contains($read('actions/bookings/fetch_dates.php'), '($currentDate <= $endDate)') && str_contains($read('actions/bookings/fetch_dates.php'), "AND expires_at > NOW()") && str_contains($read('actions/bookings/fetch_dates.php'), '$hardBlockedDates[] = $date;'),
    'holds and online submission validate the villa stay and use overlap rules' => str_contains($read('actions/bookings/lock_dates.php'), 'validate_villa_stay_dates') && str_contains($read('actions/bookings/lock_dates.php'), 'booking_overlap_sql($room_type)') && str_contains($read('actions/bookings/submit_online.php'), 'validate_villa_stay_dates') && str_contains($read('actions/bookings/submit_online.php'), 'calculate_booking_price'),
    'homepage passes villa stay type and selected dates into booking page prefill' => str_contains($read('assets/js/index.js'), "params.set('stay_type', villaStayType)") && str_contains($read('assets/js/index.js'), 'params.set(venue.room_group_id ? \'check_in\' : \'start_date\', dates.startDate)') && str_contains($read('index.php'), 'idx-modal-villa-stay') && str_contains($read('assets/js/booking.js'), "const requestedStay = params.get('stay_type')") && str_contains($read('assets/js/booking.js'), 'calendar.setSelection(start, end)'),
    'customer reschedule preserves villa duration and validates the resulting range' => str_contains($read('actions/user/request_reschedule.php'), '$req_end = $duration > 0 ? $req_start->modify("+{$duration} days") : $req_start;') && str_contains($read('actions/user/request_reschedule.php'), 'validate_villa_stay_dates($booking[\'category\'], $stay_type, $req_start, $req_end)'),
    'staff reschedule keeps Villa duration and checks inclusive conflicts' => str_contains($read('actions/admin/update_booking_status.php'), 'Rescheduling must keep the original booking duration.') && str_contains($read('actions/admin/update_booking_status.php'), 'maintenance_overlap_sql()') && str_contains($read('actions/admin/update_booking_status.php'), "booking_overlap_sql('Resort Villa')") && str_contains($read('assets/js/admin-page/admin_bookings.js'), 'isVillaReschedule ? durationNights : null'),
    'staff maintenance and master calendars show villa checkout dates and stay details' => str_contains($read('actions/admin/fetch_maintenance_calendar.php'), "\$venue_cat === 'Hotel Room'") && str_contains($read('actions/admin/get_master_calendar.php'), "['Event Hall', 'Resort Villa']") && str_contains($read('actions/admin/get_master_calendar.php'), 'villa_booking_detail_summary') && str_contains($read('assets/js/admin-page/admin_calendar.js'), 'props.villaSummary'),
    'staff and customer details expose villa night and breakfast summaries' => str_contains($read('actions/admin/get_booking_details.php'), 'villa_booking_detail_summary') && str_contains($read('actions/user/get_my_booking_details.php'), 'villa_booking_detail_summary') && str_contains($read('assets/js/admin-page/admin_bookings.js'), 'specifics.summary') && str_contains($read('assets/js/user_dashboard.js'), 'specifics.summary'),
    'receipt and booking email include the villa night and breakfast summary' => str_contains($read('print_receipt.php'), 'villa_booking_detail_summary') && str_contains($read('includes/mailer.php'), 'villa_booking_detail_summary'),
];
foreach ($contracts as $label => $passed) villa_test_assert($passed, "Contract failed: $label");

echo 'PASS|villa multi-night pricing, date rules, breakfasts, and booking flow contracts' . PHP_EOL;
