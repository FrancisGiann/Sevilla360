<?php
$page_title = 'Book Your Stay - SEVILLA360';
$extra_css = [
    'assets/css/booking.css?v=' . time(),
    'assets/css/manual_payment.css?v=' . filemtime(__DIR__ . '/assets/css/manual_payment.css'),
];
$extra_js = [
    'assets/js/manual_payment.js?v=' . filemtime(__DIR__ . '/assets/js/manual_payment.js'),
    'assets/js/booking.js?v=' . filemtime(__DIR__ . '/assets/js/booking.js'),
];
$active_page = 'booking';              
require_once 'includes/session_init.php';
require_once 'includes/booking_intent.php';
require_once 'includes/event_bundle.php';

$booking_resume_marker = booking_auth_consume_resume_marker();
$booking_resume = isset($_GET['resume']) && $_GET['resume'] === '1'
    && $booking_resume_marker;

$booking_role = (string)($_SESSION['role'] ?? '');
$booking_is_customer = ($_SESSION['logged_in'] ?? false) === true && $booking_role === 'customer';
$booking_is_staff = ($_SESSION['logged_in'] ?? false) === true && in_array($booking_role, ['staff', 'admin'], true);

include 'includes/header.php';

require_once 'config/db_connect.php';
require_once 'includes/media_helper.php';
require_once 'includes/hotel_rooms.php';
$event_bundle_discount_percent = load_event_bundle_discount_percent($conn);
$saved_contact_phone = '';
if ($booking_is_customer) {
    $phone_stmt = $conn->prepare("SELECT phone FROM customers WHERE user_id = ? LIMIT 1");
    $phone_stmt->bind_param('i', $_SESSION['user_id']);
    $phone_stmt->execute();
    $saved_contact_phone = (string)($phone_stmt->get_result()->fetch_assoc()['phone'] ?? '');
    $phone_stmt->close();
}

// Fetch Event Halls with CMS image
$halls_query = $conn->query("SELECT v.id, v.name, v.description, v.amenities, e.base_rate, e.capacity_theater, e.capacity_classroom, e.capacity_banquet FROM venues v JOIN event_halls e ON v.id = e.venue_id WHERE v.status = 'Available'");
$event_halls = $halls_query->fetch_all(MYSQLI_ASSOC);
foreach ($event_halls as &$hall) {
    $hall['image'] = get_venue_image($conn, $hall['name']);
}
unset($hall);

