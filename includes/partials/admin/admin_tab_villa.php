<!-- ADMIN RESORT VILLA TAB -->
<div class="tab-content" id="tab-villa">

    <div class="dynamic-img-wrapper">
        <img id="villa-img"
            src="https://images.unsplash.com/photo-1582268611958-ebfd161ef9cf?auto=format&fit=crop&w=800"
            alt="Resort Villa">
    </div>

    <!-- 1. WHAT: DYNAMIC DATABASE DROPDOWN & STAY TYPE -->
    <div class="form-group">
        <label>Select Villa</label>
        <select id="villa-type">
            <option value="" disabled selected>Select a Villa...</option>
            <?php foreach($villas as $villa): ?>
            <option value="<?php echo $villa['base_rate']; ?>" data-id="<?php echo $villa['id']; ?>"
                data-name="<?php echo htmlspecialchars($villa['name']); ?>" data-type="Resort Villa">
                <?php echo htmlspecialchars($villa['name']); ?> (₱<?php echo number_format($villa['base_rate']); ?>)
            </option>
            <?php endforeach; ?>
        </select>
        <small class="capacity-note">Base Capacity: 4 Pax | Maximum: 8 Pax</small>
    </div>

    <div class="form-group">
        <label class="small-label" style="margin-top: 1.5rem;">STAY TYPE</label>
        <div class="radio-group block-radios">
            <label>
                <input type="radio" name="villa-stay" id="stay-day" value="Day Time Stay" checked>
                <span class="stay-title">Villa Day Time Stay — ₱3,500</span>
            </label>
            <label>
                <input type="radio" name="villa-stay" id="stay-night" value="Overnight">
                <span class="stay-title">Villa Overnight — ₱6,500</span>
            </label>
        </div>
    </div>

    <!-- 2. WHEN: CALENDAR UI (MOVED TO MIDDLE) -->
    <div style="margin-top: 2rem; margin-bottom: 2rem;">
        <label class="small-label">SELECT BOOKING DATES</label>
        <?php
        $calendarId = 'cal-ui-villa';
        include 'includes/partials/booking_calendar.php';
        ?>
    </div>

    <!-- 3. WHO: GUESTS -->
    <div class="form-group">
        <label>Number of Guests</label>
        <input type="number" id="villa-guests" min="1" max="8" value="4">
        <small class="extra-pax-note">Additional ₱1,000 per head exceeding base capacity. <span
                id="villa-extra-fee"></span></small>
    </div>

    <!-- Reference Rules to assist Admin -->
    <div class="inclusions-card villa-inclusions" style="margin-top: 20px;">
        <div class="villa-rules">
            <div class="rule-box" id="rule-day">
                <strong>VILLA DAY TIME STAY</strong>
                <p>- Check in: 7AM | Check out: 5PM</p>
            </div>
            <div class="rule-box hidden" id="rule-night">
                <strong>VILLA OVERNIGHT</strong>
                <p>- Check in: 2PM | Check out: 12PM</p>
                <p>- Complimentary breakfast for 4 persons</p>
            </div>
        </div>
    </div>
</div>