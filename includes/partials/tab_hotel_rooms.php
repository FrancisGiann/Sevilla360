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

    <!-- 2. WHEN: CALENDAR UI (MOVED TO MIDDLE) -->
    <div style="margin-top: 2rem; margin-bottom: 2rem;">
        <label class="small-label">SELECT YOUR DATES</label>
        <?php
        $calendarId = 'cal-ui-hotel';
        include 'includes/partials/booking_calendar.php';
        ?>
        <p class="hotel-calendar-help">Hotel rooms are booked per night. A booked arrival date may still be selected as your checkout boundary after choosing an available check-in date.</p>
    </div>

    <!-- 3. WHO & EXTRAS: GUESTS AND INCLUSIONS -->
    <div class="form-group">
        <label>Number of Guests</label>
        <input type="number" id="hotel-guests" min="1" max="1" value="1">
        <small class="extra-pax-note">Additional charge per head exceeding base capacity. <span
                id="hotel-extra-fee"></span></small>
    </div>

    <div class="inclusions-card hotel-information-card">
        <div class="inc-col">
            <h4>Accommodation Information</h4>
            <p id="hotel-description">Select a building to view its description.</p>
        </div>
        <div class="inc-col">
            <h4>Amenities</h4>
            <ul id="hotel-amenities">
                <li>Select a building to view its amenities.</li>
            </ul>
        </div>
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
    window.hotelRoomData = <?php echo json_encode($grouped_hotel_rooms); ?>;
    </script>
</div>