// Fetch Hotel Rooms — individual physical rooms grouped by (room_type => [building_name + room details])
// Each room carries its specific venue_id so the booking flow books the exact physical room.
$hotel_group_schema_ready = hotel_group_schema_ready($conn);
if ($hotel_group_schema_ready) {
    $rooms_query = $conn->query("SELECT g.id AS room_group_id, g.room_type_code,
            COALESCE(t.display_name, g.legacy_room_type, 'Hotel Room') AS room_type,
            g.building_name, g.base_capacity, g.max_capacity, g.bed_count, g.nightly_rate,
            g.extra_pax_rate, g.check_in_time, g.check_out_time,
            g.description AS venue_description, g.amenities AS venue_amenities, COUNT(v.id) AS total_inventory
        FROM hotel_room_groups g
        INNER JOIN hotel_room_types t ON t.type_code = g.room_type_code AND t.active = 1
        INNER JOIN hotel_rooms h ON h.room_group_id = g.id
        INNER JOIN venues v ON v.id = h.venue_id AND v.category = 'Hotel Room' AND v.status = 'Available'
        GROUP BY g.id, g.room_type_code, t.display_name, g.legacy_room_type, g.building_name,
            g.base_capacity, g.max_capacity, g.bed_count, g.nightly_rate, g.extra_pax_rate,
            g.check_in_time, g.check_out_time, g.description, g.amenities, t.sort_order, t.comfort_rank
        ORDER BY t.sort_order, t.comfort_rank, g.building_name, g.sort_order, g.id");
} else {
    $rooms_query = $conn->query("
    SELECT 
        h.room_type, 
        v.name AS building_name,
        h.base_capacity,
        h.max_capacity,
        h.bed_count,
        h.nightly_rate,
        h.extra_pax_rate,
        h.check_in_time,
        h.check_out_time,
        v.description AS venue_description,
        v.amenities AS venue_amenities,
        COUNT(v.id) AS total_inventory
    FROM venues v 
    JOIN hotel_rooms h ON v.id = h.venue_id 
    WHERE v.status = 'Available'
    GROUP BY h.room_type, v.name, h.base_capacity, h.max_capacity, h.bed_count, h.nightly_rate, h.extra_pax_rate, h.check_in_time, h.check_out_time, v.description, v.amenities
    ORDER BY h.room_type, v.name
");
}
$hotel_rooms_flat = $rooms_query->fetch_all(MYSQLI_ASSOC);

// Group by room_type for the first dropdown.
$grouped_hotel_rooms = [];
$room_img_cache = [];
foreach ($hotel_rooms_flat as &$room) {
    if ($hotel_group_schema_ready && !empty($room['room_group_id'])) {
        $room['image'] = get_hotel_room_group_image($conn, (int)$room['room_group_id']);
    } else {
        $img_key = $room['building_name'] . ' - ' . $room['room_type'];
        if (!isset($room_img_cache[$img_key])) $room_img_cache[$img_key] = get_venue_image($conn, $img_key);
        $room['image'] = $room_img_cache[$img_key];
    }
    $grouped_hotel_rooms[$room['room_type']][] = $room;
}
unset($room);

// Fetch hotel room groups for add-on panel (distinct building+type combos with rate/capacity/count)
$room_groups_query = $hotel_group_schema_ready ? $conn->query("SELECT g.id AS room_group_id,
        g.building_name, COALESCE(t.display_name, g.legacy_room_type, 'Hotel Room') AS room_type,
        g.nightly_rate, g.base_capacity, COUNT(v.id) AS total_inventory
    FROM hotel_room_groups g
    INNER JOIN hotel_room_types t ON t.type_code = g.room_type_code AND t.active = 1
    INNER JOIN hotel_rooms h ON h.room_group_id = g.id
    INNER JOIN venues v ON v.id = h.venue_id AND v.category = 'Hotel Room' AND v.status = 'Available'
    GROUP BY g.id, g.building_name, t.display_name, g.legacy_room_type, g.nightly_rate, g.base_capacity, t.sort_order, t.comfort_rank
    ORDER BY t.sort_order, g.building_name, t.comfort_rank, g.sort_order, g.id") : $conn->query("
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
    $grp['image'] = $hotel_group_schema_ready && !empty($grp['room_group_id'])
        ? get_hotel_room_group_image($conn, (int)$grp['room_group_id'])
        : ($room_img_cache[$grp['building_name'] . ' - ' . $grp['room_type']] ?? get_venue_image($conn, $grp['building_name'] . ' - ' . $grp['room_type']));
}
unset($grp);

// Fetch Villas with CMS image
$villas_query = $conn->query("SELECT v.id, v.name, v.description, v.amenities, vi.day_rate AS base_rate, vi.overnight_rate, vi.base_capacity, vi.max_capacity, vi.extra_pax_rate, vi.has_private_pool, vi.day_check_in_time, vi.day_check_out_time, vi.overnight_check_in_time, vi.overnight_check_out_time, vi.day_stay_inclusions, vi.overnight_stay_inclusions FROM venues v JOIN villas vi ON v.id = vi.venue_id WHERE v.status = 'Available'");
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
            <div class="booking-tabs" role="tablist" aria-label="Booking service" data-booking-tablist>
                <button type="button" class="tab-btn active" id="booking-tab-event-hall" role="tab" aria-selected="true" aria-controls="tab-event-hall" tabindex="0" data-tab="event-hall">Event Hall</button>
                <button type="button" class="tab-btn" id="booking-tab-hotel-rooms" role="tab" aria-selected="false" aria-controls="tab-hotel-rooms" tabindex="-1" data-tab="hotel-rooms">Hotel Rooms</button>
                <button type="button" class="tab-btn" id="booking-tab-resort-villa" role="tab" aria-selected="false" aria-controls="tab-resort-villa" tabindex="-1" data-tab="resort-villa">Resort Villa</button>
            </div>
            <a class="booking-summary-jump" href="#booking-summary" aria-label="Jump to booking summary and submission controls">Review summary</a>

            <nav class="booking-stepper" aria-label="Booking steps" data-booking-stepper>
                <ol>
                    <?php foreach (['Choose', 'Dates', 'Options', 'Review'] as $index => $step_label): $step_number = $index + 1; ?>
                    <li>
                        <button type="button" class="booking-step-link<?php echo $step_number === 1 ? ' is-current' : ''; ?>" data-booking-step-nav="<?php echo $step_number; ?>"<?php echo $step_number === 1 ? ' aria-current="step"' : ''; ?>>
                            <span class="visually-hidden">Step <?php echo $step_number; ?> of 4: </span>
                            <span class="booking-step-number" aria-hidden="true"><?php echo $step_number; ?></span>
                            <span><?php echo htmlspecialchars($step_label, ENT_QUOTES, 'UTF-8'); ?></span>
                        </button>
                    </li>
                    <?php endforeach; ?>
                </ol>
            </nav>
            <p class="booking-step-error" data-booking-step-error role="alert" aria-live="assertive" hidden></p>

            <!-- INJECT THE TAB COMPONENTS -->
            <?php include 'includes/partials/tab_event_hall.php'; ?>
            <?php include 'includes/partials/tab_hotel_rooms.php'; ?>
            <?php include 'includes/partials/tab_resort_villa.php'; ?>

            <div class="booking-review-mount" id="booking-review-mount" hidden></div>

        </div>

        <!-- RIGHT COLUMN: STICKY SUMMARY (35%) -->
        <div class="booking-sidebar">
            <div class="sticky-summary" id="booking-summary" tabindex="-1">
                <h3
                    style="font-family: var(--font-heading); margin-bottom: 1.5rem; font-size: 1.6rem; border-bottom: 1px solid rgba(0,0,0,0.1); padding-bottom: 10px;">
                    Booking Summary
                </h3>

                <div class="booking-review-details">
                    <!-- Summary Containers -->
                    <div class="summary-container active" id="sum-event-hall" aria-hidden="false">
                        <p><strong>Service:</strong> <span class="sum-val">Event Hall</span></p>
                        <p><strong>Venue:</strong> <span class="sum-val" id="sum-ev-venue">Not selected</span></p>
                        <p><strong>Event Type:</strong> <span class="sum-val" id="sum-ev-type">Plain Hall</span></p>
                        <p><strong>Dates:</strong> <span class="sum-val sum-dates-display">--</span></p>
                        <p><strong>Operating Hours:</strong> <span class="sum-val">Per Event Schedule</span></p>
                        <p><strong>Guests:</strong> <span class="sum-val" id="sum-ev-guests">--</span></p>
                        <p><strong>Payment:</strong> <span class="sum-val" id="sum-ev-payment">To Be Arranged</span></p>
                        <p id="event-bundle-estimate" data-discount-percent="<?php echo htmlspecialchars(format_event_bundle_discount_percent($event_bundle_discount_percent), ENT_QUOTES, 'UTF-8'); ?>" style="display:none; color:var(--color-gold);" aria-live="polite"><strong>Bundle:</strong> <span id="event-bundle-estimate-label"><?php echo htmlspecialchars(event_bundle_discount_label($event_bundle_discount_percent), ENT_QUOTES, 'UTF-8'); ?></span> estimated — final quote after resort review (<span id="event-bundle-estimate-amount">₱0.00</span> discount)</p>
                        <p class="summary-guidance" id="event-estimate-guidance">Choose an event hall and dates to see the estimate.</p>
                        <div class="estimate-card" id="event-estimate-card" role="status" aria-live="polite" hidden>
                            <strong>Estimated total</strong>
                            <span id="event-estimate-total">₱0.00</span>
                            <small>Estimate only; final event quotation is subject to resort confirmation.</small>
                        </div>
                    </div>

                    <div class="summary-container" id="sum-hotel-rooms" aria-hidden="true">
                        <p><strong>Service:</strong> <span class="sum-val">Hotel Room</span></p>
                        <p><strong>Room Category:</strong> <span class="sum-val" id="sum-ht-type">Not selected</span></p>
                        <p><strong>Room:</strong> <span class="sum-val" id="sum-ht-room">Not selected</span></p>
                        <p><strong>Dates:</strong> <span class="sum-val sum-dates-display">--</span></p>
                        <p><strong>Check-in:</strong> <span class="sum-val" id="sum-ht-in" style="color:var(--color-gold);">—</span>
                        </p>
                        <p><strong>Check-out:</strong> <span class="sum-val" id="sum-ht-out" style="color:var(--color-gold);">—</span></p>
                        <p><strong>Guests:</strong> <span class="sum-val" id="sum-ht-guests">1</span></p>
                        <p><strong>Extra Pax:</strong> <span class="sum-val" id="sum-ht-fee">—</span></p>
                        <p><strong>Payment:</strong> <span class="sum-val" id="sum-ht-payment">100% Full</span></p>
                    </div>

                    <div class="summary-container" id="sum-resort-villa" aria-hidden="true">
                        <p><strong>Service:</strong> <span class="sum-val">Resort Villa</span></p>
                        <p><strong>Villa:</strong> <span class="sum-val" id="sum-vl-type">Not selected</span></p>
                        <p><strong>Stay:</strong> <span class="sum-val" id="sum-vl-stay">Day Time Stay</span></p>
                        <p><strong>Dates:</strong> <span class="sum-val sum-dates-display">--</span></p>
                        <p><strong>Check-in:</strong> <span class="sum-val" id="sum-vl-in"
                                style="color:var(--color-gold);">—</span></p>
                        <p><strong>Check-out:</strong> <span class="sum-val" id="sum-vl-out"
                                style="color:var(--color-gold);">—</span></p>
                        <p><strong>Guests:</strong> <span class="sum-val" id="sum-vl-guests">1</span></p>
                        <p><strong>Extra Pax:</strong> <span class="sum-val" id="sum-vl-fee">—</span></p>
                        <p><strong>Payment:</strong> <span class="sum-val" id="sum-vl-payment">100% Full</span></p>
                    </div>

                    <!-- DYNAMIC PRICING SECTION (Hidden for Events) -->
                    <p class="summary-guidance" id="pricing-guidance" hidden>Choose a room or villa and dates to see your estimate.</p>
                    <div id="pricing-section" hidden>
                        <div id="summary-breakdown"
                            style="margin-top: 15px; border-top: 1px dashed #ccc; padding-top: 15px;"></div>

                        <div class="summary-total"
                            style="display: flex; justify-content: space-between; font-weight: bold; font-size: 1.1rem; margin-top: 10px;">
                            <span>Total Amount</span>
                            <span id="summary-total-val" style="color: var(--color-gold);">—</span>
                        </div>

                        <div class="summary-total payable"
                            style="display: flex; justify-content: space-between; font-weight: bold; margin-top: 5px;">
                            <span>Amount Due Now</span>
                            <span id="summary-due-val">—</span>
                        </div>
                    </div>
                </div>

                <!-- Universal Summary Footer -->
                <div class="summary-footer booking-review-form" id="booking-summary-footer" hidden
                    style="margin-top: 25px; border-top: 1px solid rgba(0,0,0,0.1); padding-top: 20px;">

                    <!-- Contact Number -->
                    <div style="margin-bottom: 15px;">
                        <label for="contact-phone"
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
                        <label for="booking-notes"
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
                                id="countdown">15:00</span></span>
                    </div>

                    <div class="terms-group">
                        <input type="checkbox" id="terms-check" name="policy_consent" value="1">
                        <label for="terms-check">I agree to the <a href="#" id="open-terms">Terms &
                                Conditions</a></label>
                    </div>

                    <!-- Action Buttons -->
                    <button type="button" class="btn btn-book-submit" id="btn-proceed">SUBMIT EVENT INQUIRY</button>
                    <button class="btn btn-cancel" id="btn-cancel">CANCEL</button>
                </div>
            </div>
        </div>

    </div>
</section>

<!-- INJECT THE MODALS -->
<?php include 'includes/partials/booking_modals.php'; ?>
<?php include 'includes/partials/manual_payment_modal.php'; ?>

<script>
window.bookingAuth = <?php echo json_encode([
    'isCustomer' => $booking_is_customer,
    'isStaff' => $booking_is_staff,
    'isAuthenticated' => ($booking_is_customer || $booking_is_staff),
    'resume' => $booking_resume
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>

<?php include 'includes/footer.php'; ?>
