<?php
require_once 'config/db_connect.php';

// fetch event halls
$halls_query = $conn->query("SELECT v.id, v.name, e.base_rate FROM venues v JOIN event_halls e ON v.id = e.venue_id WHERE v.status = 'Available'");
$event_halls = $halls_query->fetch_all(MYSQLI_ASSOC);

// Fetch Hotel Rooms (Grouped by Type AND Name)
$rooms_query = $conn->query("
    SELECT 
        h.room_type, 
        v.name AS building_name, 
        h.base_capacity, 
        h.nightly_rate AS base_rate, 
        COUNT(v.id) as total_units 
    FROM venues v 
    JOIN hotel_rooms h ON v.id = h.venue_id 
    WHERE v.status = 'Available'
    GROUP BY h.room_type, v.name, h.base_capacity, h.nightly_rate
    ORDER BY h.room_type, v.name
");
$hotel_rooms_flat = $rooms_query->fetch_all(MYSQLI_ASSOC);

// Group them by Room Type so Javascript can easily filter them!
$grouped_hotel_rooms = [];
foreach ($hotel_rooms_flat as $room) {
    $grouped_hotel_rooms[$room['room_type']][] = $room;
}

// fetch villas
$villas_query = $conn->query("SELECT v.id, v.name, vi.day_rate AS base_rate FROM venues v JOIN villas vi ON v.id = vi.venue_id WHERE v.status = 'Available'");
$villas = $villas_query->fetch_all(MYSQLI_ASSOC);
?>
<!-- Expanded, Larger Booking Container -->
<div class="admin-booking-container">

    <!-- NEW CONSISTENT HEADER -->
    <div class="walkin-header">
        <p class="walkin-subtitle">MANAGE DIRECT BOOKINGS AND RESERVATIONS</p>
    </div>

    <!-- Guest Information -->
    <section class="booking-card">
        <h3 class="card-title">1. Guest Information</h3>
        <div class="form-row">
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" id="guest-name" placeholder="Enter guest's full name">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Contact Number</label>
                <input type="text" id="guest-phone" placeholder="e.g. 09123456789">
            </div>
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" id="guest-email" placeholder="Enter guest's email">
            </div>
        </div>
    </section>

    <!-- Booking Details & Tabs -->
    <section class="booking-card">
        <h3 class="card-title">2. Venue & Accommodation</h3>

        <div class="booking-tabs">
            <button class="tab-btn active" data-target="tab-event">Event Hall</button>
            <button class="tab-btn" data-target="tab-hotel">Hotel Rooms</button>
            <button class="tab-btn" data-target="tab-villa">Resort Villa</button>
        </div>

        <!-- TABS-->
        <?php include 'includes/partials/admin-page/admin_tab_event.php'; ?>
        <?php include 'includes/partials/admin-page/admin_tab_hotel.php'; ?>
        <?php include 'includes/partials/admin-page/admin_tab_villa.php'; ?>


    </section>

    <!-- Payment & Checkout -->
    <section class="booking-card">
        <h3 class="card-title">3. Payment & Checkout</h3>

        <div class="form-row">
            <div class="form-group">
                <label>Payment Scheme</label>
                <select id="payment-scheme">
                    <option value="1">Full Payment (100%)</option>
                    <option value="0.5">Down Payment (50%)</option>
                    <option value="0.2">Reservation Fee (20%)</option>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label>Payment Method</label>
            <div class="radio-group-inline mt-10">
                <label><input type="radio" name="payment-method" value="cash" checked> Cash</label>
                <label><input type="radio" name="payment-method" value="gcash"> GCash</label>
                <label><input type="radio" name="payment-method" value="maya"> Maya</label>
                <label><input type="radio" name="payment-method" value="bank"> Bank Transfer</label>
            </div>
        </div>

        <div class="form-group hidden" id="transaction-wrapper">
            <label>Reference / Transaction ID</label>
            <input type="text" id="transaction-id" placeholder="Enter transaction or reference number">
        </div>

        <!-- Booking Summary Box -->
        <div class="checkout-summary">
            <h4 class="summary-title">Booking Summary</h4>
            <div class="summary-row">
                <span>Selected Dates:</span>
                <span id="summary-dates" class="selected-date-text">Please select dates</span>
            </div>
            <div id="summary-breakdown"></div>
            <div class="summary-total">
                <span>Total Amount</span>
                <span id="summary-total-val" class="color-gold">₱0.00</span>
            </div>
            <div class="summary-total payable">
                <span>Amount Due Now</span>
                <span id="summary-due-val">₱0.00</span>
            </div>
        </div>

        <div class="action-buttons">
            <button type="submit" class="btn-confirm-walkin">CONFIRM WALK-IN BOOKING</button>
        </div>
        <div class="action-buttons">
            <button type="button" class="btn-cancel-walkin">CANCEL</button>
        </div>
        <div class="form-note">
            <p><strong>Note:</strong> Confirming this booking will mark the selected dates as reserved and
                unavailable for other bookings. Please ensure all details are correct before proceeding.</p>
        </div>
    </section>
</div>

<!-- ADMIN MODALS HERE -->
<?php include 'includes/partials/admin-page/admin_walkin_modals.php'; ?>