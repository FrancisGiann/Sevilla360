<!-- HOTEL ROOMS TAB -->
<div class="tab-content" id="tab-hotel-rooms" role="tabpanel" aria-labelledby="booking-tab-hotel-rooms" tabindex="0" aria-hidden="true">
    <h2 class="section-title">Book a Hotel Room</h2>

    <section class="booking-step-panel" data-booking-step-panel="1" aria-labelledby="hotel-step-choose" hidden>
        <h3 class="booking-step-heading" id="hotel-step-choose" tabindex="-1">Choose a room</h3>
        <p class="booking-step-intro">Select a room category and building to see its amenities and nightly rate.</p>
        <div class="form-row">
            <div class="form-group">
                <label for="hotel-room-type">Select Room Category</label>
                <select id="hotel-room-type">
                    <option value="" disabled selected>Select category...</option>
                    <?php foreach(array_keys($grouped_hotel_rooms) as $type): ?>
                    <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="hotel-room-name">Select Building</label>
                <select id="hotel-room-name" disabled>
                    <option value="" disabled selected>Select category first...</option>
                </select>
            </div>
        </div>

        <div class="inclusions-card hotel-information-card">
            <div class="inc-col">
                <h4>Accommodation Information</h4>
                <p id="hotel-description">Select a building to view its description.</p>
            </div>
            <div class="inc-col">
                <h4>Amenities</h4>
                <ul id="hotel-amenities" aria-live="polite">
                    <li class="amenities-empty">Select a building to view its amenities.</li>
                </ul>
            </div>
            <div class="venue-facts-grid hotel-facts-grid" aria-label="Room details">
                <div class="venue-fact"><span class="fact-label">Base pax</span><strong id="hotel-base-capacity">—</strong></div>
                <div class="venue-fact"><span class="fact-label">Maximum pax</span><strong id="hotel-max-capacity">—</strong></div>
                <div class="venue-fact"><span class="fact-label">Beds</span><strong id="hotel-bed-count">—</strong></div>
                <div class="venue-fact"><span class="fact-label">Nightly rate</span><strong id="hotel-nightly-rate">—</strong></div>
                <div class="venue-fact"><span class="fact-label">Extra pax rate</span><strong id="hotel-extra-rate-fact">—</strong></div>
                <div class="venue-fact"><span class="fact-label">Check-in / out</span><strong id="hotel-check-times">—</strong></div>
            </div>
        </div>
        <div class="dynamic-img-wrapper booking-venue-image hotel-image-panel" id="hotel-image-panel" hidden>
            <img id="hotel-img" alt="" hidden>
            <p class="booking-image-placeholder" id="hotel-image-placeholder" role="status" hidden></p>
        </div>
        <div class="booking-step-actions booking-step-actions-end">
            <button type="button" class="btn booking-step-next" data-booking-step-next="2">Continue to dates</button>
        </div>
    </section>

    <section class="booking-step-panel" data-booking-step-panel="2" aria-labelledby="hotel-step-dates" hidden>
        <h3 class="booking-step-heading" id="hotel-step-dates" tabindex="-1">Choose check-in and check-out</h3>
        <div class="booking-date-control">
            <p class="small-label">SELECT YOUR DATES</p>
            <?php $calendarId = 'cal-ui-hotel'; include 'includes/partials/booking_calendar.php'; ?>
            <p class="booking-inline-note hotel-calendar-help">Hotel rooms are booked per night. A booked arrival date can still be selected as your checkout boundary after choosing an available check-in date.</p>
        </div>
        <div class="form-group">
            <label for="hotel-guests">Number of Guests</label>
            <input type="number" id="hotel-guests" min="1" max="1" value="1" step="1">
            <small class="capacity-note" id="hotel-capacity-note">Select a building to see its maximum capacity.</small>
            <small class="extra-pax-note">Additional charge per head exceeding base capacity. <span id="hotel-extra-fee"></span></small>
        </div>
        <div class="booking-step-actions">
            <button type="button" class="btn booking-step-back" data-booking-step-back="1">Back</button>
            <button type="button" class="btn booking-step-next" data-booking-step-next="3">Continue to options</button>
        </div>
    </section>

    <section class="booking-step-panel" data-booking-step-panel="3" aria-labelledby="hotel-step-options" hidden>
        <h3 class="booking-step-heading" id="hotel-step-options" tabindex="-1">Choose a payment scheme</h3>
        <p class="booking-step-intro">Choose how much to pay when you reserve this room.</p>
        <div class="form-group">
            <p class="small-label" id="hotel-payment-label">PAYMENT SCHEME</p>
            <div class="radio-group booking-choice-grid payment-choice-grid" role="radiogroup" aria-labelledby="hotel-payment-label">
                <label class="booking-choice-tile"><input type="radio" name="hotel-payment" value="100% Full" checked> 100% Full</label>
                <label class="booking-choice-tile"><input type="radio" name="hotel-payment" value="50% Downpayment"> 50% Downpayment</label>
                <label class="booking-choice-tile"><input type="radio" name="hotel-payment" value="20% Reservation"> 20% Reservation</label>
            </div>
        </div>
        <div class="booking-step-actions">
            <button type="button" class="btn booking-step-back" data-booking-step-back="2">Back</button>
            <button type="button" class="btn booking-step-next" data-booking-step-next="4">Review reservation</button>
        </div>
    </section>

    <section class="booking-step-panel booking-review-panel" data-booking-step-panel="4" aria-labelledby="hotel-step-review" hidden>
        <h3 class="booking-step-heading" id="hotel-step-review" tabindex="-1">Review your room reservation</h3>
        <p>Customers can place a 15-minute hold after confirming dates. Guests confirm dates here and sign in when submitting. After reservation, the secure payment window provides manual payment instructions.</p>
        <div class="booking-step-actions">
            <button type="button" class="btn booking-step-back" data-booking-step-back="3">Back to options</button>
        </div>
    </section>

    <!-- Inject the PHP Data for Javascript (individual rooms per category group) -->
    <script>
    window.hotelRoomData = <?php echo json_encode($grouped_hotel_rooms, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>
</div>
