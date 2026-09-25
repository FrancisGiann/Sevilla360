<!-- ADMIN HOTEL ROOMS TAB -->
<div class="tab-content" id="tab-hotel" role="tabpanel" aria-labelledby="walkin-tab-hotel" aria-hidden="true" tabindex="0">

    <div class="form-row venue-picker-row">
        <div class="form-group venue-picker-group">
            <label for="hotel-room-type">Select room category</label>
            <select id="hotel-room-type">
                <option value="" disabled selected>Select category...</option>
                <?php foreach(array_keys($grouped_hotel_rooms) as $type): ?>
                <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group venue-picker-group">
            <label for="hotel-room-name">Select building</label>
            <select id="hotel-room-name" disabled>
                <option value="" disabled selected>Select category first...</option>
            </select>
        </div>
    </div>

    <div class="inclusions-card hotel-information-card">
        <div class="inc-col">
            <h4>Accommodation information</h4>
            <p id="hotel-description">Select a room to view its description.</p>
        </div>
        <div class="inc-col">
            <h4>Amenities</h4>
            <ul id="hotel-amenities" aria-live="polite">
                <li class="amenities-empty">Select a room to view its amenities.</li>
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

    <div class="dynamic-img-wrapper venue-image-frame" id="hotel-image-panel" hidden>
        <img id="hotel-img" alt="" hidden>
        <p class="venue-image-empty" role="status" hidden>Photo unavailable for this room.</p>
    </div>

    <div class="walkin-calendar-section">
        <p class="small-label">Select booking dates</p>
        <?php
        $calendarId = 'cal-ui-hotel';
        include 'includes/partials/booking_calendar.php';
        ?>
        <p class="hotel-calendar-help">Hotel rooms are booked per night. A booked arrival date may still be selected as the checkout boundary.</p>
    </div>

    <!-- 3. WHO: GUESTS -->
    <div class="form-group">
        <label for="hotel-guests">Number of guests</label>
        <input type="number" id="hotel-guests" min="1" max="1" value="1">
        <small class="capacity-note" id="hotel-capacity-note">Select a room to see its maximum capacity.</small>
        <small class="extra-pax-note">Additional charge per head exceeding base capacity. <span
                id="hotel-extra-fee"></span></small>
    </div>

    <!-- Inject the PHP Data for Javascript cascading dropdown (individual rooms) -->
    <script>
    window.hotelRoomData = <?php echo json_encode($grouped_hotel_rooms, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>
</div>
