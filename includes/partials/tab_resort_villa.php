<!-- RESORT VILLA TAB -->
<div class="tab-content" id="tab-resort-villa">
    <h2 class="section-title">Reserve a Resort Villa</h2>

    <div class="dynamic-img-wrapper">
        <img id="villa-img"
            src="<?php echo htmlspecialchars(!empty($villas) ? $villas[0]['image'] : 'assets/img/placeholder.jpg'); ?>"
            alt="Resort Villa">
    </div>

    <!-- 1. WHAT: DYNAMIC DATABASE DROPDOWN & STAY TYPE -->
    <div class="form-group">
        <label>Select Villa</label>
        <select id="villa-type">
            <option value="" disabled selected>Select a Villa...</option>
            <?php foreach($villas as $villa): ?>
            <option value="<?php echo $villa['base_rate']; ?>" data-id="<?php echo $villa['id']; ?>"
                data-name="<?php echo htmlspecialchars($villa['name']); ?>" data-type="Resort Villa"
                data-overnight="<?php echo $villa['overnight_rate']; ?>"
                data-base-cap="<?php echo $villa['base_capacity']; ?>"
                data-max-cap="<?php echo $villa['max_capacity']; ?>"
                data-extra-pax="<?php echo $villa['extra_pax_rate']; ?>"
                data-img="<?php echo htmlspecialchars($villa['image']); ?>">
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
        <label class="small-label">SELECT YOUR DATES</label>
        <?php
        $calendarId = 'cal-ui-villa';
        include 'includes/partials/booking_calendar.php';
        ?>
    </div>

    <!-- 3. WHO & EXTRAS: GUESTS AND INCLUSIONS -->
    <div class="form-group">
        <label>Number of Guests</label>
        <input type="number" id="villa-guests" min="1" max="8" value="4">
        <small class="extra-pax-note">Additional ₱1,000 per head exceeding base capacity. <span
                id="villa-extra-fee"></span></small>
    </div>

    <div class="inclusions-card villa-inclusions">
        <div class="villa-rules">
            <div class="rule-box" id="rule-day">
                <strong>VILLA DAY TIME STAY</strong>
                <p>- ₱3500 for 4 persons</p>
                <p>- Check in: 7AM Check out: 5PM</p>
            </div>
            <div class="rule-box hidden" id="rule-night">
                <strong>VILLA OVERNIGHT</strong>
                <p>- ₱6500 for 4 persons</p>
                <p>- Complimentary breakfast for 4 persons</p>
                <p>- Check in: 2PM Check out: 12PM</p>
            </div>
        </div>

        <div class="inc-col">
            <h4 class="script-subtitle">What's inside the house?</h4>
            <ul>
                <li>TV, Bed, Airconditioner</li>
                <li>Hot and cold shower, Refrigerator</li>
                <li>Toiletry items (Toothbrush, toothpaste, soap)</li>
            </ul>
        </div>
        <div class="inc-col" style="margin-top: 1.5rem;">
            <h4 class="script-subtitle">What's outside the house?</h4>
            <ul>
                <li>Small private swimming pool</li>
                <li>Garden</li>
            </ul>
        </div>
    </div>

    <!-- 4. PAYMENT SCHEME -->
    <div class="form-group">
        <label class="small-label">PAYMENT SCHEME</label>
        <div class="radio-group">
            <label><input type="radio" name="villa-payment" value="100% Full" checked> 100% Full</label>
            <label><input type="radio" name="villa-payment" value="50% Downpayment"> 50% Downpayment</label>
            <label><input type="radio" name="villa-payment" value="20% Reservation"> 20% Reservation</label>
        </div>
    </div>
</div>