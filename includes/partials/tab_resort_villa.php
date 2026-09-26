<!-- RESORT VILLA TAB -->
<div class="tab-content" id="tab-resort-villa" role="tabpanel" aria-labelledby="booking-tab-resort-villa" tabindex="0" aria-hidden="true">
    <h2 class="section-title">Reserve a Resort Villa</h2>

    <section class="booking-step-panel" data-booking-step-panel="1" aria-labelledby="villa-step-choose" hidden>
        <h3 class="booking-step-heading" id="villa-step-choose" tabindex="-1">Choose a villa and stay</h3>
        <p class="booking-step-intro">Day and overnight stays use separate rates. Choose your stay to see the hours and inclusions.</p>
        <div class="form-group">
            <label for="villa-type">Select Villa</label>
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
                    <?php echo htmlspecialchars($villa['name']); ?>
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

        <div class="dynamic-img-wrapper booking-venue-image" id="villa-image-panel" hidden>
            <img id="villa-img" alt="" hidden>
            <p class="booking-image-placeholder" id="villa-image-placeholder" role="status" hidden></p>
        </div>
        <div class="booking-step-actions booking-step-actions-end">
            <button type="button" class="btn booking-step-next" data-booking-step-next="2">Continue to dates</button>
        </div>
    </section>

    <section class="booking-step-panel" data-booking-step-panel="2" aria-labelledby="villa-step-dates" hidden>
        <h3 class="booking-step-heading" id="villa-step-dates" tabindex="-1">Choose your dates</h3>
        <div class="booking-date-control">
            <p class="small-label">SELECT YOUR DATES</p>
            <p class="booking-inline-note villa-calendar-help" id="villa-calendar-help">Day Time Stay: one calendar date. Overnight: choose check-in and checkout dates; the entire range is reserved.</p>
            <p class="booking-inline-note villa-breakfast-schedule" id="villa-breakfast-schedule" hidden></p>
            <?php $calendarId = 'cal-ui-villa'; include 'includes/partials/booking_calendar.php'; ?>
        </div>
        <div class="form-group">
            <label for="villa-guests">Number of Guests</label>
            <input type="number" id="villa-guests" min="1" max="1" value="1" step="1">
            <small class="capacity-note" id="villa-capacity-note-guest">Maximum capacity appears after selecting a villa.</small>
            <small class="extra-pax-note">Additional <span id="villa-extra-rate">configured rate</span> per head exceeding base capacity. <span id="villa-extra-fee"></span></small>
        </div>
        <div class="booking-step-actions">
            <button type="button" class="btn booking-step-back" data-booking-step-back="1">Back</button>
            <button type="button" class="btn booking-step-next" data-booking-step-next="3">Continue to options</button>
        </div>
    </section>

    <section class="booking-step-panel" data-booking-step-panel="3" aria-labelledby="villa-step-options" hidden>
        <h3 class="booking-step-heading" id="villa-step-options" tabindex="-1">Choose a payment scheme</h3>
        <p class="booking-step-intro">Choose how much to pay when you reserve this villa.</p>
        <div class="form-group">
            <p class="small-label" id="villa-payment-label">PAYMENT SCHEME</p>
            <div class="radio-group booking-choice-grid payment-choice-grid" role="radiogroup" aria-labelledby="villa-payment-label">
                <label class="booking-choice-tile"><input type="radio" name="villa-payment" value="100% Full" checked> 100% Full</label>
                <label class="booking-choice-tile"><input type="radio" name="villa-payment" value="50% Downpayment"> 50% Downpayment</label>
                <label class="booking-choice-tile"><input type="radio" name="villa-payment" value="20% Reservation"> 20% Reservation</label>
            </div>
        </div>
        <div class="booking-step-actions">
            <button type="button" class="btn booking-step-back" data-booking-step-back="2">Back</button>
            <button type="button" class="btn booking-step-next" data-booking-step-next="4">Review reservation</button>
        </div>
    </section>

    <section class="booking-step-panel booking-review-panel" data-booking-step-panel="4" aria-labelledby="villa-step-review" hidden>
        <h3 class="booking-step-heading" id="villa-step-review" tabindex="-1">Review your villa reservation</h3>
        <p>Customers can place a 15-minute hold after confirming dates. Guests confirm dates here and sign in when submitting. After reservation, the secure payment window provides manual payment instructions.</p>
        <div class="booking-step-actions">
            <button type="button" class="btn booking-step-back" data-booking-step-back="3">Back to options</button>
        </div>
    </section>
</div>
