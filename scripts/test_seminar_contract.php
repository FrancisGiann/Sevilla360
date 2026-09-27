<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/seminars.php';

$checks = [];
$check = static function (string $label, bool $passed) use (&$checks): void {
    $checks[$label] = $passed;
    if (!$passed) throw new RuntimeException('Failed: ' . $label);
};
$throws = static function (callable $callable): bool {
    try { $callable(); return false; } catch (InvalidArgumentException) { return true; }
};

$attendees = [];
for ($i = 1; $i <= 6; $i++) $attendees[] = ['id' => $i, 'gender' => 'male', 'location' => 'Sevilla'];
$allocation = seminar_allocate_attendees($attendees, [
    ['venue_id' => 101, 'max_capacity' => 5], ['venue_id' => 102, 'max_capacity' => 5],
]);
$counts = array_count_values($allocation['assignments']);
sort($counts);
$check('allocator balances six same-gender attendees into 4+2 instead of 5+1', $counts === [2, 4] && $allocation['solo'] === []);
$locationCohorts = [];
$cohortAttendees = [];
foreach ([['North', 3], ['South', 3]] as [$location, $size]) {
    for ($i = 0; $i < $size; $i++) {
        $id = count($cohortAttendees) + 1;
        $cohortAttendees[] = ['id' => $id, 'gender' => 'female', 'location' => $location];
        $locationCohorts[$location][] = $id;
    }
}
$cohortRooms = [['venue_id' => 201, 'max_capacity' => 4], ['venue_id' => 202, 'max_capacity' => 4]];
$cohortAllocation = seminar_allocate_attendees($cohortAttendees, $cohortRooms);
$check('allocator keeps 3-person locations in separate 4-capacity rooms', count(array_unique(array_intersect_key($cohortAllocation['assignments'], array_flip($locationCohorts['North'])))) === 1
    && count(array_unique(array_intersect_key($cohortAllocation['assignments'], array_flip($locationCohorts['South'])))) === 1
    && $cohortAllocation['assignments'][1] !== $cohortAllocation['assignments'][4]
    && count(array_count_values($cohortAllocation['assignments'])) === 2);
$normalizedLocations = [
    ['id' => 1, 'gender' => 'female', 'location' => '  Sevilla  '],
    ['id' => 2, 'gender' => 'female', 'location' => "sevilla\t"],
    ['id' => 3, 'gender' => 'female', 'location' => 'SEVILLA'],
];
$normalizedAllocation = seminar_allocate_attendees($normalizedLocations, [['venue_id' => 203, 'max_capacity' => 3]]);
$check('allocator normalizes location case and whitespace without changing attendee values', count(array_unique($normalizedAllocation['assignments'])) === 1
    && $normalizedLocations[0]['location'] === '  Sevilla  ' && $normalizedLocations[1]['location'] === "sevilla\t");
$loneLocationAllocation = seminar_allocate_attendees([
    ['id' => 1, 'gender' => 'male', 'location' => 'North'], ['id' => 2, 'gender' => 'male', 'location' => 'North'],
    ['id' => 3, 'gender' => 'male', 'location' => 'North'], ['id' => 4, 'gender' => 'male', 'location' => 'South'],
], [['venue_id' => 204, 'max_capacity' => 4], ['venue_id' => 205, 'max_capacity' => 4]]);
$check('allocator pairs a lone location attendee with same-gender attendees when feasible', count(array_unique($loneLocationAllocation['assignments'])) === 1
    && $loneLocationAllocation['solo'] === []);
$genderSeparated = seminar_allocate_attendees([
    ['id' => 1, 'gender' => 'female', 'location' => 'Sevilla'], ['id' => 2, 'gender' => 'female', 'location' => 'Sevilla'],
    ['id' => 3, 'gender' => 'male', 'location' => 'Sevilla'], ['id' => 4, 'gender' => 'male', 'location' => 'Sevilla'],
], [['venue_id' => 210, 'max_capacity' => 2], ['venue_id' => 211, 'max_capacity' => 2]]);
$check('allocator never mixes known genders automatically', $genderSeparated['assignments'][1] === $genderSeparated['assignments'][2]
    && $genderSeparated['assignments'][3] === $genderSeparated['assignments'][4]
    && $genderSeparated['assignments'][1] !== $genderSeparated['assignments'][3]);
$unavoidableSolo = seminar_allocate_attendees([
    ['id' => 1, 'gender' => 'female', 'location' => 'Solo location'],
], [['venue_id' => 212, 'max_capacity' => 3]]);
$check('allocator allows a solo assignment when no same-gender partner exists', $unavoidableSolo['assignments'] === [1 => 212]
    && isset($unavoidableSolo['solo'][1]));
$unevenAllocation = seminar_allocate_attendees($attendees, [
    ['venue_id' => 206, 'max_capacity' => 5], ['venue_id' => 207, 'max_capacity' => 2],
]);
$unevenCounts = array_count_values($unevenAllocation['assignments']);
sort($unevenCounts);
$check('allocator respects uneven 5+2 capacities and avoids a singleton when capacity allows', $unevenCounts === [2, 4] && $unevenAllocation['solo'] === []);
$oversizedAllocation = seminar_allocate_attendees($attendees, [
    ['venue_id' => 213, 'max_capacity' => 1], ['venue_id' => 214, 'max_capacity' => 4], ['venue_id' => 215, 'max_capacity' => 4],
]);
$oversizedCounts = array_count_values($oversizedAllocation['assignments']);
sort($oversizedCounts);
$check('allocator splits oversized cohorts across larger rooms before tiny rooms', $oversizedCounts === [2, 4]
    && !in_array(213, $oversizedAllocation['assignments'], true) && $oversizedAllocation['solo'] === []);
$fivePersonAllocation = seminar_allocate_attendees(array_slice($attendees, 0, 5), [
    ['venue_id' => 216, 'max_capacity' => 4], ['venue_id' => 217, 'max_capacity' => 4], ['venue_id' => 218, 'max_capacity' => 1],
]);
$fivePersonCounts = array_count_values($fivePersonAllocation['assignments']);
sort($fivePersonCounts);
$check('allocator places five attendees across two capacity-4 rooms instead of using a tiny solo room', $fivePersonCounts === [2, 3]
    && !in_array(218, $fivePersonAllocation['assignments'], true) && $fivePersonAllocation['solo'] === []);
$splitRemainderAllocation = seminar_allocate_attendees([
    ['id' => 1, 'gender' => 'female', 'location' => 'Alpha'], ['id' => 2, 'gender' => 'female', 'location' => 'Alpha'], ['id' => 3, 'gender' => 'female', 'location' => 'Alpha'],
    ['id' => 4, 'gender' => 'female', 'location' => 'Beta'], ['id' => 5, 'gender' => 'female', 'location' => 'Beta'], ['id' => 6, 'gender' => 'female', 'location' => 'Beta'],
], [['venue_id' => 219, 'max_capacity' => 4], ['venue_id' => 220, 'max_capacity' => 2], ['venue_id' => 221, 'max_capacity' => 1]]);
$splitRemainderCounts = array_count_values($splitRemainderAllocation['assignments']);
sort($splitRemainderCounts);
$check('allocator sends a split singleton remainder to occupied same-gender space before an empty room', $splitRemainderCounts === [2, 4]
    && $splitRemainderAllocation['assignments'][4] !== 221 && $splitRemainderAllocation['solo'] === []);
$insufficientAllocation = seminar_allocate_attendees($attendees, [
    ['venue_id' => 208, 'max_capacity' => 2], ['venue_id' => 209, 'max_capacity' => 2],
]);
$check('allocator leaves attendees unassigned when total capacity is insufficient', count($insufficientAllocation['assignments']) === 4
    && count($insufficientAllocation['unassigned']) === 2 && $insufficientAllocation['solo'] === []);
