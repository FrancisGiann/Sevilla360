<!-- RESORT VILLA  TAB-->
<div class="tab-content" id="tab-resort-villa">
    <h2 class="section-title">Reserve a Resort Villa</h2>

    <!--- CALENDAR UI -->
    <?php
    $calendarId = 'cal-ui-villa';
    include 'includes/partials/booking_calendar.php';
    ?>

    <div class="dynamic-img-wrapper">
        <img id="villa-img"
            src="https://images.unsplash.com/photo-1582268611958-ebfd161ef9cf?auto=format&fit=crop&w=800"
            alt="Resort Villa">
    </div>

    <!-- DYNAMIC DATABASE DROPDOWN -->
    <div class="form-group">
        <label>Select Villa</label>
        <select id="villa-type">
            <option value="" disabled selected>Select a Villa...</option>
            <?php foreach($villas as $villa): ?>
            <option value="<?php echo $villa['base_rate']; ?>" data-id="<?php echo $villa['id']; ?>">
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
                <input type="radio" name="villa-stay" value="Day Time Stay" checked>
                <span class="stay-title">Villa Day Time Stay — ₱3,500</span>
            </label>
            <label>
                <input type="radio" name="villa-stay" value="Overnight">
                <span class="stay-title">Villa Overnight — ₱6,500</span>
            </label>
        </div>
    </div>

    <div class="form-group" style="margin-top: 1.5rem;">
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
                <li>TV</li>
                <li>Bed</li>
                <li>Airconditioner</li>
                <li>Hot and cold shower</li>
                <li>Refrigerator</li>
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

    <div class="form-group">
        <label class="small-label">PAYMENT SCHEME</label>
        <div class="radio-group">
            <label><input type="radio" name="villa-payment" value="100% Full" checked> 100%
                Full</label>
            <label><input type="radio" name="villa-payment" value="50% Downpayment"> 50%
                Downpayment</label>
            <label><input type="radio" name="villa-payment" value="20% Reservation"> 20%
                Reservation</label>
        </div>
    </div>
</div>