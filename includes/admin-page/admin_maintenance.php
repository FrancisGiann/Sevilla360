<?php
require_once 'config/db_connect.php';

$upcoming_maint = $conn->query("
    SELECT m.id, m.start_date, m.end_date, m.maintenance_type, m.is_blocking, m.status, v.name as venue_name 
    FROM maintenance m 
    LEFT JOIN venues v ON m.venue_id = v.id
    WHERE (m.status = 'Scheduled' OR m.status IS NULL)
    ORDER BY m.start_date ASC, m.id ASC
");

$past_maint = $conn->query("
    SELECT m.id, m.start_date, m.end_date, m.maintenance_type, m.is_blocking, m.status, m.completed_at, v.name as venue_name 
    FROM maintenance m 
    LEFT JOIN venues v ON m.venue_id = v.id
    WHERE m.status = 'Completed'
    ORDER BY m.id DESC LIMIT 50
");

$parseMaintenanceDate = static function ($value) {
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    $dateString = trim($value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateString);
    $errors = DateTimeImmutable::getLastErrors();

    if ($date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d') !== $dateString
    ) {
        return null;
    }

    return $date;
};

$formatMaintenanceSchedule = static function ($startDate, $endDate) use ($parseMaintenanceDate) {
    $start = $parseMaintenanceDate($startDate);
    $end = $parseMaintenanceDate($endDate);

    if ($start === null || $end === null) {
        return '<span class="maintenance-schedule-value maintenance-date-unavailable">Date unavailable</span>';
    }

    $startLabel = htmlspecialchars($start->format('M j, Y'), ENT_QUOTES, 'UTF-8');
    $startValue = htmlspecialchars($start->format('Y-m-d'), ENT_QUOTES, 'UTF-8');
    $markup = '<span class="maintenance-schedule-value"><time datetime="' . $startValue . '">' . $startLabel . '</time>';

    if ($start->format('Y-m-d') !== $end->format('Y-m-d')) {
        $endLabel = htmlspecialchars($end->format('M j, Y'), ENT_QUOTES, 'UTF-8');
        $endValue = htmlspecialchars($end->format('Y-m-d'), ENT_QUOTES, 'UTF-8');
        $markup .= ' <span aria-hidden="true">—</span> <time datetime="' . $endValue . '">' . $endLabel . '</time>';
    }

    return $markup . '</span>';
};

$getOpenMaintenanceStatus = static function ($startDate, $endDate) use ($parseMaintenanceDate) {
    $start = $parseMaintenanceDate($startDate);
    $end = $parseMaintenanceDate($endDate);

    if ($start === null || $end === null) {
        return ['label' => 'Date needs review', 'class' => 'needs-review'];
    }

    if ($end < $start) {
        return ['label' => 'Invalid date range', 'class' => 'needs-review'];
    }

    $today = new DateTimeImmutable('today');
    if ($end < $today) {
        return ['label' => 'Overdue', 'class' => 'overdue'];
    }

    if ($start > $today) {
        return ['label' => 'Upcoming', 'class' => 'upcoming'];
    }

    return ['label' => 'In progress', 'class' => 'in-progress'];
};

$formatCompletedAt = static function ($value) {
    $timestamp = is_string($value) && trim($value) !== '' ? strtotime($value) : false;
    return $timestamp === false ? 'Date unavailable' : date('M j, Y h:i A', $timestamp);
};

$upcoming_maint_failed = $upcoming_maint === false;
$past_maint_failed = $past_maint === false;
$upcoming_maint_count = $upcoming_maint_failed ? 0 : $upcoming_maint->num_rows;
$past_maint_count = $past_maint_failed ? 0 : $past_maint->num_rows;

$venues_query = $conn->query("
    SELECT v.id, v.category, v.name, h.room_type, h.room_number 
    FROM venues v 
    LEFT JOIN hotel_rooms h ON v.id = h.venue_id 
    WHERE v.status = 'Available' 
    ORDER BY v.category, v.name, h.room_type, h.room_number
");
$grouped_venues = [
    'Event Hall' => [],
    'Hotel Room' => [],
    'Resort Villa' => []
];

while ($row = $venues_query->fetch_assoc()) {
    if ($row['category'] === 'Hotel Room') {
        $building = $row['name'];
        $type = $row['room_type'] ?: 'Standard';
        $num = $row['room_number'] ?: 'Unknown';
        
        if (!isset($grouped_venues['Hotel Room'][$building])) {
            $grouped_venues['Hotel Room'][$building] = [];
        }
        if (!isset($grouped_venues['Hotel Room'][$building][$type])) {
            $grouped_venues['Hotel Room'][$building][$type] = [];
        }
        $grouped_venues['Hotel Room'][$building][$type][] = [
            'id' => $row['id'],
            'display' => "Room $num"
        ];
    } else {
        $grouped_venues[$row['category']][] = [
            'id' => $row['id'],
            'display' => $row['name']
        ];
    }
}
?>

<script>
window.venueData = <?php
    $venueDataJson = json_encode($grouped_venues, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $venueDataJson === false ? '{}' : $venueDataJson;
?>;
</script>

<div class="admin-maintenance-container admin-booking-container">

    <!-- Top Section: Venue Selection & Category Tabs -->
    <div class="maintenance-venue-section">
        <label class="small-label maint-section-label">SELECT VENUE CATEGORY</label>

        <div class="booking-tabs venue-tabs" id="maintenance-tabs">
            <button class="tab-btn active" data-venue="Event Hall">Event Hall</button>
            <button class="tab-btn" data-venue="Hotel Room">Hotel Room</button>
            <button class="tab-btn" data-venue="Resort Villa">Resort Villa</button>
        </div>
    </div>

    <div class="maintenance-grid">
        <!-- Form Inputs & Availability Calendar -->
        <div class="maintenance-main">

            <!-- Maintenance Form Inputs -->
            <div class="booking-card form-section maint-form-card">

                <div class="form-group" id="wrapper-specific-venue">
                    <label for="maint-specific-venue" id="label-specific-venue" class="maint-uppercase-label">WHICH EVENT HALL?</label>
                    <select id="maint-specific-venue"></select>
                </div>
                
                <div id="wrapper-hotel-cascading" style="display: none; gap: 15px; margin-bottom: 20px;">
                    <div class="form-group" style="flex:1; margin-bottom: 0;">
                        <label for="maint-hotel-building" class="maint-uppercase-label">BUILDING</label>
                        <select id="maint-hotel-building"></select>
                    </div>
                    <div class="form-group" style="flex:1; margin-bottom: 0;">
                        <label for="maint-hotel-roomtype" class="maint-uppercase-label">ROOM TYPE</label>
                        <select id="maint-hotel-roomtype"></select>
                    </div>
                    <div class="form-group" style="flex:1; margin-bottom: 0;">
                        <label for="maint-hotel-roomnum" class="maint-uppercase-label">ROOM NUMBER</label>
                        <select id="maint-hotel-roomnum"></select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="maint-area">SPECIFIC AFFECTED AREA <span class="maint-optional-text">(Optional)</span></label>
                    <input type="text" id="maint-area" placeholder="e.g., Master Bathroom, Air Conditioning Unit...">
                </div>

                <div class="form-group">
                    <label for="maint-type">MAINTENANCE TYPE</label>
                    <select id="maint-type">
                        <option value="" disabled selected>Select a type...</option>
                        <option value="Electrical / Wiring">Electrical / Wiring</option>
                        <option value="Plumbing">Plumbing</option>
                        <option value="Deep Cleaning">Deep Cleaning</option>
                        <option value="Renovation">Renovation</option>
                        <option value="Pool / Garden Maintenance">Pool / Garden Maintenance</option>
                        <option value="General Inspection">General Inspection</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="maint-notes">DESCRIPTION / NOTES</label>
                    <textarea id="maint-notes" rows="4" placeholder="Add specific details regarding the maintenance..."></textarea>
                </div>

                <div class="form-group maint-mb-0">
                    <label class="toggle-label-ui" for="maint-block">
                        <span class="toggle-text">BLOCK UNIT FROM NEW BOOKINGS</span>
                        <div class="custom-toggle">
                            <input type="checkbox" id="maint-block">
                            <span class="toggle-slider"></span>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Availability Calendar UI -->
            <div class="maint-calendar-wrapper">
                <label class="maint-calendar-label">SELECT SCHEDULE DATES</label>
                <?php
                    $calendarId = 'cal-ui-maint';
                    include 'includes/partials/booking_calendar.php';
                ?>
            </div>

        </div>

        <!-- Right Sidebar: Maintenance Summary & Actions -->
        <div class="maintenance-sidebar">
            <div class="sticky-summary checkout-summary maint-summary-box">
                <h3 class="summary-title">Maintenance Summary</h3>

                <div class="summary-container active">
                    <p>Category <span class="sum-val maint-sum-category" id="sum-maint-category">Event Hall</span></p>
                    <p>Unit <span class="sum-val maint-sum-unit" id="sum-maint-unit">--</span></p>
                    <p>Date <span class="sum-val" id="sum-maint-date">--</span></p>
                    <p>Duration <span class="sum-val" id="sum-maint-duration">--</span></p>
                    <p>Area <span class="sum-val" id="sum-maint-area">--</span></p>
                    <p>Type <span class="sum-val" id="sum-maint-type">--</span></p>
                    <p class="maint-sum-last-row">Booking Block <span class="sum-val maint-sum-block" id="sum-maint-block">OFF</span></p>
                </div>

                <div class="action-buttons maint-summary-actions">
                    <button type="button" class="btn-confirm-walkin btn-confirm-maint" id="btn-schedule-maint">SCHEDULE MAINTENANCE</button>
                    <button type="button" class="btn-cancel-walkin btn-reset-maint" id="btn-clear-maint">CLEAR FORM</button>
                </div>
            </div>
        </div>
    </div>

    <section class="table-card maint-table-card" aria-labelledby="maint-records-title">
        <div class="maint-records-heading">
            <div>
                <h3 class="maint-records-title" id="maint-records-title">Maintenance records</h3>
                <p class="maint-records-description" id="maint-records-description">
                    Open schedules, including overdue work, stay here until completed or deleted. Recent completions shows the latest 50 records.
                </p>
            </div>
        </div>

        <div class="maint-table-tabs" id="maintTableSubTabs" role="group" aria-label="Maintenance record views">
            <button type="button" class="maint-view-button active" id="maint-view-active"
                data-maint-view="active" aria-pressed="true" aria-controls="view-maint-active">
                <span>Open &amp; scheduled</span>
                <span class="maint-view-count"><?php echo $upcoming_maint_count; ?></span>
            </button>
            <button type="button" class="maint-view-button" id="maint-view-history"
                data-maint-view="history" aria-pressed="false" aria-controls="view-maint-history">
                <span>Recent completions</span>
                <span class="maint-view-count"><?php echo $past_maint_count; ?></span>
            </button>
        </div>

        <div class="table-responsive maint-record-panel" id="view-maint-active" role="region"
            aria-labelledby="maint-view-active" aria-describedby="maint-records-description">
            <table class="bookings-table maint-record-table">
                <thead>
                    <tr>
                        <th scope="col">RECORD</th>
                        <th scope="col">SCHEDULE</th>
                        <th scope="col">STATUS</th>
                        <th scope="col">ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($upcoming_maint_failed): ?>
                    <tr class="maint-empty-state">
                        <td colspan="4" class="maint-empty-row" role="alert">Maintenance records could not be loaded. Refresh the page to try again.</td>
                    </tr>
                    <?php elseif ($upcoming_maint_count > 0): ?>
                    <?php while ($m = $upcoming_maint->fetch_assoc()): ?>
                    <?php
                        $openStatus = $getOpenMaintenanceStatus($m['start_date'], $m['end_date']);
                        $venueName = $m['venue_name'] ?? 'Venue unavailable';
                        $maintenanceType = $m['maintenance_type'] ?? 'Maintenance type unavailable';
                    ?>
                    <tr>
                        <td data-label="Record" class="maintenance-record-cell">
                            <span class="maintenance-record-details">
                                <strong class="maintenance-venue-name"><?php echo htmlspecialchars($venueName, ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span class="maintenance-type"><?php echo htmlspecialchars($maintenanceType, ENT_QUOTES, 'UTF-8'); ?></span>
                            </span>
                        </td>
                        <td data-label="Schedule" class="maintenance-schedule"><?php echo $formatMaintenanceSchedule($m['start_date'], $m['end_date']); ?></td>
                        <td data-label="Status">
                            <div class="maintenance-status-group">
                                <span class="maintenance-lifecycle maintenance-lifecycle--<?php echo htmlspecialchars($openStatus['class'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($openStatus['label'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <span class="maintenance-impact"><?php echo !empty($m['is_blocking']) ? 'Blocked' : 'Note only'; ?></span>
                            </div>
                        </td>
                        <td data-label="Actions" class="maintenance-actions-cell">
                            <div class="maint-action-cell">
                                <button type="button" class="btn-action btn-complete-maint maint-action-complete"
                                    data-id="<?php echo htmlspecialchars((string)$m['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                    title="Finish early and free up the calendar">Mark Done</button>
                                <button type="button" class="btn-action btn-delete-maint maint-action-delete"
                                    data-id="<?php echo htmlspecialchars((string)$m['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                    title="Completely delete this record">Delete</button>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    <?php else: ?>
                    <tr class="maint-empty-state">
                        <td colspan="4" class="maint-empty-row">No open maintenance records. Scheduled and overdue work will appear here.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="table-responsive maint-record-panel" id="view-maint-history" role="region"
            aria-labelledby="maint-view-history" aria-describedby="maint-records-description" hidden>
            <table class="bookings-table maint-record-table">
                <thead>
                    <tr>
                        <th scope="col">RECORD</th>
                        <th scope="col">SCHEDULE</th>
                        <th scope="col">STATUS</th>
                        <th scope="col">COMPLETED ON</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($past_maint_failed): ?>
                    <tr class="maint-empty-state">
                        <td colspan="4" class="maint-empty-row" role="alert">Completed records could not be loaded. Refresh the page to try again.</td>
                    </tr>
                    <?php elseif ($past_maint_count > 0): ?>
                    <?php while ($pm = $past_maint->fetch_assoc()): ?>
                    <?php
                        $historyStart = $parseMaintenanceDate($pm['start_date']);
                        $historyEnd = $parseMaintenanceDate($pm['end_date']);
                        $historyNeedsDateReview = $historyStart === null || $historyEnd === null || $historyEnd < $historyStart;
                        $historyCompletedOn = !empty($pm['completed_at'])
                            ? $formatCompletedAt($pm['completed_at'])
                            : ($historyEnd === null ? 'Date unavailable' : $historyEnd->format('M j, Y'));
                        $venueName = $pm['venue_name'] ?? 'Venue unavailable';
                        $maintenanceType = $pm['maintenance_type'] ?? 'Maintenance type unavailable';
                    ?>
                    <tr>
                        <td data-label="Record" class="maintenance-record-cell">
                            <span class="maintenance-record-details">
                                <strong class="maintenance-venue-name"><?php echo htmlspecialchars($venueName, ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span class="maintenance-type"><?php echo htmlspecialchars($maintenanceType, ENT_QUOTES, 'UTF-8'); ?></span>
                            </span>
                        </td>
                        <td data-label="Schedule" class="maintenance-schedule">
                            <?php echo $formatMaintenanceSchedule($pm['start_date'], $pm['end_date']); ?>
                            <?php if ($historyNeedsDateReview): ?>
                            <span class="maintenance-schedule-note">Dates need review</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Status">
                            <div class="maintenance-status-group">
                                <span class="maintenance-lifecycle maintenance-lifecycle--completed">Completed</span>
                                <span class="maintenance-impact"><?php echo !empty($pm['is_blocking']) ? 'Blocked' : 'Note only'; ?></span>
                            </div>
                        </td>
                        <td data-label="Completed on" class="maintenance-completed-date">
                            <?php echo htmlspecialchars($historyCompletedOn, ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    <?php else: ?>
                    <tr class="maint-empty-state">
                        <td colspan="4" class="maint-empty-row">No completed maintenance records yet.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
