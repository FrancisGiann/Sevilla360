<?php
$page_title = 'Book Your Stay - SEVILLA360';
$extra_css = 'assets/css/booking.css?v=' . time(); 
$extra_js = 'assets/js/booking.js?v=' . time();    
$active_page = 'booking';              
$required_role = 'customer';
require 'includes/auth_guard.php';

include 'includes/header.php';

require_once 'config/db_connect.php';
require_once 'includes/media_helper.php';
$phone_stmt = $conn->prepare("SELECT phone FROM customers WHERE user_id = ? LIMIT 1");
$phone_stmt->bind_param('i', $_SESSION['user_id']);
$phone_stmt->execute();
$saved_contact_phone = (string)($phone_stmt->get_result()->fetch_assoc()['phone'] ?? '');
$phone_stmt->close();

// Fetch Event Halls with CMS image
$halls_query = $conn->query("SELECT v.id, v.name, v.description, v.amenities, e.base_rate, e.capacity_theater, e.capacity_classroom, e.capacity_banquet FROM venues v JOIN event_halls e ON v.id = e.venue_id WHERE v.status = 'Available'");
$event_halls = $halls_query->fetch_all(MYSQLI_ASSOC);
foreach ($event_halls as &$hall) {
    $hall['image'] = get_venue_image($conn, $hall['name']);
}
unset($hall);

