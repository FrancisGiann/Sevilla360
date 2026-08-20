<?php
if (!isset($sys_settings)) {
    $sys_query = $conn->query("SELECT setting_key, setting_value FROM system_settings");
    $sys_settings = [];
    if ($sys_query) {
        while($r = $sys_query->fetch_assoc()) {
            $sys_settings[$r['setting_key']] = $r['setting_value'];
        }
    }
}
$type_wed = $sys_settings['event_type_wedding'] ?? 10000;
$type_bday = $sys_settings['event_type_birthday'] ?? 5000;

$default_event_img = (!empty($event_halls) && !empty($event_halls[0]['image']))
    ? $event_halls[0]['image']
    : 'assets/img/placeholder.jpg';
?>

<!-- ADMIN EVENT HALL TAB -->
<div class="tab-content active" id="tab-event">
    <div class="dynamic-img-wrapper">
        <img id="event-img"
            src="<?php echo htmlspecialchars($default_event_img); ?>"
            alt="Event Hall">
    </div>

    <div class="form-row">
        <!-- 1. WHAT: DYNAMIC DATABASE DROPDOWN (WITH CAPACITIES) -->
        <div class="form-group" style="width: 100%;">
            <label>Select Venue Space</label>
            <select id="event-venue">
                <option value="" disabled selected>Select an Event Hall...</option>
                <?php foreach($event_halls as $hall): ?>
                <option value="<?php echo $hall['base_rate']; ?>" data-id="<?php echo $hall['id']; ?>"
                    data-name="<?php echo htmlspecialchars($hall['name']); ?>" data-type="Event Hall"
                    data-theater="<?php echo $hall['capacity_theater'] ?? 0; ?>"
                    data-classroom="<?php echo $hall['capacity_classroom'] ?? 0; ?>"
                    data-banquet="<?php echo $hall['capacity_banquet'] ?? 0; ?>"
                    data-img="<?php echo htmlspecialchars($hall['image']); ?>">
                    <?php echo htmlspecialchars($hall['name']); ?> (Base Rate:
                    ₱<?php echo number_format($hall['base_rate']); ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Event Setup / Seating Style</label>
            <select id="event-style">
                <option value="0">Theater Style</option>
                <option value="0">Classroom Style</option>
                <option value="0">Banquet Type</option>
            </select>
        </div>
    </div>

    <div class="form-group">
        <label>Event Type</label>
        <div class="radio-group-inline mt-10" id="event-type-group">
            <label><input type="radio" name="event-type" value="0" data-text="Plain Hall" checked> Plain
                Hall</label>
            <label><input type="radio" name="event-type" value="<?php echo $type_wed; ?>" data-text="Wedding">
                Wedding</label>
            <label><input type="radio" name="event-type" value="<?php echo $type_bday; ?>" data-text="Birthday">
                Birthday</label>
            <label><input type="radio" name="event-type" value="0" id="event-others-radio" data-text="Custom Event">
                Others</label>
        </div>
        <input type="text" id="event-type-others" class="hidden custom-input"
            placeholder="Please specify event type (e.g. Corporate Seminar)...">
    </div>

    <div style="margin-top: 2rem; margin-bottom: 2rem;">
        <label class="small-label">SELECT EVENT DATES</label>
        <?php
        $calendarId = 'cal-ui-event';
        include 'includes/partials/booking_calendar.php';
        ?>
    </div>

    <div class="form-group">
        <label>Number of Guests</label>
        <input type="number" id="event-guests" min="10" placeholder="e.g. 100">
    </div>

    <?php include 'includes/partials/addons_section.php'; ?>
</div>