$check('allocator is deterministic for the same roster and room order', $allocation === seminar_allocate_attendees($attendees, [
    ['venue_id' => 101, 'max_capacity' => 5], ['venue_id' => 102, 'max_capacity' => 5],
]));
$unknown = seminar_allocate_attendees([
    ['id' => 1, 'gender' => 'unknown', 'location' => 'Sevilla'],
    ['id' => 2, 'gender' => 'female', 'location' => 'Sevilla'],
], [['venue_id' => 9, 'max_capacity' => 1]]);
$check('unknown gender and insufficient capacity remain flagged and unassigned', $unknown['unassigned'] === [1] && isset($unknown['solo'][2]));
$check('same-gender attendees can share a room without exceeding capacity', array_count_values(seminar_allocate_attendees([
    ['id' => 1, 'gender' => 'female', 'location' => 'North'], ['id' => 2, 'gender' => 'female', 'location' => 'North'],
], [['venue_id' => 4, 'max_capacity' => 2]])['assignments']) === [4 => 2]);
$largeRoster = [];
for ($i = 1; $i <= 300; $i++) $largeRoster[] = ['id' => $i, 'gender' => 'female', 'location' => 'Sevilla'];
$largeRooms = [];
for ($i = 1; $i <= 120; $i++) $largeRooms[] = ['venue_id' => 1000 + $i, 'max_capacity' => 5];
$largeAllocation = seminar_allocate_attendees($largeRoster, $largeRooms);
$check('allocator handles 300 attendees across 120 rooms at exact capacity', count($largeAllocation['assignments']) === 300 && $largeAllocation['unassigned'] === [] && $largeAllocation['solo'] === []);
$roomIds = seminar_normalize_room_ids(range(1, 120));
$check('room selection accepts more than 100 distinct room units', count($roomIds) === 120 && $roomIds[0] === 1 && $roomIds[119] === 120);
$check('room option availability returns inventory floor metadata and uses hotel checkout-exclusive overlap', str_contains((string)file_get_contents(__DIR__ . '/../includes/seminars.php'), "m.start_date < ? AND m.end_date > ?")
    && str_contains((string)file_get_contents(__DIR__ . '/../includes/seminars.php'), 'h.room_number,h.room_type,h.floor_label,h.max_capacity'));

$staleSoloRows = [
    ['id' => 1, 'gender' => 'female', 'assigned_venue_id' => 4, 'solo_flag' => 1],
    ['id' => 2, 'gender' => 'female', 'assigned_venue_id' => 4, 'solo_flag' => 1],
];
$check('finalization derives live room counts rather than rejecting stale solo flags', seminar_check_final_assignments($staleSoloRows, [4 => 2], [])['counts'] === [4 => 2]);
$check('finalization allows a live singleton regardless of cached solo flag', seminar_check_final_assignments([
    ['id' => 1, 'gender' => 'female', 'assigned_venue_id' => 4, 'solo_flag' => 1],
], [4 => 2], [])['counts'] === [4 => 1]
    && seminar_check_final_assignments([
        ['id' => 1, 'gender' => 'female', 'assigned_venue_id' => 4, 'solo_flag' => 0],
    ], [4 => 2], [])['counts'] === [4 => 1]);
$check('finalization still requires a nonempty roster, reserved room assignment, and every attendee assigned', $throws(static fn() => seminar_check_final_assignments([], [4 => 2], []))
    && $throws(static fn() => seminar_check_final_assignments([['id' => 1, 'gender' => 'female', 'assigned_venue_id' => 0]], [4 => 2], []))
    && $throws(static fn() => seminar_check_final_assignments([['id' => 1, 'gender' => 'female', 'assigned_venue_id' => 9]], [4 => 2], [])));
$check('finalization rejects capacity excess', $throws(static fn() => seminar_check_final_assignments([
    ['id' => 1, 'gender' => 'female', 'assigned_venue_id' => 4], ['id' => 2, 'gender' => 'female', 'assigned_venue_id' => 4],
    ['id' => 3, 'gender' => 'female', 'assigned_venue_id' => 4],
], [4 => 2], [])));
$mixed = [
    ['id' => 1, 'gender' => 'female', 'assigned_venue_id' => 4], ['id' => 2, 'gender' => 'male', 'assigned_venue_id' => 4],
];
$check('finalization requires deliberate approval for mixed-gender rooms', $throws(static fn() => seminar_check_final_assignments($mixed, [4 => 2], []))
    && seminar_check_final_assignments($mixed, [4 => 2], [4])['counts'] === [4 => 2]);

$future = new DateTimeImmutable('today + 10 days');
$checkIn = $future->format('Y-m-d');
$checkOut = $future->modify('+2 days')->format('Y-m-d');
$check('date validation accepts same-day inclusive hall dates and later hotel checkout', seminar_validate_dates($checkIn, $checkIn, $checkIn, $checkOut)[1] === $checkIn);
$check('date validation rejects checkout on the check-in date', $throws(static fn() => seminar_validate_dates($checkIn, $checkIn, $checkIn, $checkIn)));
$check('CSV import preserves UTF-8 and maps attendee columns', (static function (): bool {
    $path = tempnam(sys_get_temp_dir(), 'seminar-csv-');
    try {
        file_put_contents($path, "Name,Gender,Location,Contact\nÉlodie,female,Sevilla,+34 123\n");
        $sheets = seminar_csv_sheets($path);
        $parsed = seminar_validate_import_rows($sheets[0]['rows'], ['name' => '0', 'gender' => '1', 'location' => '2', 'contact' => '3']);
        return $parsed['errors'] === [] && $parsed['attendees'][0] === ['full_name' => 'Élodie', 'gender' => 'female', 'location' => 'Sevilla', 'contact' => '+34 123'];
    } finally { @unlink($path); }
})());

$check('XLSX parser exposes metadata and mapped rows for a non-first worksheet', (static function (): bool {
    if (!class_exists('ZipArchive') || !class_exists('XMLReader')) return false;
    $path = tempnam(sys_get_temp_dir(), 'seminar-xlsx-');
    $zip = new ZipArchive();
    $zipOpened = false;
    try {
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) return false;
        $zipOpened = true;
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Cover" sheetId="1" r:id="rId1"/><sheet name="Roster" sheetId="2" r:id="rId2"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Target="worksheets/sheet2.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Name</t></si><si><t>Gender</t></si><si><t>Location</t></si><si><t>Contact</t></si><si><t>Amira Santos</t></si><si><t>female</t></si><si><t>Sevilla</t></si><si><t>555-0101</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c></row></sheetData></worksheet>');
        $zip->addFromString('xl/worksheets/sheet2.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c></row><row r="2"><c r="A2" t="s"><v>4</v></c><c r="B2" t="s"><v>5</v></c><c r="C2" t="s"><v>6</v></c><c r="D2" t="s"><v>7</v></c></row></sheetData></worksheet>');
        $zip->close();
        $zipOpened = false;
        $sheets = seminar_xlsx_sheets($path);
        $parsed = seminar_validate_import_rows($sheets[1]['rows'], ['name' => '0', 'gender' => '1', 'location' => '2', 'contact' => '3']);
        return $sheets[1]['name'] === 'Roster' && $parsed['errors'] === [] && $parsed['attendees'][0]['full_name'] === 'Amira Santos';
    } finally { if ($zipOpened) $zip->close(); @unlink($path); }
})());

$seminar = ['name' => 'Seminar <Plan>', 'hall_name' => 'Main Hall', 'hall_start_date' => '2030-06-01', 'hall_end_date' => '2030-06-02', 'hotel_check_in' => '2030-05-31', 'hotel_check_out' => '2030-06-03'];
$rooms = [
    ['venue_id' => 4, 'name' => 'North Wing', 'room_number' => '12', 'floor_label' => 'Second Floor', 'max_capacity' => 16],
    ['venue_id' => 5, 'name' => 'South Wing', 'room_number' => '18', 'floor_label' => 'Floor 3', 'max_capacity' => 3],
    ['venue_id' => 6, 'name' => 'Unused Wing', 'room_number' => '99', 'floor_label' => 'Fourth Floor', 'max_capacity' => 3],
];
$check('floor labels preserve arbitrary text, format numeric and ordinal labels, avoid duplicate prefixes, and omit blanks', seminar_floor_display_label('Floor 2') === 'Floor 2'
    && seminar_floor_display_label('Second Floor') === 'Second Floor'
    && seminar_floor_display_label('Mezzanine') === 'Mezzanine'
    && seminar_floor_display_label('2') === 'Floor 2'
    && seminar_floor_display_label('2nd') === 'Floor 2nd'
    && seminar_floor_display_label('') === ''
    && str_contains((string)file_get_contents(__DIR__ . '/../assets/js/admin-page/admin_seminars.js'), 'if (/\\bfloor\\b/i.test(label)) return label;')
    && str_contains((string)file_get_contents(__DIR__ . '/../assets/js/admin-page/admin_seminars.js'), 'return /^\\d+(?:st|nd|rd|th)?$/i.test(label) ? `Floor ${label}` : label;'));
