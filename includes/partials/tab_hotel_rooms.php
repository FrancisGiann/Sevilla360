<!-- HOTEL ROOMS TAB -->
<div class="tab-content" id="tab-hotel-rooms">
    <h2 class="section-title">Book a Hotel Room</h2>

    <!--- CALENDAR UI -->
    <?php
    $calendarId = 'cal-ui-hotel';
    include 'includes/partials/booking_calendar.php';
    ?>

    <div class="dynamic-img-wrapper">
        <img id="hotel-img"
            src="https://images.unsplash.com/photo-1611892440504-42a792e24d32?auto=format&fit=crop&w=800"
            alt="Hotel Room">
    </div>

    <!-- DYNAMIC DATABASE DROPDOWNS -->
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

    <div class="form-group">
        <label>Number of Guests</label>
        <input type="number" id="hotel-guests" min="1" max="4" value="2">
        <small class="extra-pax-note">Additional ₱800 per head exceeding base capacity. <span
                id="hotel-extra-fee"></span></small>
    </div>

    <div class="inclusions-card">
        <div class="inc-col">
            <h4>Room Features</h4>
            <ul>
                <li>King-sized Bed</li>
                <li>En-suite Bathroom</li>
                <li>Smart TV & Wi-Fi</li>
            </ul>
        </div>
        <div class="inc-col">
            <h4>Resort Perks</h4>
            <ul>
                <li>Free Breakfast for 2</li>
                <li>Pool Access</li>
                <li>Gym Access</li>
            </ul>
        </div>
    </div>

    <!-- PAYMENT SCHEME IS BACK! -->
    <div class="form-group">
        <label class="small-label">PAYMENT SCHEME</label>
        <div class="radio-group">
            <label><input type="radio" name="hotel-payment" value="100% Full" checked> 100% Full</label>
            <label><input type="radio" name="hotel-payment" value="50% Downpayment"> 50% Downpayment</label>
            <label><input type="radio" name="hotel-payment" value="20% Reservation"> 20% Reservation</label>
        </div>
    </div>

    <!-- Inject the PHP Data for Javascript -->
    <script>
    window.hotelRoomData = <?php echo json_encode($grouped_hotel_rooms); ?>;
    </script>
</div>