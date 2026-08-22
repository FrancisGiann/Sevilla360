<!-- ADMIN HOTEL ROOMS TAB -->
<div class="tab-content" id="tab-hotel">

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
            <label>Select Specific Room</label>
            <select id="hotel-room-name" disabled>
                <option value="" disabled selected>Select category first...</option>
            </select>
        </div>
    </div>

    <!-- 2. WHEN: CALENDAR UI (STRICTLY ONE INSTANCE) -->
    <div style="margin-top: 2rem; margin-bottom: 2rem;">
        <label class="small-label">SELECT BOOKING DATES</label>
        <?php
        $calendarId = 'cal-ui-hotel';
        include 'includes/partials/booking_calendar.php';
        ?>
        <p class="hotel-calendar-help">Hotel rooms are booked per night. A booked arrival date may still be selected as the checkout boundary.</p>
    </div>

    <!-- 3. WHO: GUESTS -->
    <div class="form-group">
        <label>Number of Guests</label>
        <input type="number" id="hotel-guests" min="1" max="4" value="2">
        <small class="extra-pax-note">Additional charge per head exceeding base capacity. <span
                id="hotel-extra-fee"></span></small>
    </div>

    <div class="inclusions-card hotel-information-card">
        <div class="inc-col">
            <h4>Accommodation Information</h4>
            <p id="hotel-description">Select a room to view its description.</p>
        </div>
        <div class="inc-col">
            <h4>Amenities</h4>
            <ul id="hotel-amenities">
                <li>Select a room to view its amenities.</li>
            </ul>
        </div>
    </div>

    <!-- Inject the PHP Data for Javascript cascading dropdown (individual rooms) -->
    <script>
    window.hotelRoomData = <?php echo json_encode($grouped_hotel_rooms); ?>;
    </script>
</div>
