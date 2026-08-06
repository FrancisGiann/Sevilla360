<!-- ADMIN EVENT HALL TAB -->
<div class="tab-content active" id="tab-event">
    <div class="dynamic-img-wrapper">
        <img id="event-img"
            src="https://images.unsplash.com/photo-1519225421980-715cb0215aed?auto=format&fit=crop&w=800"
            alt="Event Hall">
    </div>

    <!-- 1. WHAT: DYNAMIC DATABASE DROPDOWN & EVENT TYPE -->
    <div class="form-row">
        <div class="form-group">
            <label>Select Venue Space</label>
            <select id="event-venue">
                <option value="" disabled selected>Select an Event Hall...</option>
                <?php foreach($event_halls as $hall): ?>
                <option value="<?php echo $hall['base_rate']; ?>" data-id="<?php echo $hall['id']; ?>"
                    data-name="<?php echo htmlspecialchars($hall['name']); ?>" data-type="Event Hall">
                    <?php echo htmlspecialchars($hall['name']); ?> (Base Rate:
                    ₱<?php echo number_format($hall['base_rate']); ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Estimated Event Style</label>
            <select id="event-style">
                <option value="0">Minimalist (Standard Setup)</option>
                <option value="0">Classic Elegance Setup</option>
            </select>
        </div>
    </div>

    <div class="form-group">
        <label>Event Type</label>
        <div class="radio-group" id="event-type-group">
            <label><input type="radio" name="event-type" value="0" data-text="Plain Hall" checked> Plain Hall</label>
            <label><input type="radio" name="event-type" value="0" data-text="Wedding"> Wedding</label>
            <label><input type="radio" name="event-type" value="0" data-text="Birthday"> Birthday</label>
            <label><input type="radio" name="event-type" value="0" id="event-others-radio" data-text="Custom Event">
                Others</label>
        </div>
        <input type="text" id="event-type-others" class="hidden custom-input"
            placeholder="Please specify event type (e.g. Corporate Seminar)...">
    </div>

    <!-- 2. WHEN: CALENDAR UI -->
    <div style="margin-top: 2rem; margin-bottom: 2rem;">
        <label class="small-label">SELECT EVENT DATES</label>
        <?php
        $calendarId = 'cal-ui-event';
        include 'includes/partials/booking_calendar.php';
        ?>
    </div>

    <!-- 3. WHO & EXTRAS: GUESTS AND ADD-ONS -->
    <div class="form-group">
        <label>Number of Guests</label>
        <input type="number" id="event-guests" min="10" placeholder="e.g. 100">
    </div>

    <!-- ADD-ONS -->
    <?php include 'includes/partials/addons_section.php'; ?>
</div>