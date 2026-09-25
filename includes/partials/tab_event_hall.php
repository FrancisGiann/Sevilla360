<?php
// Fetch system settings safely for dynamic pricing
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

<!-- EVENT HALL TAB -->
<div class="tab-content active" id="tab-event-hall" role="tabpanel" aria-labelledby="booking-tab-event-hall" tabindex="0" aria-hidden="false">
    <h2 class="section-title">Event Inquiry & Date Reservation</h2>
    <section class="booking-step-panel" data-booking-step-panel="1" aria-labelledby="event-step-choose">
        <h3 class="booking-step-heading" id="event-step-choose" tabindex="-1">Choose an event hall</h3>
        <p class="booking-step-intro">Choose a space to see its details, amenities, and capacity.</p>
        <div class="form-group">
            <label for="event-venue">Select Venue Space</label>
            <select id="event-venue">
                <option value="" disabled selected>Select an Event Hall...</option>
                <?php foreach($event_halls as $hall): ?>
                <option value="<?php echo $hall['base_rate']; ?>" data-id="<?php echo $hall['id']; ?>"
                    data-name="<?php echo htmlspecialchars($hall['name']); ?>" data-type="Event Hall"
                    data-description="<?php echo htmlspecialchars($hall['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                    data-amenities="<?php echo htmlspecialchars($hall['amenities'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                    data-theater="<?php echo $hall['capacity_theater'] ?? 0; ?>"
                    data-classroom="<?php echo $hall['capacity_classroom'] ?? 0; ?>"
                    data-banquet="<?php echo $hall['capacity_banquet'] ?? 0; ?>"
                    data-img="<?php echo htmlspecialchars($hall['image']); ?>">
                    <?php echo htmlspecialchars($hall['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="inclusions-card venue-information-card" id="event-venue-information">
            <div class="inc-col">
                <h4>Venue Information</h4>
                <p id="event-venue-description">Select an event hall to view its description.</p>
            </div>
            <div class="inc-col">
                <h4>Amenities</h4>
                <ul id="event-venue-amenities" aria-live="polite"><li class="amenities-empty">Select an event hall to view its amenities.</li></ul>
            </div>
            <div class="venue-facts-grid event-facts-grid" aria-label="Event hall details">
                <div class="venue-fact"><span class="fact-label">Base rate</span><strong id="event-base-rate">—</strong></div>
                <div class="venue-fact"><span class="fact-label">Theater capacity</span><strong id="event-theater-capacity">—</strong></div>
                <div class="venue-fact"><span class="fact-label">Classroom capacity</span><strong id="event-classroom-capacity">—</strong></div>
                <div class="venue-fact"><span class="fact-label">Banquet capacity</span><strong id="event-banquet-capacity">—</strong></div>
            </div>
        </div>

        <div class="dynamic-img-wrapper booking-venue-image" id="event-image-panel" hidden>
            <img id="event-img" alt="" hidden>
            <p class="booking-image-placeholder" id="event-image-placeholder" role="status" hidden></p>
        </div>
        <div class="booking-step-actions booking-step-actions-end">
            <button type="button" class="btn booking-step-next" data-booking-step-next="2">Continue to dates</button>
        </div>
    </section>

    <section class="booking-step-panel" data-booking-step-panel="2" aria-labelledby="event-step-dates" hidden>
        <h3 class="booking-step-heading" id="event-step-dates" tabindex="-1">Choose inquiry dates</h3>
        <div class="booking-date-control">
            <p class="small-label">SELECT INQUIRY DATES</p>
            <?php $calendarId = 'cal-ui-event'; include 'includes/partials/booking_calendar.php'; ?>
            <p class="booking-inline-note">Dates are checked for availability, but an inquiry does not hold the date. It goes to the first finalized contract.</p>
        </div>
        <div class="form-group">
            <label for="event-guests">Estimated Number of Guests</label>
            <input type="number" id="event-guests" min="10" step="1" placeholder="e.g. 100">
            <small class="capacity-note">Choose a guest count that fits the seating style selected in Options.</small>
        </div>
        <div class="booking-step-actions">
            <button type="button" class="btn booking-step-back" data-booking-step-back="1">Back</button>
            <button type="button" class="btn booking-step-next" data-booking-step-next="3">Continue to options</button>
        </div>
    </section>

    <section class="booking-step-panel" data-booking-step-panel="3" aria-labelledby="event-step-options" hidden>
        <h3 class="booking-step-heading" id="event-step-options" tabindex="-1">Plan your event</h3>
        <div class="form-group">
            <label for="event-style">Event Setup / Seating Style</label>
            <select id="event-style">
                <option value="theater">Theater Style</option>
                <option value="classroom">Classroom Style</option>
                <option value="banquet">Banquet Type</option>
            </select>
            <small class="capacity-note" id="event-capacity-note">Select an event hall to view capacity by setup style.</small>
        </div>
        <div class="form-group">
            <p class="form-group-label" id="event-type-label">What are you celebrating?</p>
            <div class="radio-group booking-choice-grid event-type-choice-grid" id="event-type-group" role="radiogroup" aria-labelledby="event-type-label">
                <label class="booking-choice-tile"><input type="radio" id="event-type-plain" name="event-type" value="0" data-text="Plain Hall" checked> Plain Hall</label>
                <label class="booking-choice-tile"><input type="radio" name="event-type" value="<?php echo $type_wed; ?>" data-text="Wedding"> Wedding</label>
                <label class="booking-choice-tile"><input type="radio" name="event-type" value="<?php echo $type_bday; ?>" data-text="Birthday"> Birthday</label>
                <label class="booking-choice-tile"><input type="radio" name="event-type" value="0" id="event-others-radio" data-text="Custom Event"> Others</label>
            </div>
            <input type="text" id="event-type-others" class="hidden custom-input" aria-label="Specify other event type" placeholder="Please specify your event type (e.g. Corporate Seminar)...">
        </div>
        <?php include 'includes/partials/addons_section.php'; ?>
        <div class="booking-step-actions">
            <button type="button" class="btn booking-step-back" data-booking-step-back="2">Back</button>
            <button type="button" class="btn booking-step-next" data-booking-step-next="4">Review inquiry</button>
        </div>
    </section>

    <section class="booking-step-panel booking-review-panel" data-booking-step-panel="4" aria-labelledby="event-step-review" hidden>
        <h3 class="booking-step-heading" id="event-step-review" tabindex="-1">Review your event inquiry</h3>
        <p>Event inquiries only check availability; they do not hold the date or require payment. A coordinator will contact you within 24 hours to finalize the guest count, menus, styling, and quote. Manual payment instructions follow resort confirmation.</p>
        <div class="booking-step-actions">
            <button type="button" class="btn booking-step-back" data-booking-step-back="3">Back to options</button>
        </div>
    </section>
</div>
