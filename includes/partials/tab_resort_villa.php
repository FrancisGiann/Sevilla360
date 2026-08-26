<!-- RESORT VILLA TAB -->
<div class="tab-content" id="tab-resort-villa">
    <h2 class="section-title">Reserve a Resort Villa</h2>

    <div class="dynamic-img-wrapper">
        <img id="villa-img"
            src="<?php echo htmlspecialchars(!empty($villas) ? $villas[0]['image'] : 'assets/img/placeholder.jpg'); ?>"
            alt="Resort Villa">
    </div>

    <div class="form-group">
        <label>Select Villa</label>
        <select id="villa-type">
            <option value="" disabled selected>Select a Villa...</option>
            <?php foreach($villas as $villa): ?>
            <option value="<?php echo htmlspecialchars((string)$villa['base_rate'], ENT_QUOTES, 'UTF-8'); ?>"
                data-id="<?php echo (int)$villa['id']; ?>"
                data-name="<?php echo htmlspecialchars($villa['name'], ENT_QUOTES, 'UTF-8'); ?>"
                data-type="Resort Villa"
                data-overnight="<?php echo htmlspecialchars((string)$villa['overnight_rate'], ENT_QUOTES, 'UTF-8'); ?>"
                data-base-cap="<?php echo (int)$villa['base_capacity']; ?>"
                data-max-cap="<?php echo (int)$villa['max_capacity']; ?>"
                data-extra-pax="<?php echo htmlspecialchars((string)$villa['extra_pax_rate'], ENT_QUOTES, 'UTF-8'); ?>"
                data-private-pool="<?php echo (int)$villa['has_private_pool']; ?>"
                data-day-in="<?php echo htmlspecialchars((string)$villa['day_check_in_time'], ENT_QUOTES, 'UTF-8'); ?>"
                data-day-out="<?php echo htmlspecialchars((string)$villa['day_check_out_time'], ENT_QUOTES, 'UTF-8'); ?>"
                data-night-in="<?php echo htmlspecialchars((string)$villa['overnight_check_in_time'], ENT_QUOTES, 'UTF-8'); ?>"
                data-night-out="<?php echo htmlspecialchars((string)$villa['overnight_check_out_time'], ENT_QUOTES, 'UTF-8'); ?>"
                data-day-inclusions="<?php echo htmlspecialchars((string)($villa['day_stay_inclusions'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                data-night-inclusions="<?php echo htmlspecialchars((string)($villa['overnight_stay_inclusions'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                data-description="<?php echo htmlspecialchars((string)($villa['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                data-amenities="<?php echo htmlspecialchars((string)($villa['amenities'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                data-img="<?php echo htmlspecialchars($villa['image'], ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($villa['name']); ?> (Day ₱<?php echo number_format((float)$villa['base_rate']); ?> · Overnight ₱<?php echo number_format((float)$villa['overnight_rate']); ?>)
            </option>
            <?php endforeach; ?>
        </select>
        <small class="capacity-note" id="villa-capacity-note">Select a villa to view its configured capacity.</small>
    </div>

    <div class="inclusions-card venue-information-card" id="villa-venue-information">
        <div class="inc-col">
            <h4>Villa Information</h4>
            <p id="villa-description">Select a villa to view its description.</p>
        </div>
        <div class="inc-col">
            <h4>Amenities</h4>
            <ul id="villa-amenities" aria-live="polite"><li class="amenities-empty">Select a villa to view its amenities.</li></ul>
        </div>
        <div class="venue-facts-grid" aria-label="Villa details">
            <div class="venue-fact"><span class="fact-label">Base pax</span><strong id="villa-base-capacity">—</strong></div>
            <div class="venue-fact"><span class="fact-label">Maximum pax</span><strong id="villa-max-capacity">—</strong></div>
            <div class="venue-fact"><span class="fact-label">Extra pax rate</span><strong id="villa-extra-rate-fact">—</strong></div>
            <div class="venue-fact"><span class="fact-label">Private pool</span><strong id="villa-private-pool">—</strong></div>
        </div>
        <div class="villa-stay-panel">
            <div class="villa-stay-heading">
                <h4>Choose your stay</h4>
                <p>Day and overnight rates are separate total rates.</p>
            </div>
            <div class="villa-stay-options">
                <label class="villa-stay-card selected">
                    <input type="radio" name="villa-stay" id="stay-day" value="Day Time Stay" checked>
                    <span class="villa-stay-copy"><strong>Day Time Stay</strong><small id="stay-day-details">Select a villa to view rate and hours.</small><span class="villa-inclusion-list" id="stay-day-inclusions"></span></span>
                </label>
                <label class="villa-stay-card">
                    <input type="radio" name="villa-stay" id="stay-night" value="Overnight">
                    <span class="villa-stay-copy"><strong>Overnight</strong><small id="stay-night-details">Select a villa to view rate and hours.</small><span class="villa-inclusion-list" id="stay-night-inclusions"></span></span>
                </label>
            </div>
        </div>
    </div>

    <div style="margin-top: 2rem; margin-bottom: 2rem;">
        <label class="small-label">SELECT YOUR DATES</label>
        <p class="villa-calendar-help" id="villa-calendar-help">Day Time Stay: one calendar date.</p>
        <?php
        $calendarId = 'cal-ui-villa';
        include 'includes/partials/booking_calendar.php';
        ?>
    </div>

    <div class="form-group">
        <label>Number of Guests</label>
        <input type="number" id="villa-guests" min="1" max="1" value="1">
        <small class="capacity-note" id="villa-capacity-note-guest">Maximum capacity appears after selecting a villa.</small>
        <small class="extra-pax-note">Additional <span id="villa-extra-rate">configured rate</span> per head exceeding base capacity. <span id="villa-extra-fee"></span></small>
    </div>

    <div class="form-group">
        <label class="small-label">PAYMENT SCHEME</label>
        <div class="radio-group">
            <label><input type="radio" name="villa-payment" value="100% Full" checked> 100% Full</label>
            <label><input type="radio" name="villa-payment" value="50% Downpayment"> 50% Downpayment</label>
            <label><input type="radio" name="villa-payment" value="20% Reservation"> 20% Reservation</label>
        </div>
    </div>
</div>