// Fetch Hotel Rooms — individual physical rooms grouped by (room_type => [building_name + room details])
// Each room carries its specific venue_id so the booking flow books the exact physical room.
$rooms_query = $conn->query("
    SELECT 
        h.room_type, 
        v.name AS building_name,
        h.base_capacity,
        h.max_capacity,
        h.nightly_rate,
        h.extra_pax_rate,
        v.description AS venue_description,
        v.amenities AS venue_amenities,
        COUNT(v.id) AS total_inventory
    FROM venues v 
    JOIN hotel_rooms h ON v.id = h.venue_id 
    WHERE v.status = 'Available'
    GROUP BY h.room_type, v.name, h.base_capacity, h.max_capacity, h.nightly_rate, h.extra_pax_rate, v.description, v.amenities
    ORDER BY h.room_type, v.name
");
$hotel_rooms_flat = $rooms_query->fetch_all(MYSQLI_ASSOC);

// Group by room_type for the first dropdown.
$grouped_hotel_rooms = [];
$room_img_cache = [];
foreach ($hotel_rooms_flat as &$room) {
    $img_key = $room['building_name'] . ' - ' . $room['room_type'];
    if (!isset($room_img_cache[$img_key])) {
        $room_img_cache[$img_key] = get_venue_image($conn, $img_key);
    }
    $room['image'] = $room_img_cache[$img_key];
    $grouped_hotel_rooms[$room['room_type']][] = $room;
}
unset($room);

// Fetch hotel room groups for add-on panel (distinct building+type combos with rate/capacity/count)
$room_groups_query = $conn->query("
    SELECT 
        v.name AS building_name,
        h.room_type,
        h.nightly_rate,
        h.base_capacity,
        COUNT(v.id) AS total_inventory
    FROM venues v
    JOIN hotel_rooms h ON v.id = h.venue_id
    WHERE v.status = 'Available'
    GROUP BY v.name, h.room_type, h.nightly_rate, h.base_capacity
    ORDER BY v.name, h.room_type
");
$hotel_room_groups = $room_groups_query->fetch_all(MYSQLI_ASSOC);
foreach ($hotel_room_groups as &$grp) {
    $img_key = $grp['building_name'] . ' - ' . $grp['room_type'];
    $grp['image'] = $room_img_cache[$img_key] ?? get_venue_image($conn, $img_key);
}
unset($grp);

// Fetch Villas with CMS image
$villas_query = $conn->query("SELECT v.id, v.name, v.description, v.amenities, vi.day_rate AS base_rate, vi.overnight_rate, vi.base_capacity, vi.max_capacity, vi.extra_pax_rate FROM venues v JOIN villas vi ON v.id = vi.venue_id WHERE v.status = 'Available'");
$villas = $villas_query->fetch_all(MYSQLI_ASSOC);
foreach ($villas as &$villa) {
    $villa['image'] = get_venue_image($conn, $villa['name']);
}
unset($villa);
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
                    <p><strong>Operating Hours:</strong> <span class="sum-val">Per Event Schedule</span></p>
                    <p><strong>Guests:</strong> <span class="sum-val" id="sum-ev-guests">--</span></p>
                    <p><strong>Payment:</strong> <span class="sum-val" id="sum-ev-payment">To Be Arranged</span></p>
                    <p id="event-bundle-estimate" style="display:none; color:var(--color-gold);" aria-live="polite"><strong>Bundle:</strong> Estimated — final quote after resort review (<span id="event-bundle-estimate-amount">₱0.00</span> discount)</p>
                    <div class="estimate-card" id="event-estimate-card" role="status" aria-live="polite">
                        <strong>Estimated total</strong>
                        <span id="event-estimate-total">₱0.00</span>
                        <small>Estimate only; final event quotation is subject to resort confirmation.</small>
                    </div>
                </div>

                <div class="summary-container" id="sum-hotel-rooms">
                    <p><strong>Service:</strong> <span class="sum-val">Hotel Room</span></p>
                    <p><strong>Room Category:</strong> <span class="sum-val" id="sum-ht-type">--</span></p>
                    <p><strong>Room:</strong> <span class="sum-val" id="sum-ht-room">--</span></p>
                    <p><strong>Dates:</strong> <span class="sum-val sum-dates-display">--</span></p>
                    <p><strong>Check-in:</strong> <span class="sum-val" style="color:var(--color-gold);">2:00 PM</span>
                    </p>
                    <p><strong>Check-out:</strong> <span class="sum-val" style="color:var(--color-gold);">12:00
                            PM</span></p>
                    <p><strong>Guests:</strong> <span class="sum-val" id="sum-ht-guests">2</span></p>
                    <p><strong>Extra Pax:</strong> <span class="sum-val" id="sum-ht-fee">₱0</span></p>
                    <p><strong>Payment:</strong> <span class="sum-val" id="sum-ht-payment">100% Full</span></p>
                </div>

                <div class="summary-container" id="sum-resort-villa">
                    <p><strong>Service:</strong> <span class="sum-val">Resort Villa</span></p>
                    <p><strong>Villa:</strong> <span class="sum-val" id="sum-vl-type">La Casita (Poolside)</span></p>
                    <p><strong>Stay:</strong> <span class="sum-val" id="sum-vl-stay">Day Time Stay</span></p>
                    <p><strong>Dates:</strong> <span class="sum-val sum-dates-display">--</span></p>
                    <p><strong>Check-in:</strong> <span class="sum-val" id="sum-vl-in"
                            style="color:var(--color-gold);">7:00 AM</span></p>
                    <p><strong>Check-out:</strong> <span class="sum-val" id="sum-vl-out"
                            style="color:var(--color-gold);">5:00 PM</span></p>
                    <p><strong>Guests:</strong> <span class="sum-val" id="sum-vl-guests">4</span></p>
                    <p><strong>Extra Pax:</strong> <span class="sum-val" id="sum-vl-fee">₱0</span></p>
                    <p><strong>Payment:</strong> <span class="sum-val" id="sum-vl-payment">100% Full</span></p>
                </div>

                <!-- DYNAMIC PRICING SECTION (Hidden for Events) -->
                <div id="pricing-section">
                    <div id="summary-breakdown"
                        style="margin-top: 15px; border-top: 1px dashed #ccc; padding-top: 15px;"></div>

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
                </div>

                <!-- Universal Summary Footer -->
                <div class="summary-footer"
                    style="margin-top: 25px; border-top: 1px solid rgba(0,0,0,0.1); padding-top: 20px;">

                    <!-- Contact Number -->
                    <div style="margin-bottom: 15px;">
                        <label
                            style="display:block; font-weight:600; font-size:0.9rem; margin-bottom:8px; color:var(--color-dark);">Best
                            Contact Number</label>
                        <?php if ($saved_contact_phone !== ''): ?><div class="contact-number-options"><label><input type="radio" name="contact-phone-choice" value="saved" checked> Use my saved number <strong><?php echo htmlspecialchars($saved_contact_phone); ?></strong></label><label><input type="radio" name="contact-phone-choice" value="alternate"> Use a different number</label></div><?php endif; ?>
                        <input type="tel" id="contact-phone" value="<?php echo htmlspecialchars($saved_contact_phone); ?>" placeholder="e.g. 09123456789" autocomplete="tel" inputmode="tel"
                            style="width:100%; padding:10px; border-radius:4px; border:1px solid rgba(0,0,0,0.15); font-family:var(--font-body);">
                        <label class="save-contact-choice <?php echo $saved_contact_phone !== '' ? 'hidden' : ''; ?>" id="save-contact-choice"><input type="checkbox" id="save-contact-default"> Save this as my default number</label>
                        <small style="color: #888; display: block; margin-top: 5px;">We will call this number to confirm
                            your booking.</small>
                    </div>

                    <!-- Additional Notes Input -->
                    <div style="margin-bottom: 20px;">
                        <label
                            style="display:block; font-weight:600; font-size:0.9rem; margin-bottom:8px; color:var(--color-dark);">Special
                            Requests / Notes (Optional)</label>
                        <textarea id="booking-notes" rows="3"
                            placeholder="Allergies, early check-in requests, or specific event instructions..."
                            style="width:100%; padding:10px; border-radius:4px; border:1px solid rgba(0,0,0,0.15); font-family:var(--font-body); resize:vertical;"></textarea>
                    </div>

                    <!-- Lock Timer -->
                    <div class="timer-box" id="timer-box">
                        <span id="timer-text">Select your dates to book.</span>
                        <span id="countdown-wrapper" style="display: none;">Session expires in: <span
                                id="countdown">30:00</span></span>
                    </div>

                    <div class="terms-group">
                        <input type="checkbox" id="terms-check" name="policy_consent" value="1">
                        <label for="terms-check">I agree to the <a href="#" id="open-terms">Terms &
                                Conditions</a></label>
                    </div>

                    <!-- Action Buttons -->
                    <button class="btn btn-paymongo" id="btn-proceed">PROCEED TO PAYMENT</button>
                    <button class="btn btn-cancel" id="btn-cancel">CANCEL</button>
                </div>
            </div>
        </div>

    </div>
</section>

<!-- INJECT THE MODALS -->
<?php include 'includes/partials/booking_modals.php'; ?>

<?php include 'includes/footer.php'; ?>
