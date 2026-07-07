<?php
$page_title = 'Book Your Stay - SEVILLA360';
$extra_css = 'assets/css/booking.css'; 
$extra_js = 'assets/js/booking.js?v=1.3';    
$active_page = 'booking';              

include 'includes/header.php';

require_once 'config/db_connect.php';

// Fetch Event Halls 
$halls_query = $conn->query("SELECT v.id, v.name, e.base_rate FROM venues v JOIN event_halls e ON v.id = e.venue_id WHERE v.status = 'Available'");
$event_halls = $halls_query->fetch_all(MYSQLI_ASSOC);

// Fetch Hotel Rooms (Grouped for Cascading Dropdown)
$rooms_query = $conn->query("
    SELECT h.room_type, v.name AS building_name, h.base_capacity, h.nightly_rate AS base_rate, COUNT(v.id) as total_units 
    FROM venues v JOIN hotel_rooms h ON v.id = h.venue_id 
    WHERE v.status = 'Available'
    GROUP BY h.room_type, v.name, h.base_capacity, h.nightly_rate
    ORDER BY h.room_type, v.name
");
$hotel_rooms_flat = $rooms_query->fetch_all(MYSQLI_ASSOC);
$grouped_hotel_rooms = [];
foreach ($hotel_rooms_flat as $room) {
    $grouped_hotel_rooms[$room['room_type']][] = $room;
}

// Fetch Villas 
$villas_query = $conn->query("SELECT v.id, v.name, vi.day_rate AS base_rate FROM venues v JOIN villas vi ON v.id = vi.venue_id WHERE v.status = 'Available'");
$villas = $villas_query->fetch_all(MYSQLI_ASSOC);
?>

<!-- Main Booking Section -->
<section class="booking-section">
    <div class="container booking-grid">

        <!-- LEFT COLUMN (65%) -->
        <div class="booking-main">

            <!-- Tab Navigation Buttons -->
            <div class="booking-tabs">
                <button class="tab-btn active" data-tab="event-hall">Event Hall</button>
                <button class="tab-btn" data-tab="hotel-rooms">Hotel Rooms</button>
                <button class="tab-btn" data-tab="resort-villa">Resort Villa</button>
            </div>

            <!-- INJECT THE TAB COMPONENTS -->
            <?php include 'includes/partials/tab_event_hall.php'; ?>
            <?php include 'includes/partials/tab_hotel_rooms.php'; ?>
            <?php include 'includes/partials/tab_resort_villa.php'; ?>

            <!----PAYMENT SCHEME---->


        </div>

        <!-- RIGHT COLUMN: STICKY SUMMARY (35%) -->
        <div class="booking-sidebar">
            <div class="sticky-summary">
                <h3
                    style="font-family: var(--font-heading); margin-bottom: 1.5rem; font-size: 1.6rem; border-bottom: 1px solid rgba(0,0,0,0.1); padding-bottom: 10px;">
                    Booking Summary
                </h3>

                <!-- Summary Containers -->
                <div class="summary-container active" id="sum-event-hall">
                    <p><strong>Service:</strong> <span class="sum-val">Event Hall</span></p>
                    <p><strong>Venue:</strong> <span class="sum-val" id="sum-ev-venue">Grand Ballroom</span></p>
                    <p><strong>Event Type:</strong> <span class="sum-val" id="sum-ev-type">Plain Hall</span></p>
                    <p><strong>Dates:</strong> <span class="sum-val sum-dates-display">--</span></p>
                    <p><strong>Guests:</strong> <span class="sum-val" id="sum-ev-guests">--</span></p>
                    <p><strong>Payment Scheme:</strong> <span class="sum-val" id="sum-ev-payment">100% Full</span>
                    </p>
                </div>

                <div class="summary-container" id="sum-hotel-rooms">
                    <p><strong>Service:</strong> <span class="sum-val">Hotel Room</span></p>
                    <p><strong>Room Type:</strong> <span class="sum-val" id="sum-ht-type">Deluxe Room</span></p>
                    <p><strong>Dates:</strong> <span class="sum-val sum-dates-display">--</span></p>
                    <p><strong>Guests:</strong> <span class="sum-val" id="sum-ht-guests">2</span></p>
                    <p><strong>Extra Pax Fee:</strong> <span class="sum-val" id="sum-ht-fee">₱0</span></p>
                    <p><strong>Payment Scheme:</strong> <span class="sum-val" id="sum-ht-payment">100% Full</span>
                    </p>
                </div>

                <div class="summary-container" id="sum-resort-villa">
                    <p><strong>Service:</strong> <span class="sum-val">Resort Villa</span></p>
                    <p><strong>Villa:</strong> <span class="sum-val" id="sum-vl-type">La Casita (Poolside)</span>
                    </p>
                    <p><strong>Stay:</strong> <span class="sum-val" id="sum-vl-stay">Day Time Stay</span></p>
                    <p><strong>Dates:</strong> <span class="sum-val sum-dates-display">--</span></p>
                    <p><strong>Guests:</strong> <span class="sum-val" id="sum-vl-guests">4</span></p>
                    <p><strong>Extra Pax Fee:</strong> <span class="sum-val" id="sum-vl-fee">₱0</span></p>
                    <p><strong>Payment Scheme:</strong> <span class="sum-val" id="sum-vl-payment">100% Full</span>
                    </p>
                </div>

                <div id="summary-breakdown" style="margin-top: 15px; border-top: 1px dashed #ccc; padding-top: 15px;">
                </div>
                <div class="summary-total"
                    style="display: flex; justify-content: space-between; font-weight: bold; font-size: 1.1rem; margin-top: 10px;">
                    <span>Total Amount</span>
                    <span id="summary-total-val" style="color: var(--color-gold);">₱0.00</span>
                </div>
                <div class="summary-total payable"
                    style="display: flex; justify-content: space-between; font-weight: bold; margin-top: 5px;">
                    <span>Amount Due Now</span>
                    <span id="summary-due-val">₱0.00</span>
                </div>

                <!-- Universal Summary Footer -->
                <div class="summary-footer">
                    <div class="timer-box" id="timer-box">
                        <span id="timer-text">Select your dates to book.</span>
                        <span id="countdown-wrapper" style="display: none;">Session expires in: <span
                                id="countdown">30:00</span></span>
                    </div>

                    <div class="terms-group">
                        <input type="checkbox" id="terms-check">
                        <label for="terms-check">I agree to the <a href="#" id="open-terms">Terms &
                                Conditions</a></label>
                    </div>

                    <button class="btn btn-paymongo" id="btn-proceed">PROCEED VIA PAYMONGO</button>
                    <button class="btn btn-cancel" id="btn-cancel">CANCEL</button>
                </div>
            </div>
        </div>

    </div>
</section>

<!-- INJECT THE MODALS -->
<?php include 'includes/partials/booking_modals.php'; ?>

<?php include 'includes/footer.php'; ?>