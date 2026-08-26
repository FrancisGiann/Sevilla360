<!-- HOTEL ROOMS TAB -->
<div class="tab-content" id="tab-hotel-rooms">
    <h2 class="section-title">Book a Hotel Room</h2>

    <div class="dynamic-img-wrapper">
        <!-- src set dynamically by JS from data-img on selected option -->
        <img id="hotel-img" src="assets/img/placeholder.jpg" alt="Hotel Room">
    </div>

    <!-- 1. WHAT: DYNAMIC DATABASE DROPDOWNS -->
    <div class="form-row">
        <div class="form-group">
            <label>Select Room Category</label>
            <select id="hotel-room-type">
                <option value="" disabled selected>Select category...</option>
                <?php foreach(array_keys($grouped_hotel_rooms) as $type): ?>
                <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Select Building</label>
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

    <!-- 2. WHEN: CALENDAR UI -->
    <div style="margin-top: 2rem; margin-bottom: 2rem;">
        <label class="small-label">SELECT YOUR DATES</label>
        <?php
        $calendarId = 'cal-ui-hotel';
        include 'includes/partials/booking_calendar.php';
        ?>
        <p class="hotel-calendar-help">Hotel rooms are booked per night. A booked arrival date may still be selected as your checkout boundary after choosing an available check-in date.</p>
    </div>

    <!-- 3. WHO & EXTRAS: GUESTS -->
    <div class="form-group">
        <label>Number of Guests</label>
        <input type="number" id="hotel-guests" min="1" max="1" value="1">
        <small class="capacity-note" id="hotel-capacity-note">Select a building to see its maximum capacity.</small>
        <small class="extra-pax-note">Additional charge per head exceeding base capacity. <span
                id="hotel-extra-fee"></span></small>
    </div>

    <!-- 4. PAYMENT SCHEME -->
    <div class="form-group">
        <label class="small-label">PAYMENT SCHEME</label>
        <div class="radio-group">
            <label><input type="radio" name="hotel-payment" value="100% Full" checked> 100% Full</label>
            <label><input type="radio" name="hotel-payment" value="50% Downpayment"> 50% Downpayment</label>
            <label><input type="radio" name="hotel-payment" value="20% Reservation"> 20% Reservation</label>
        </div>
    </div>

    <!-- Inject the PHP Data for Javascript (individual rooms per category group) -->
    <script>
    window.hotelRoomData = <?php echo json_encode($grouped_hotel_rooms, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>
</div>
