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

?>

<!-- ADMIN EVENT HALL TAB -->
<div class="tab-content active" id="tab-event" role="tabpanel" aria-labelledby="walkin-tab-event" aria-hidden="false" tabindex="0">
    <div class="form-group venue-picker-group">
        <label for="event-venue">Select event hall</label>
        <select id="event-venue">
                <option value="" disabled selected>Select an Event Hall...</option>
                <?php foreach($event_halls as $hall): ?>
                <option value="<?php echo htmlspecialchars((string)$hall['base_rate'], ENT_QUOTES, 'UTF-8'); ?>"
                    data-id="<?php echo (int)$hall['id']; ?>"
                    data-name="<?php echo htmlspecialchars($hall['name'], ENT_QUOTES, 'UTF-8'); ?>" data-type="Event Hall"
                    data-description="<?php echo htmlspecialchars((string)($hall['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                    data-amenities="<?php echo htmlspecialchars((string)($hall['amenities'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                    data-theater="<?php echo (int)($hall['capacity_theater'] ?? 0); ?>"
                    data-classroom="<?php echo (int)($hall['capacity_classroom'] ?? 0); ?>"
                    data-banquet="<?php echo (int)($hall['capacity_banquet'] ?? 0); ?>"
                    data-img="<?php echo htmlspecialchars((string)$hall['image'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($hall['name'], ENT_QUOTES, 'UTF-8'); ?>
                </option>
                <?php endforeach; ?>
        </select>
        <small class="venue-picker-help">Select a hall to review its description, amenities, rate, and seating capacity.</small>
    </div>

    <div class="inclusions-card venue-information-card event-information-card" id="event-venue-information">
        <div class="inc-col">
            <h4>Venue information</h4>
            <p id="event-venue-description" class="venue-description-empty">Select an event hall to view its description.</p>
        </div>
        <div class="inc-col">
            <h4>Amenities</h4>
            <ul id="event-venue-amenities" aria-live="polite"><li class="amenities-empty">Select an event hall to view its amenities.</li></ul>
        </div>
        <div class="venue-facts-grid event-facts-grid" aria-label="Event hall details">
            <div class="venue-fact"><span class="fact-label">Base rate</span><strong id="event-base-rate" class="fact-placeholder">—</strong></div>
            <div class="venue-fact"><span class="fact-label">Theater capacity</span><strong id="event-theater-capacity" class="fact-placeholder">—</strong></div>
            <div class="venue-fact"><span class="fact-label">Classroom capacity</span><strong id="event-classroom-capacity" class="fact-placeholder">—</strong></div>
            <div class="venue-fact"><span class="fact-label">Banquet capacity</span><strong id="event-banquet-capacity" class="fact-placeholder">—</strong></div>
        </div>
    </div>

    <div class="dynamic-img-wrapper venue-image-frame" id="event-image-panel" hidden>
        <img id="event-img" alt="" hidden>
        <p class="venue-image-empty" role="status" hidden>Photo unavailable for this event hall.</p>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label for="event-style">Event setup / seating style</label>
            <select id="event-style">
                <option value="theater">Theater Style</option>
                <option value="classroom">Classroom Style</option>
                <option value="banquet">Banquet Type</option>
            </select>
        </div>
    </div>

    <div class="form-group">
        <p class="form-group-label" id="event-type-label">Event type</p>
        <div class="radio-group booking-choice-grid event-type-choice-grid walkin-choice-grid" id="event-type-group" role="radiogroup" aria-labelledby="event-type-label">
            <label class="booking-choice-tile"><input type="radio" name="event-type" value="0" data-text="Plain Hall" checked> Plain Hall</label>
            <label class="booking-choice-tile"><input type="radio" name="event-type" value="<?php echo htmlspecialchars((string)$type_wed, ENT_QUOTES, 'UTF-8'); ?>" data-text="Wedding"> Wedding</label>
            <label class="booking-choice-tile"><input type="radio" name="event-type" value="<?php echo htmlspecialchars((string)$type_bday, ENT_QUOTES, 'UTF-8'); ?>" data-text="Birthday"> Birthday</label>
            <label class="booking-choice-tile"><input type="radio" name="event-type" value="0" id="event-others-radio" data-text="Custom Event"> Others</label>
        </div>
        <input type="text" id="event-type-others" class="hidden custom-input"
            aria-label="Specify other event type" placeholder="Please specify event type (e.g. Corporate Seminar)...">
    </div>

    <div class="walkin-calendar-section">
        <p class="small-label">Select event dates</p>
        <?php
        $calendarId = 'cal-ui-event';
        include 'includes/partials/booking_calendar.php';
        ?>
    </div>

    <div class="form-group">
        <label for="event-guests">Number of guests</label>
        <input type="number" id="event-guests" min="10" step="1" placeholder="e.g. 100">
    </div>

    <?php include 'includes/partials/addons_section.php'; ?>
</div>