$pdfAttendees = [['full_name' => 'Amira Santos', 'location' => 'Sevilla', 'contact' => '555-0101', 'assigned_venue_id' => 4]];
for ($i = 2; $i <= 16; $i++) $pdfAttendees[] = ['full_name' => 'Attendee ' . $i, 'location' => $i % 2 ? 'Sevilla' : 'Dos Hermanas', 'contact' => '555-01' . str_pad((string)$i, 2, '0', STR_PAD_LEFT), 'assigned_venue_id' => 4];
$pdfAttendees[] = ['full_name' => 'Diego Santos', 'location' => 'Dos Hermanas', 'contact' => '555-0202', 'assigned_venue_id' => 5];
$pdfAttendees[] = ['full_name' => 'Lucia Santos', 'location' => 'Sevilla', 'contact' => '555-0203', 'assigned_venue_id' => 5];
$check('registration and alphabetical PDFs fit 16+2, large rooms, and a 67-room batch on A4 pages', (static function () use ($seminar, $rooms, $pdfAttendees): bool {
    if (!is_file(__DIR__ . '/../vendor/autoload.php')) return false;
    require_once __DIR__ . '/../vendor/autoload.php';
    $pdfOptions = [
        'isRemoteEnabled' => false,
        'isPhpEnabled' => false,
        'chroot' => [realpath(__DIR__ . '/../assets'), realpath(__DIR__ . '/../vendor/dompdf/dompdf')],
    ];
    $roomPageCount = 0;
    foreach (['rooms', 'list'] as $type) {
        $dompdf = new Dompdf\Dompdf($pdfOptions);
        $dompdf->loadHtml(seminar_render_pdf_html($seminar, $rooms, $pdfAttendees, $type), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait'); $dompdf->render();
        $output = $dompdf->output();
        if (!str_starts_with($output, '%PDF-') || strlen($output) < 1000) return false;
        if ($type === 'rooms') $roomPageCount = $dompdf->getCanvas()->get_page_count();
    }
    $largeRoomAttendees = [];
    for ($i = 1; $i <= 60; $i++) $largeRoomAttendees[] = ['full_name' => 'Attendee ' . $i, 'location' => 'Sevilla', 'assigned_venue_id' => 7];
    $largePdf = new Dompdf\Dompdf($pdfOptions);
    $largePdf->loadHtml(seminar_render_pdf_html($seminar, [['venue_id' => 7, 'name' => 'Large Wing', 'room_number' => '60', 'floor_label' => 'Floor 6', 'max_capacity' => 60]], $largeRoomAttendees, 'rooms'), 'UTF-8');
    $largePdf->setPaper('A4', 'portrait'); $largePdf->render();
    $largeRoomPageCount = $largePdf->getCanvas()->get_page_count();
    $singleRoom = seminar_occupied_pdf_room($rooms, $pdfAttendees, 4);
    $singleAttendees = array_values(array_filter($pdfAttendees, static fn($person) => (int)$person['assigned_venue_id'] === 4));
    $singlePdf = new Dompdf\Dompdf($pdfOptions);
    $singlePdf->loadHtml(seminar_render_pdf_html($seminar, [$singleRoom], $singleAttendees, 'rooms'), 'UTF-8');
    $singlePdf->setPaper('A4', 'portrait'); $singlePdf->render();
    $html = seminar_render_pdf_html($seminar, $rooms, $pdfAttendees, 'rooms');
    $withoutFloor = seminar_render_pdf_html($seminar, [['venue_id' => 9, 'name' => 'Plain Wing', 'room_number' => '9', 'floor_label' => '', 'max_capacity' => 3]], [['full_name' => 'Rosa Plain', 'location' => 'Sevilla', 'assigned_venue_id' => 9]], 'rooms');
    $singlePageCount = $singlePdf->getCanvas()->get_page_count();
    unset($dompdf, $largePdf, $singlePdf);
    gc_collect_cycles();
    $batchRooms = $batchAttendees = [];
    for ($room = 0; $room < 67; $room++) $batchRooms[] = ['venue_id' => 1000 + $room, 'name' => 'Batch Wing', 'room_number' => (string)($room + 1), 'floor_label' => '', 'max_capacity' => 5];
    for ($i = 0; $i < 300; $i++) $batchAttendees[] = ['full_name' => 'Batch Attendee ' . ($i + 1), 'location' => 'Sevilla', 'assigned_venue_id' => 1000 + ($i % 67)];
    $batchHtml = seminar_render_pdf_html($seminar, $batchRooms, $batchAttendees, 'rooms');
    $batchPdf = new Dompdf\Dompdf($pdfOptions);
    $batchPdf->loadHtml($batchHtml, 'UTF-8'); unset($batchHtml);
    $batchPdf->setPaper('A4', 'portrait'); $batchPdf->render();
    $batchOutput = $batchPdf->output();
    return $roomPageCount === 2 && $largeRoomPageCount === 1 && $singlePageCount === 1
        && str_starts_with($batchOutput, '%PDF-') && $batchPdf->getCanvas()->get_page_count() === 67
        && seminar_occupied_pdf_room($rooms, $pdfAttendees, 6) === null && seminar_occupied_pdf_room($rooms, $pdfAttendees, 99) === null
        && str_contains($html, 'Second Floor') && substr_count($html, 'Floor 3') === 1 && str_contains($html, 'Amira Santos') && str_contains($html, 'Attendee 16') && str_contains($html, 'Room 12')
        && !str_contains($html, '555-0101') && !str_contains($html, '555-0202')
        && str_contains($html, 'Contact No.') && str_contains($html, 'Signature') && str_contains($html, 'Main Hall')
        && !str_contains($withoutFloor, 'Floor') && str_contains($withoutFloor, 'Capacity 3')
        && !str_contains($html, 'Seminar <Plan>') && !str_contains($html, 'Room 99')
        && str_contains($html, 'file://' . realpath(__DIR__ . '/../assets/img/Logo.png'))
        && !str_contains($html, 'data:image/png;base64,');
})());

$root = dirname(__DIR__);
$source = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$adminEndpoint = $source('actions/admin/seminars.php');
$pdfEndpoint = $source('actions/admin/seminar_pdf.php');
$adminJs = $source('assets/js/admin-page/admin_seminars.js');
$seminarPage = $source('includes/admin-page/admin_seminars.php');
$seminarCss = $source('assets/css/admin-page/admin_seminars.css');
$dashboard = $source('admin_dashboard.php');
$statusEndpoint = $source('actions/admin/update_booking_status.php');
$onlineSubmit = $source('actions/bookings/submit_online.php');
$inventoryGuardStart = strpos($adminEndpoint, 'function seminar_assert_inventory');
$inventoryGuardEnd = strpos($adminEndpoint, 'function seminar_lock_resources', $inventoryGuardStart === false ? 0 : $inventoryGuardStart);
$finalizeGuardStart = strpos($adminEndpoint, 'function seminar_finalize');
$finalizeGuardEnd = strpos($adminEndpoint, "\n}\n", $finalizeGuardStart === false ? 0 : $finalizeGuardStart);
$inventoryGuard = $inventoryGuardStart !== false && $inventoryGuardEnd !== false ? substr($adminEndpoint, $inventoryGuardStart, $inventoryGuardEnd - $inventoryGuardStart) : '';
$finalizeGuard = $finalizeGuardStart !== false && $finalizeGuardEnd !== false ? substr($adminEndpoint, $finalizeGuardStart, $finalizeGuardEnd - $finalizeGuardStart) : '';
$availabilityFiles = [
    'actions/bookings/fetch_dates.php', 'actions/bookings/get_room_availability.php', 'actions/bookings/lock_dates.php',
    'actions/bookings/lock_addon_rooms.php', 'actions/bookings/submit_online.php', 'actions/bookings/submit_walkin.php',
    'actions/admin/schedule_maintenance.php', 'actions/admin/update_booking_status.php',
];
$allIntegrated = true;
foreach ($availabilityFiles as $file) {
    $flow = $source($file);
    $allIntegrated = $allIntegrated && str_contains($flow, 'seminar_')
        && (str_contains($flow, 'seminar_reservations') || str_contains($flow, 'seminar_has_resource_conflict') || str_contains($flow, 'seminar_has_maintenance_conflict'));
}
$check('public and staff booking, maintenance, and confirmation flows account for seminar holds', $allIntegrated);
$check('selected worksheet refreshes its own headers, samples, and suggested mapping', str_contains($adminJs, 'saveVisibleMapping(); renderImportMetadata(Number(event.target.value))')
    && str_contains($adminEndpoint, "'headers' => \$headers")
    && str_contains($adminEndpoint, "'mapping' => seminar_suggest_mapping(\$headers)")
    && str_contains($adminEndpoint, "foreach (\$sheets as \$sheet)"));
$check('new seminars require validated roster before atomic create-from-import', str_contains($adminJs, 'preview_validate')
    && str_contains($adminJs, "request('create_from_import'")
    && str_contains($adminEndpoint, "if (\$op === 'create_from_import')")
    && str_contains($adminEndpoint, 'seminar_mutate_reservation($conn, $data, null, $validated'));
$check('picker shows disabled unavailable rooms with reasons and selected summary excludes them', str_contains($adminJs, 'unavailable_reason')
    && str_contains($adminJs, "'disabled'") && str_contains($adminJs, 'Number(room.available) === 1'));
$check('room bulk selection requires a nonempty search and selects only available visible matches', str_contains($adminJs, 'searchButton.disabled = !query || !matches.length;')
    && str_contains($adminJs, 'searchButton.textContent = !query ? \'Search to select matches\'')
    && str_contains($adminJs, 'const query = workspace.querySelector(\'#seminar-room-search\')?.value.trim();')
    && str_contains($adminJs, 'if (!query) { flash(\'Search for a hotel, floor, or room before selecting matching rooms.\', \'info\'); return; }')
    && str_contains($adminJs, "[data-room-option]:not([hidden]) [data-room-check]:not(:disabled)")
    && str_contains($adminJs, "button.textContent = !count ? 'No available rooms' : query ? `Select \${count} match")
    && str_contains($adminJs, '[data-room-search-status]')
    && !str_contains($adminJs, "if (event.target.closest('[data-select-building]'))")
    && !str_contains($adminJs, 'Select visible rooms')
    && str_contains($seminarCss, '.seminar-room-option[hidden], .seminar-building[hidden] { display: none; }'));
$check('room picker and assignment board use legible room and attendee hierarchy without nested positive pills', str_contains($seminarCss, '.seminar-room-option__copy strong { color: #493623; font-size: 14px;')
    && str_contains($seminarCss, '.seminar-room-capacity { color: #604627; font-size: 12px;')
    && str_contains($seminarCss, '.seminar-attendee__info > strong { color: #352d27; font-size: 14px;')
    && str_contains($seminarCss, '.seminar-attendee__info > span { color: #675c51; font-size: 12px;')
    && str_contains($seminarCss, '.seminar-room-filter { align-items: stretch; grid-template-columns: 1fr; }')
    && str_contains($seminarCss, '.seminar-room-options { grid-template-columns: 1fr; }')
    && str_contains($adminJs, 'role="status"') && !str_contains($adminJs, 'Assignment looks good'));
$check('numbered progress labels all four seminar wizard stages', str_contains($adminJs, "['Import attendees', 'Seminar details', 'Select rooms', 'Review and print']")
    && str_contains($seminarPage, 'seminar-wizard-progress'));
$startReservationStart = strpos($adminJs, 'async function startReservation()');
$startReservationEnd = strpos($adminJs, 'function beginRosterReplacement', $startReservationStart === false ? 0 : $startReservationStart);
$startReservationBody = $startReservationStart !== false && $startReservationEnd !== false ? substr($adminJs, $startReservationStart, $startReservationEnd - $startReservationStart) : '';
$check('new seminar flow separates details and room screens and defers holds until room submit', str_contains($adminJs, 'function renderDetailsScreen()')
    && str_contains($adminJs, 'function renderRoomSelectionScreen()') && str_contains($startReservationBody, 'renderDetailsScreen()')
    && !str_contains($startReservationBody, "request('create_from_import'")
    && str_contains($adminJs, "document.getElementById('seminar-create')?.addEventListener('click', saveReservation)")
    && str_contains($adminJs, 'data-wizard-back="2"'));
$check('wizard keeps roster mappings and seminar details while navigating back and forward', str_contains($adminJs, 'state.mappings[sheetIndex] || sheet.mapping')
    && str_contains($adminJs, 'state.reservationDraft = { ...state.reservationDraft, name:')
    && str_contains($adminJs, 'state.wizardMode === \'new\' && state.reservationDraft?.hall_start && state.reservationDraft?.hotel_check_out')
    && !str_contains($startReservationBody, 'state.selectedRooms.clear(); state.floors = {};')
    && str_contains($adminJs, "else if (destination === 1) importScreen()")
    && str_contains($adminJs, 'else if (destination === 2) renderDetailsScreen()'));
$importScreenStart = strpos($adminJs, 'function importScreen(');
$importScreenEnd = strpos($adminJs, 'function renderImportMetadata', $importScreenStart === false ? 0 : $importScreenStart);
$importScreenBody = $importScreenStart !== false && $importScreenEnd !== false ? substr($adminJs, $importScreenStart, $importScreenEnd - $importScreenStart) : '';
$planRenderStart = strpos($adminJs, 'function renderPlan(');
$planRenderEnd = strpos($adminJs, 'function bindPlanEvents', $planRenderStart === false ? 0 : $planRenderStart);
$planRenderBody = $planRenderStart !== false && $planRenderEnd !== false ? substr($adminJs, $planRenderStart, $planRenderEnd - $planRenderStart) : '';
$primaryActionsStart = strpos($planRenderBody, 'const primaryActions =');
$secondaryActionsStart = strpos($planRenderBody, 'const secondaryActions =');
$planActionsStart = strpos($planRenderBody, 'const planActions =');
$planActionsEnd = strpos($planRenderBody, 'const hall =', $planActionsStart === false ? 0 : $planActionsStart);
$primaryActionsBody = $primaryActionsStart !== false && $secondaryActionsStart !== false ? substr($planRenderBody, $primaryActionsStart, $secondaryActionsStart - $primaryActionsStart) : '';
$secondaryActionsBody = $secondaryActionsStart !== false && $planActionsStart !== false ? substr($planRenderBody, $secondaryActionsStart, $planActionsStart - $secondaryActionsStart) : '';
$planActionsBody = $planActionsStart !== false && $planActionsEnd !== false ? substr($planRenderBody, $planActionsStart, $planActionsEnd - $planActionsStart) : '';
$check('Step 1 offers one roster picker/action without a template link and keeps Back to plan only for roster replacement', !str_contains($seminarPage, 'seminar_template.php')
    && !str_contains($importScreenBody, 'seminar_template.php')
    && str_contains($seminarPage, 'type="file" name="file"')
    && !str_contains($seminarPage, 'Upload and preview')
    && str_contains($importScreenBody, 'type="file" name="file"')
    && !str_contains($importScreenBody, 'Upload and preview')
    && str_contains($seminarPage, 'data-upload-status')
    && !str_contains($seminarPage, 'id="seminar-new"') && !str_contains($seminarPage, 'Back to seminars')
    && !str_contains($importScreenBody, 'Back to seminars') && str_contains($importScreenBody, 'state.replaceTarget ?'));
$uploadHelperStart = strpos($adminJs, 'function uploadSelectedRoster(');
$uploadHelperEnd = strpos($adminJs, 'async function replaceDraftRoster', $uploadHelperStart === false ? 0 : $uploadHelperStart);
$uploadHelperBody = $uploadHelperStart !== false && $uploadHelperEnd !== false ? substr($adminJs, $uploadHelperStart, $uploadHelperEnd - $uploadHelperStart) : '';
$check('roster selection uploads automatically once, supports retry, and preserves the active preview on failure', str_contains($importScreenBody, 'if (event.target.files?.length) uploadSelectedRoster(event.target.form)')
    && str_contains($adminJs, 'event.preventDefault(); await uploadSelectedRoster(event.target)')
    && str_contains($uploadHelperBody, 'if (state.rosterUploadPromise) return state.rosterUploadPromise')
    && str_contains($uploadHelperBody, "actionLabel: 'Retry upload'")
    && str_contains($uploadHelperBody, 'state.import = { ...result, filename: selectedFile.name }')
    && strpos($uploadHelperBody, 'if (!response.ok || !result.success)') < strpos($uploadHelperBody, 'state.import = { ...result, filename: selectedFile.name }')
    && str_contains($uploadHelperBody, 'Upload failed. Retry this file or choose another roster.')
    && !str_contains($adminJs, 'Replace the roster preview?'));
$check('roster uploads prevent navigation until completion', str_contains($uploadHelperBody, "const backButton = workspace.querySelector('[data-back-list]')")
    && str_contains($uploadHelperBody, 'if (backButton) backButton.disabled = true;')
    && str_contains($uploadHelperBody, 'if (backButton?.isConnected) backButton.disabled = false;')
    && str_contains($adminJs, "if (state.rosterUploadPromise) {\n      flash('Wait for the roster upload to finish before opening another plan.', 'info');"));
$check('pending roster upload blocks continue and validation cannot re-enable its button', str_contains($adminJs, 'continueButton.disabled = !!state.rosterUploadPromise || !result.count || !!result.errors?.length;')
    && str_contains($adminJs, "if (state.rosterUploadPromise) {\n        flash('Wait for the roster upload to finish before continuing.', 'info');\n        return;\n      }\n      if (state.replaceTarget) { await replaceDraftRoster(); return; }"));
$check('New seminar is available only while viewing a saved plan and returns to Step 1 after confirmation', str_contains($planRenderBody, 'id="seminar-new">New seminar')
    && !str_contains($importScreenBody, 'seminar-new') && !str_contains($seminarPage, 'seminar-new')
    && str_contains($adminJs, "if (event.target.closest('#seminar-new'))")
    && str_contains($adminJs, 'function beginNewSeminar()')
    && str_contains($adminJs, "title: 'Start a new seminar?'")
    && str_contains($adminJs, 'requestConfirmation({')
    && str_contains($adminJs, 'startNewWizard();')
    && !str_contains($adminJs, 'newFileInput?.click();'));
$check('seminar confirmations use an accessible in-app dialog with safe cancel defaults', str_contains($seminarPage, '<dialog class="seminar-confirm-dialog"')
    && str_contains($seminarPage, 'aria-modal="true"')
    && str_contains($seminarPage, 'aria-labelledby="seminar-confirm-title" aria-describedby="seminar-confirm-message"')
    && str_contains($adminJs, 'confirmationCancel.focus()')
    && str_contains($adminJs, "confirmationDialog.returnValue = ''")
    && str_contains($adminJs, "event.key === 'Escape'")
    && str_contains($adminJs, "confirmationDialog.close(accepted ? 'confirm' : 'cancel')")
    && !preg_match('/(?:window\.)?(?:alert|confirm|prompt)\s*\(/i', $adminJs));
$check('destructive and reset seminar actions explain their effect before confirmation', str_contains($adminJs, "title: 'Cancel this seminar?'")
    && str_contains($adminJs, "title: 'Remove this attendee?'")
    && str_contains($adminJs, "title: 'Regenerate room assignments?'")
    && str_contains($adminJs, "title: 'Replace the draft roster?'")
    && str_contains($adminJs, 'clears mixed-gender approvals')
    && str_contains($adminJs, 'confirmLabel: \'Cancel seminar\', destructive: true'));
$check('draft review keeps save and finalize prominent and groups secondary actions accessibly', str_contains($primaryActionsBody, 'id="seminar-save-plan"')
    && str_contains($primaryActionsBody, 'id="seminar-finalize"')
    && !str_contains($primaryActionsBody, 'seminar-regenerate')
    && !str_contains($primaryActionsBody, 'seminar-cancel')
    && str_contains($secondaryActionsBody, 'seminar-regenerate')
    && str_contains($secondaryActionsBody, 'seminar-edit-reservation')
    && str_contains($secondaryActionsBody, 'seminar-replace-roster')
    && str_contains($secondaryActionsBody, 'seminar-new')
    && str_contains($secondaryActionsBody, 'seminar-cancel')
    && str_contains($planRenderBody, 'const moreActionsMarkup = secondaryActions.length ? `<details class="seminar-more-actions">')
    && str_contains($planRenderBody, '<span>More actions</span>'));
$check('finalized and cancelled review states expose only valid primary actions', str_contains($primaryActionsBody, "plan.status === 'finalized'")
    && str_contains($primaryActionsBody, 'id="seminar-reopen">Reopen plan')
    && str_contains($primaryActionsBody, "plan.status === 'cancelled'")
    && str_contains($primaryActionsBody, 'id="seminar-new">New seminar')
    && str_contains($secondaryActionsBody, "plan.status !== 'cancelled'")
    && str_contains($secondaryActionsBody, 'Print room sheets') === false
    && str_contains($secondaryActionsBody, 'data-pdf-type="list"'));
$check('rooming review compacts a fully assigned roster and keeps attendee entry collapsed', str_contains($planRenderBody, 'const unassignedMarkup = !isDraft ?')
    && str_contains($planRenderBody, 'Every attendee has a room assignment.')
    && str_contains($planRenderBody, '<details class="seminar-add-attendee-details">')
    && str_contains($planRenderBody, 'id="seminar-add-form"')
    && str_contains($seminarCss, '.seminar-unassigned-empty {')
    && str_contains($seminarCss, '.seminar-add-attendee-details > summary'));
$check('mobile review filters do not inherit tall flex basis and action menus reflow', str_contains($seminarCss, '.seminar-board-tools .seminar-field, .seminar-room-filter .seminar-field { flex: 0 1 auto; min-height: 0; }')
    && str_contains($seminarCss, '.seminar-primary-actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); width: 100%; }')
    && str_contains($seminarCss, '.seminar-more-actions__menu { box-shadow: none; margin-top: 7px; position: static; width: 100%; }'));
$check('seminar feedback persists outside rerendered screens and has dismiss/retry controls', str_contains($seminarPage, 'id="seminar-feedback" role="status" aria-live="polite"')
    && strpos($seminarPage, 'id="seminar-feedback"') < strpos($seminarPage, 'id="seminar-workspace"')
    && str_contains($adminJs, 'feedbackMessage.textContent = String(message')
    && str_contains($adminJs, 'data-feedback-dismiss')
    && str_contains($adminJs, "actionLabel: 'Retry'"));
$check('seminar actions expose in-progress feedback and prevent duplicate submissions', str_contains($adminJs, 'function markButtonBusy(button, label)')
    && str_contains($adminJs, 'button.setAttribute(\'aria-busy\', \'true\')')
    && str_contains($adminJs, 'Checking roster rows and mapping…')
    && str_contains($adminJs, 'Creating seminar and holds…')
    && str_contains($adminJs, 'Checking Event Hall availability…')
    && str_contains($seminarCss, '.seminar-button.is-loading::before')
    && str_contains($seminarCss, '.seminar-confirm-dialog__actions .seminar-button { width: 100%; }'));
$workspacePosition = strpos($seminarPage, 'id="seminar-workspace"');
$savedPlansPosition = strpos($seminarPage, 'id="seminar-saved-plans"');
$check('saved plans follow the full-width wizard and stay hidden unless plans exist', $workspacePosition !== false && $savedPlansPosition > $workspacePosition
    && str_contains($seminarPage, 'id="seminar-saved-plans" aria-labelledby="seminar-saved-plans-title" hidden')
    && str_contains($adminJs, 'savedPlans.hidden = state.plans.length === 0')
    && str_contains($adminJs, 'list.innerHTML = state.plans.map')
    && !str_contains($adminJs, 'No seminar plans yet')
    && str_contains($seminarCss, '.seminar-saved-plans[hidden] { display: none; }'));
$check('column mapping and preview appear only after a successful roster upload', !str_contains($seminarPage, 'id="seminar-mapping-panel"')
    && !str_contains($seminarPage, 'id="seminar-mapping-content"')
    && str_contains($importScreenBody, 'const mappingPanel = state.import ?')
    && str_contains($adminJs, 'state.import = { ...result, filename: selectedFile.name }')
    && str_contains($importScreenBody, 'esc(state.import.filename')
    && str_contains($importScreenBody, 'Current roster preview remains active until a replacement uploads successfully.')
    && str_contains($seminarPage, 'Select the client roster to preview it')
    && str_contains($adminJs, "selected.textContent = event.target.files?.[0]?.name || 'Select the client roster to preview it'")
    && str_contains($adminJs, 'Roster ready:') && str_contains($adminJs, 'Continue to seminar details.'));
$check('dashboard global section padding is reset inside the seminar scope after shared styles load', strpos($dashboard, 'assets/css/style.css') < strpos($dashboard, 'assets/css/admin-page/admin_overview.css')
    && strpos($dashboard, 'assets/css/admin-page/admin_overview.css') < strpos($dashboard, 'assets/css/admin-page/admin_seminars.css')
    && str_contains($seminarCss, ':where(.seminars-page) section { padding: 0; }'));
$check('upload uses a styled accessible file chooser and mapping stays compact but editable', str_contains($seminarPage, 'seminar-file-input')
    && str_contains($seminarPage, 'Choose roster file') && str_contains($adminJs, 'seminar-file-picker__button')
    && str_contains($seminarCss, '.seminar-file-input:focus-visible + .seminar-file-picker__button')
    && str_contains($adminJs, '<details class="seminar-mapping-editor"')
    && str_contains($adminJs, 'Change worksheet or columns')
    && str_contains($adminJs, 'function renderMappingSummary(')
    && str_contains($adminJs, "state.mappingEditorOpen = state.mappingEditorOpen || !requiredMapped;")
    && str_contains($adminJs, "'Name, Gender, Location, and Contact'")
    && str_contains($adminJs, 'seminar-roster-summary'));
$check('roster review visibly groups attendee count, gender, location counts, and row validation', str_contains($adminJs, 'seminar-roster-summary__count')
    && str_contains($adminJs, 'seminar-roster-summary__gender')
    && str_contains($adminJs, 'seminar-roster-locations__head')
    && str_contains($adminJs, 'locations.slice(0, 6)')
    && str_contains($adminJs, 'Show ${moreLocations.length} more locations')
    && str_contains($adminJs, 'seminar-roster-validation')
    && str_contains($seminarCss, '.seminar-roster-summary__count strong { color: #493623; font-size: 25px;')
    && str_contains($seminarCss, '.seminar-roster-locations__list li { align-items: baseline; border-bottom: 1px solid #e7ddce;')
    && str_contains($seminarCss, 'font-size: 12px; gap: 8px; justify-content: space-between;')
    && str_contains($seminarCss, '.seminar-roster-validation { background: #f2f8f0; border: 1px solid #c4d8bb;'));
$check('wizard progress is segmented with legible stage labels and reflows to two columns on phones', str_contains($seminarCss, '.seminar-wizard-progress { display: grid; gap: 8px; grid-template-columns: repeat(4, minmax(0, 1fr));')
    && str_contains($seminarCss, '.seminar-wizard-progress__step.is-current { background: #f8eddc;')
    && str_contains($seminarCss, '.seminar-wizard-progress__step.is-complete { background: #f2ece2;')
    && !str_contains($seminarCss, '.seminar-wizard-progress__step:not(:last-child)::after')
    && str_contains($seminarCss, 'grid-template-columns: repeat(2, minmax(0, 1fr)); margin-bottom: 18px;'));
$check('room picker and review hide floor editing and omit empty floor placeholders in PDFs while updates preserve legacy labels', !str_contains($adminJs, 'data-floor=')
    && !str_contains($adminJs, 'data-plan-floor=') && !str_contains($seminarCss, '.seminar-floor-mini')
    && str_contains($adminEndpoint, "SELECT venue_id,floor_label FROM seminar_reservations WHERE seminar_id=? AND resource_kind='room' FOR UPDATE")
    && str_contains($adminEndpoint, '$retainedFloors[(int)$floorRow[\'venue_id\']]')
    && !str_contains($adminJs, 'room_ids: [...state.selectedRooms], floors:')
    && str_contains((string)file_get_contents(__DIR__ . '/../includes/seminars.php'), 'seminar_floor_display_label($room[\'floor_label\'] ?? \'\')')
    && str_contains($adminJs, 'Floor not recorded') && str_contains($adminJs, 'data-room-search="${esc(roomSearch)}"')
    && str_contains($adminJs, 'seminar-room-board-search') && str_contains($seminarCss, '.seminar-floor-missing')
    && str_contains($adminEndpoint, "COALESCE(NULLIF(TRIM(h.floor_label), ''), sr.floor_label) AS floor_label")
    && str_contains($pdfEndpoint, "COALESCE(NULLIF(TRIM(h.floor_label), ''), sr.floor_label) AS floor_label"));
$check('missing floors are visible in the picker and review without blocking room holds or finalization', str_contains($adminJs, 'warnings.push(\'Floor not recorded\')')
    && str_contains($inventoryGuard, 'function seminar_assert_inventory') && !str_contains($inventoryGuard, 'floor_label')
    && str_contains($finalizeGuard, 'function seminar_finalize') && !str_contains($finalizeGuard, 'floor_label'));
$check('seminar attendee copy uses correct singular and plural grammar and solo status is advisory', str_contains($adminJs, "attendees === 1 ? '' : 's'")
    && str_contains($adminJs, 'attendee${attendees === 1 ? \'\' : \'s\'} ·')
    && str_contains($adminJs, 'Choose available hotel rooms for these dates.')
    && str_contains($adminJs, 'id="seminar-auto-select-rooms"')
    && str_contains($adminJs, 'Suggest rooms by building')
    && str_contains($adminJs, 'Keeps selected rooms; clear selection to start over by building. Assignments happen on continue.')
    && str_contains($adminJs, "result.reason === 'total_capacity'")
    && str_contains($adminJs, "result.reason === 'gender_separation'")
    && !str_contains($adminJs, '100 or more rooms')
    && str_contains($adminJs, "warnings.push('Solo occupancy allowed')")
    && !str_contains($source('includes/seminars.php'), 'Resolve every solo room before finalizing.'));
$check('wizard has a distinct content title and no redundant step kickers', str_contains($seminarPage, '<h1>Seminar rooming</h1>')
    && !str_contains($seminarPage, 'seminar-kicker')
    && !str_contains($adminJs, 'Step 1 of 4 ·') && !str_contains($adminJs, 'Step 2 of 4 ·')
    && !str_contains($adminJs, 'Step 3 of 4 ·') && !str_contains($adminJs, 'Step 4 of 4 ·'));
$emptyStart = strpos($adminJs, 'function emptyScreen()');
$emptyEnd = strpos($adminJs, 'function mappingForm', $emptyStart === false ? 0 : $emptyStart);
$emptyWorkspace = $emptyStart !== false && $emptyEnd !== false ? substr($adminJs, $emptyStart, $emptyEnd - $emptyStart) : '';
$check('no-plan workspace opens the uploader immediately without a tall empty card', str_contains($emptyWorkspace, 'importScreen();')
    && !str_contains($emptyWorkspace, 'seminar-empty') && str_contains($seminarPage, 'id="seminar-upload-form"')
    && str_contains($seminarPage, 'id="seminar-list"') && !str_contains($seminarCss, 'min-height: 540px'));
$check('manual bulk moves keep their unsaved assignments while the room board rerenders', str_contains($adminJs, 'state.selectedAttendees.clear(); renderPlan(false)')
    && str_contains($adminJs, 'function renderPlan(resetDraft = true)'));
$check('finalized room cards open secure occupied-room-only PDF previews', str_contains($adminJs, "url.searchParams.set('type', preview.type)")
    && str_contains($adminJs, "url.searchParams.set('room_id', preview.roomId)")
    && str_contains($adminJs, 'data-pdf-type="room" data-pdf-room-id="${roomId}"')
    && str_contains($adminJs, 'data-pdf-label="${esc(roomLabel)}">Print this room</button>')
    && str_contains($adminJs, 'disabled aria-describedby="seminar-print-locked-${roomId}">Print this room</button>')
    && str_contains($adminJs, 'Available after finalizing the plan.')
    && str_contains($pdfEndpoint, "in_array(\$type, ['rooms', 'room', 'list']")
    && str_contains($pdfEndpoint, 'seminar_occupied_pdf_room')
    && str_contains($pdfEndpoint, "\$seminar['status'] !== 'finalized'")
    && str_contains($pdfEndpoint, "\$dompdf->stream(\$filename, ['Attachment' => false])"));
$check('PDF logos use a local asset under a restricted Dompdf chroot instead of repeated base64 data', str_contains($pdfEndpoint, "'chroot' => [\$assetRoot, \$dompdfRoot]")
    && str_contains($pdfEndpoint, "\$assetRoot = realpath(__DIR__ . '/../../assets')")
    && str_contains($pdfEndpoint, "\$dompdfRoot = realpath(__DIR__ . '/../../vendor/dompdf/dompdf')")
    && str_contains($source('includes/seminars.php'), "'file://' . \$logoPath")
    && !str_contains($source('includes/seminars.php'), 'data:image/png;base64,'));
$check('seminar room sheet actions open the accessible in-page PDF preview', str_contains($seminarPage, '<dialog class="seminar-pdf-dialog"')
    && str_contains($seminarPage, 'aria-labelledby="seminar-pdf-title" aria-describedby="seminar-pdf-context"')
    && str_contains($seminarPage, 'data-pdf-loading role="status"')
    && str_contains($seminarPage, 'data-pdf-retry')
    && str_contains($seminarPage, 'data-pdf-close') && str_contains($seminarPage, 'data-pdf-print')
    && str_contains($seminarPage, 'data-pdf-download')
    && str_contains($seminarPage, '<iframe class="seminar-pdf-preview__frame" data-pdf-frame title="Seminar PDF preview"')
    && str_contains($adminJs, 'data-pdf-type="rooms" data-pdf-seminar="${esc(plan.name)}" data-pdf-label="All room sheets">Print room sheets</button>')
    && str_contains($adminJs, 'data-pdf-type="list" data-pdf-seminar="${esc(plan.name)}" data-pdf-label="Name-to-room list">Name-to-room list</button>')
    && str_contains($adminJs, 'data-pdf-type="room" data-pdf-room-id="${roomId}"'));
$viewerFallbackStart = strpos($adminJs, 'function showPdfViewerFallback(preview)');
$viewerFallbackEnd = strpos($adminJs, 'function markPdfViewerReady(preview)', $viewerFallbackStart === false ? 0 : $viewerFallbackStart);
$viewerFallbackBody = $viewerFallbackStart !== false && $viewerFallbackEnd !== false ? substr($adminJs, $viewerFallbackStart, $viewerFallbackEnd - $viewerFallbackStart) : '';
$viewerReadyStart = strpos($adminJs, 'function markPdfViewerReady(preview)');
$viewerReadyEnd = strpos($adminJs, 'async function loadPdfPreview(preview)', $viewerReadyStart === false ? 0 : $viewerReadyStart);
$viewerReadyBody = $viewerReadyStart !== false && $viewerReadyEnd !== false ? substr($adminJs, $viewerReadyStart, $viewerReadyEnd - $viewerReadyStart) : '';
$check('embedded PDF viewer has a bounded readiness timeout and usable unsupported-browser fallback', str_contains($adminJs, 'const pdfViewerReadyTimeout = 4000;')
    && str_contains($adminJs, 'window.setTimeout(() => showPdfViewerFallback(preview), pdfViewerReadyTimeout)')
    && str_contains($adminJs, 'if (frameLocation === \'about:blank\') return;')
    && str_contains($viewerFallbackBody, 'pdfPreviewFallback.hidden = false;')
    && str_contains($viewerFallbackBody, 'pdfPreviewPrint.disabled = true;')
    && str_contains($viewerFallbackBody, 'This browser cannot print the embedded preview.')
    && !str_contains($viewerFallbackBody, 'pdfPreviewDownload.hidden')
    && str_contains($viewerReadyBody, 'pdfPreviewPrint.disabled = false;')
    && str_contains($seminarPage, 'Preview unavailable in this browser')
    && str_contains($seminarPage, 'Download it below to open and print it')
    && str_contains($seminarCss, '.seminar-pdf-preview__fallback {'));
$check('PDF preview uses same-origin authenticated fetch and rejects HTTP or non-PDF responses', str_contains($adminJs, "credentials: 'same-origin'")
    && str_contains($adminJs, "url.origin !== window.location.origin")
    && str_contains($adminJs, 'if (!response.ok) throw new Error(pdfHttpError(response.status));')
    && str_contains($adminJs, "contentType !== 'application/pdf'")
    && str_contains($adminJs, 'new AbortController()'));
$check('PDF preview supports download and printing, releases object URLs, and restores focus on close', str_contains($adminJs, 'URL.createObjectURL(blob)')
    && str_contains($adminJs, "new URL('actions/admin/seminar_pdf.php', document.baseURI)")
    && str_contains($adminJs, 'pdfPreviewDownload.href = objectUrl;')
    && str_contains($adminJs, 'pdfPreviewDownload.download = `seminar-${preview.seminarId}-${preview.type}${roomSuffix}.pdf`;')
    && str_contains($adminJs, 'pdfPreviewDialog.showModal()')
    && str_contains($adminJs, 'pdfPreviewDialog.addEventListener(\'close\'')
    && str_contains($adminJs, 'preview.controller?.abort()')
    && str_contains($adminJs, 'URL.revokeObjectURL(preview.objectUrl)')
    && str_contains($adminJs, 'previewWindow.print()')
    && str_contains($seminarPage, 'use the PDF viewer’s print control or download the file')
    && str_contains($adminJs, 'preview.invoker.focus({ preventScroll: true })')
    && preg_match('/window\\.open\\s*\\(/i', $adminJs) === 0
    && preg_match('/href="[^"]*seminar_pdf\\.php[^"]*"/i', $adminJs) === 0);
$printPreviewStart = strpos($adminJs, 'function printPdfPreview()');
$printPreviewEnd = strpos($adminJs, "\n  app.addEventListener('click'", $printPreviewStart === false ? 0 : $printPreviewStart);
$printPreviewBody = $printPreviewStart !== false && $printPreviewEnd !== false ? substr($adminJs, $printPreviewStart, $printPreviewEnd - $printPreviewStart) : '';
$check('print fallback keeps the loaded PDF and preview actions visible', str_contains($printPreviewBody, 'previewWindow.print()')
    && str_contains($printPreviewBody, 'pdfPreviewPrintHelp.textContent =')
    && !str_contains($printPreviewBody, 'showPdfPreviewError')
    && str_contains($seminarPage, 'data-pdf-print-help role="status" aria-live="polite"')
    && str_contains($seminarPage, 'data-pdf-download hidden')
    && str_contains($seminarPage, 'data-pdf-print disabled'));
$check('roster edits and assignments are submitted through the same atomic save operation', str_contains($adminJs, 'attendee_edits, allow_mixed')
    && str_contains($adminEndpoint, "if (\$op === 'save_assignments')")
    && str_contains($adminEndpoint, '$conn->begin_transaction();')
    && str_contains($adminEndpoint, 'foreach ($normalizedEdits as $attendeeId => $edit)'));
$check('Event Hall invoice finalization locks and rechecks attached hotel rooms before confirming', str_contains($statusEndpoint, 'seminar_assert_hotel_room_available')
    && str_contains($statusEndpoint, 'booking_rooms WHERE booking_id=? ORDER BY venue_id FOR UPDATE')
    && str_contains($statusEndpoint, "UPDATE bookings SET guests_count = ?, base_amount = ?, addons_amount = ?, total_amount = ?, payment_scheme = ?, booking_status = 'Confirmed'"));
$check('online submission releases only its own primary and allocated add-on room locks', str_contains($onlineSubmit, 'venue_id IN (SELECT venue_id FROM booking_rooms WHERE booking_id = ?)')
    && str_contains($onlineSubmit, '$stmt_unlock->bind_param("sii", $session_id, $venue_id, $booking_id)'));

// Exercise transactional locking in a uniquely named throwaway database. The
// runner never modifies the configured application database or its tables.
require_once $root . '/config/db_connect.php';
$temporaryFloorTables = [];
try {
    $conn->query('CREATE TEMPORARY TABLE venues (id INT PRIMARY KEY, name VARCHAR(100))');
    $temporaryFloorTables[] = 'venues';
    $conn->query('CREATE TEMPORARY TABLE hotel_rooms (venue_id INT PRIMARY KEY, room_number VARCHAR(20), room_type VARCHAR(80), max_capacity INT, floor_label VARCHAR(80) NULL)');
    $temporaryFloorTables[] = 'hotel_rooms';
    $conn->query('CREATE TEMPORARY TABLE seminar_reservations (seminar_id INT, venue_id INT, resource_kind VARCHAR(10), floor_label VARCHAR(80) NULL)');
    $temporaryFloorTables[] = 'seminar_reservations';
    $conn->query("INSERT INTO venues VALUES (7,'North Wing')");
    $conn->query("INSERT INTO hotel_rooms VALUES (7,'701','Deluxe',2,'Floor 8')");
    $conn->query("INSERT INTO seminar_reservations VALUES (99,7,'room','Legacy Floor')");
    $floorStmt = $conn->prepare("SELECT sr.venue_id,COALESCE(NULLIF(TRIM(h.floor_label), ''), sr.floor_label) AS floor_label,v.name,h.room_number,h.room_type,h.max_capacity FROM seminar_reservations sr JOIN venues v ON v.id=sr.venue_id JOIN hotel_rooms h ON h.venue_id=sr.venue_id WHERE sr.seminar_id=? AND sr.resource_kind='room' ORDER BY v.name,h.room_number,sr.venue_id");
    $floorTestSeminarId = 99;
    $floorStmt->bind_param('i', $floorTestSeminarId);
    $floorStmt->execute();
    $inventoryRoom = $floorStmt->get_result()->fetch_assoc();
    $floorTestSeminar = ['name' => 'Floor Verification', 'hall_name' => 'Demo Hall', 'hall_start_date' => '2030-06-01', 'hall_end_date' => '2030-06-01', 'hotel_check_in' => '2030-05-31', 'hotel_check_out' => '2030-06-02'];
    $floorTestAttendees = [['full_name' => 'Floor Test', 'location' => 'Sevilla', 'assigned_venue_id' => 7]];
    $inventoryHtml = seminar_render_pdf_html($floorTestSeminar, [$inventoryRoom], $floorTestAttendees, 'rooms');
    $check('inventory floor query feeds the authoritative value into print HTML once', $inventoryRoom['floor_label'] === 'Floor 8'
        && substr_count($inventoryHtml, 'Floor 8') === 1 && !str_contains($inventoryHtml, 'Legacy Floor'));
    $conn->query("UPDATE hotel_rooms SET floor_label='' WHERE venue_id=7");
    $emptyInventoryFloor = $conn->query("SELECT COALESCE(NULLIF(TRIM(h.floor_label), ''), sr.floor_label) AS floor_label FROM seminar_reservations sr JOIN hotel_rooms h ON h.venue_id=sr.venue_id WHERE sr.seminar_id=99")->fetch_assoc()['floor_label'];
    $conn->query("UPDATE hotel_rooms SET floor_label='   ' WHERE venue_id=7");
    $whitespaceInventoryFloor = $conn->query("SELECT COALESCE(NULLIF(TRIM(h.floor_label), ''), sr.floor_label) AS floor_label FROM seminar_reservations sr JOIN hotel_rooms h ON h.venue_id=sr.venue_id WHERE sr.seminar_id=99")->fetch_assoc()['floor_label'];
    $conn->query('UPDATE hotel_rooms SET floor_label=NULL WHERE venue_id=7');
    $nullInventoryFloor = $conn->query("SELECT COALESCE(NULLIF(TRIM(h.floor_label), ''), sr.floor_label) AS floor_label FROM seminar_reservations sr JOIN hotel_rooms h ON h.venue_id=sr.venue_id WHERE sr.seminar_id=99")->fetch_assoc()['floor_label'];
    $check('NULL, empty, and whitespace inventory labels fall back to the saved reservation floor', $nullInventoryFloor === 'Legacy Floor'
        && $emptyInventoryFloor === 'Legacy Floor' && $whitespaceInventoryFloor === 'Legacy Floor');
    $floorStmt->close();
} catch (mysqli_sql_exception $error) {
    foreach (array_reverse($temporaryFloorTables) as $table) $conn->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
    $temporaryFloorTables = [];
    if (!in_array($error->getCode(), [1044, 1045, 1142], true)) throw $error;
    echo "SKIP temporary floor SQL/print checks: the configured database account cannot create temporary tables.\n";
}
foreach (array_reverse($temporaryFloorTables) as $table) $conn->query("DROP TEMPORARY TABLE IF EXISTS `$table`");

$testDbName = 'sevilla360_seminar_test_' . bin2hex(random_bytes(6));
$createdTestDb = false;
$databaseTestsSkipped = false;
try {
    $conn->query("CREATE DATABASE `$testDbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $createdTestDb = true;
} catch (mysqli_sql_exception $error) {
    if (!in_array($error->getCode(), [1044, 1045], true)) throw $error;
    $databaseTestsSkipped = true;
}

if ($createdTestDb) {
    try {
    $host = $_ENV['DB_HOST'] ?? 'localhost'; $user = $_ENV['DB_USER'] ?? 'root'; $password = $_ENV['DB_PASS'] ?? '';
    $testConn = new mysqli($host, $user, $password, $testDbName);
    $testConn->set_charset('utf8mb4');
    $testConn->query('CREATE TABLE venues (id INT NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) ENGINE=InnoDB');
    $testConn->query('CREATE TABLE users (id INT NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) ENGINE=InnoDB');
    $testConn->query('CREATE TABLE hotel_rooms (venue_id INT NOT NULL, room_number VARCHAR(20) NULL, PRIMARY KEY (venue_id)) ENGINE=InnoDB');
    $testConn->query('INSERT INTO venues (id) VALUES (1),(2),(3)');
    $testConn->query('INSERT INTO users (id) VALUES (1)');
    $migration = file_get_contents($root . '/migrations/028_seminars.sql');
    foreach (explode(';', (string)$migration) as $statement) if (trim($statement) !== '') $testConn->query($statement);
    $floorMigration = file_get_contents($root . '/migrations/029_hotel_room_floors.sql');
    foreach (explode(';', (string)$floorMigration) as $statement) if (trim($statement) !== '') $testConn->query($statement);
    $testConn->query("INSERT INTO seminars (id,name,status,hall_venue_id,hall_start_date,hall_end_date,hotel_check_in,hotel_check_out,created_by) VALUES
        (1,'Hall date test','draft',1,'2030-06-01','2030-06-03','2030-05-31','2030-06-04',1),
        (2,'Room date test','draft',2,'2030-06-01','2030-06-03','2030-06-01','2030-06-03',1)");
    $testConn->query("INSERT INTO seminar_reservations (seminar_id,venue_id,resource_kind,start_date,end_date) VALUES
        (1,1,'hall','2030-06-01','2030-06-03'),(2,2,'room','2030-06-01','2030-06-03')");
    $testConn->query("INSERT INTO hotel_rooms (venue_id,room_number,floor_label) VALUES (2,'12','Floor 8'),(3,'13',NULL)");
    $testConn->query("UPDATE seminar_reservations SET floor_label='Legacy Floor' WHERE seminar_id=2 AND resource_kind='room'");
    $floorQuery = "SELECT COALESCE(NULLIF(TRIM(h.floor_label), ''), sr.floor_label) AS floor_label FROM seminar_reservations sr LEFT JOIN hotel_rooms h ON h.venue_id=sr.venue_id WHERE sr.seminar_id=2 AND sr.resource_kind='room'";
    $inventoryFloor = (string)$testConn->query($floorQuery)->fetch_assoc()['floor_label'];
    $check('seminar floor reads prefer inventory and render the inventory value once in print HTML', $inventoryFloor === 'Floor 8'
        && substr_count(seminar_render_pdf_html($seminar, [['venue_id' => 2, 'name' => 'North Wing', 'room_number' => '12', 'floor_label' => $inventoryFloor, 'max_capacity' => 1]], [['full_name' => 'Inventory Floor', 'location' => 'Sevilla', 'assigned_venue_id' => 2]], 'rooms'), 'Floor 8') === 1);
    $testConn->query("UPDATE hotel_rooms SET floor_label=NULL WHERE venue_id=2");
    $legacyFloor = (string)$testConn->query($floorQuery)->fetch_assoc()['floor_label'];
    $check('legacy seminar reservation floors are used only while inventory is NULL', $legacyFloor === 'Legacy Floor');
    $testConn->query("UPDATE hotel_rooms SET floor_label='' WHERE venue_id=2");
    $emptyInventoryFloor = (string)$testConn->query($floorQuery)->fetch_assoc()['floor_label'];
    $check('an empty inventory floor preserves a legacy reservation floor after a routine room edit', $emptyInventoryFloor === 'Legacy Floor');
    $testConn->query("UPDATE hotel_rooms SET floor_label='   ' WHERE venue_id=2");
    $whitespaceInventoryFloor = (string)$testConn->query($floorQuery)->fetch_assoc()['floor_label'];
    $check('a whitespace-only inventory floor also falls back to the saved reservation floor', $whitespaceInventoryFloor === 'Legacy Floor');
    $check('hall conflicts include the final event date and allow the following day', seminar_has_resource_conflict($testConn, 1, '2030-06-03', '2030-06-04')
        && !seminar_has_resource_conflict($testConn, 1, '2030-06-04', '2030-06-05'));
    $check('hotel conflicts exclude checkout and allow next check-in on checkout day', !seminar_has_resource_conflict($testConn, 2, '2030-06-03', '2030-06-05')
        && seminar_has_resource_conflict($testConn, 2, '2030-06-02', '2030-06-04'));
    $check('maintenance overlap follows inclusive hall and exclusive hotel date ranges', seminar_has_maintenance_conflict($testConn, 1, '2030-06-03', '2030-06-03')
        && !seminar_has_maintenance_conflict($testConn, 2, '2030-06-03', '2030-06-04')
        && seminar_has_maintenance_conflict($testConn, 2, '2030-06-02', '2030-06-03'));

    $testConn->begin_transaction();
    $lock = $testConn->query('SELECT id FROM venues WHERE id=3 FOR UPDATE'); $lock->free();
    [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $pid = pcntl_fork();
    if ($pid === -1) throw new RuntimeException('Unable to fork concurrency test process.');
    if ($pid === 0) {
        fclose($parentSocket);
        try {
            $childConn = new mysqli($host, $user, $password, $testDbName);
            $childConn->set_charset('utf8mb4'); $childConn->begin_transaction();
            fwrite($childSocket, "ready\n"); fflush($childSocket);
            $childLock = $childConn->query('SELECT id FROM venues WHERE id=3 FOR UPDATE'); $childLock->free();
            $conflict = seminar_has_resource_conflict($childConn, 3, '2030-06-01', '2030-06-03');
            $childConn->rollback();
            fwrite($childSocket, ($conflict ? 'conflict' : 'clear') . "\n"); fflush($childSocket);
        } catch (Throwable $error) {
            fwrite($childSocket, 'error:' . get_class($error) . "\n"); fflush($childSocket);
        }
        posix_kill(getmypid(), SIGKILL);
    }
    fclose($childSocket); stream_set_timeout($parentSocket, 15);
    if (trim((string)fgets($parentSocket)) !== 'ready') throw new RuntimeException('Concurrency test worker did not start.');
    $testConn->query("INSERT INTO seminars (id,name,status,hall_venue_id,hall_start_date,hall_end_date,hotel_check_in,hotel_check_out,created_by) VALUES (3,'Concurrent hold','draft',3,'2030-06-01','2030-06-03','2030-06-01','2030-06-03',1)");
    $testConn->query("INSERT INTO seminar_reservations (seminar_id,venue_id,resource_kind,start_date,end_date) VALUES (3,3,'hall','2030-06-01','2030-06-03')");
    $testConn->commit();
    $workerResult = trim((string)fgets($parentSocket)); fclose($parentSocket);
    pcntl_waitpid($pid, $status);
    $check('concurrent reservation attempt serializes on the venue row and observes the committed hold', $workerResult === 'conflict');
    $testConn->close();
    } finally {
        if (isset($testConn) && $testConn instanceof mysqli) $testConn->close();
        $conn->query("DROP DATABASE IF EXISTS `$testDbName`");
    }
}

foreach ($checks as $label => $passed) echo ($passed ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
echo 'Passed ' . count($checks) . ' seminar contract checks.' . PHP_EOL;
if ($databaseTestsSkipped) echo 'SKIP isolated SQL conflict/concurrency checks: the configured database account cannot create disposable databases.' . PHP_EOL;
