<?php
require_once 'config/db_connect.php';
require_once 'includes/media_helper.php';

// Fetch Event Halls with CMS image and the venue details shown in the booking flow.
$halls_query = $conn->query("SELECT v.id, v.name, v.description, v.amenities, e.base_rate, e.capacity_theater, e.capacity_classroom, e.capacity_banquet FROM venues v JOIN event_halls e ON v.id = e.venue_id WHERE v.status = 'Available'");
$event_halls = $halls_query->fetch_all(MYSQLI_ASSOC);
foreach ($event_halls as &$hall) {
    $hall['image'] = get_venue_image($conn, $hall['name']);
    if ($hall['image'] === 'assets/img/placeholder.jpg') $hall['image'] = '';
}
unset($hall);

// Fetch Hotel Rooms — individual physical rooms grouped by room_type
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
$hotel_rooms_flat = $rooms_query->fetch_all(MYSQLI_ASSOC);

$grouped_hotel_rooms = [];
$room_img_cache = [];
foreach ($hotel_rooms_flat as &$room) {
    $img_key = $room['building_name'] . ' - ' . $room['room_type'];
    if (!isset($room_img_cache[$img_key])) {
        $room_img_cache[$img_key] = get_venue_image($conn, $img_key);
    }
    $room['image'] = $room_img_cache[$img_key] === 'assets/img/placeholder.jpg' ? '' : $room_img_cache[$img_key];
    $grouped_hotel_rooms[$room['room_type']][] = $room;
}
unset($room);

// Fetch hotel room groups for event add-on panel
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
$villas_query = $conn->query("SELECT v.id, v.name, v.description, v.amenities, vi.day_rate AS base_rate, vi.overnight_rate, vi.base_capacity, vi.max_capacity, vi.extra_pax_rate, vi.has_private_pool, vi.day_check_in_time, vi.day_check_out_time, vi.overnight_check_in_time, vi.overnight_check_out_time, vi.day_stay_inclusions, vi.overnight_stay_inclusions FROM venues v JOIN villas vi ON v.id = vi.venue_id WHERE v.status = 'Available'");
$villas = $villas_query->fetch_all(MYSQLI_ASSOC);
foreach ($villas as &$villa) {
    $villa['image'] = get_venue_image($conn, $villa['name']);
    if ($villa['image'] === 'assets/img/placeholder.jpg') $villa['image'] = '';
}
unset($villa);
?>
<div class="admin-booking-container">

    <!-- Direct Walk-in Header -->
    <div class="walkin-header">
        <h2>Walk-in booking</h2>
        <p class="walkin-subtitle">Create a reservation for a guest at the front desk.</p>
    </div>

    <!-- Section 1: Guest Information -->
    <section class="booking-card">
        <h3 class="card-title">Guest information</h3>
        <div class="form-row">
            <div class="form-group">
                <label for="guest-name">Full name</label>
                <input type="text" id="guest-name" autocomplete="name" placeholder="Enter guest's full name">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="guest-phone">Contact number</label>
                <input type="tel" id="guest-phone" autocomplete="tel" placeholder="e.g. 09123456789">
            </div>
            <div class="form-group">
                <label for="guest-email">Email address</label>
                <input type="email" id="guest-email" autocomplete="email" placeholder="Enter guest's email">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group full-width">
                <label for="guest-notes">Special requests</label>
                <textarea id="guest-notes" rows="3" placeholder="Enter any specific guest requirements..." class="form-textarea-full"></textarea>
            </div>
        </div>
        <div class="form-row" id="walkin-admin-notes-row" style="display: none;">
            <div class="form-group full-width">
                <label for="admin-notes">Internal preparation notes (staff only)</label>
                <textarea id="admin-notes" rows="3" placeholder="Style, theme, setup time, decoration or preparation instructions..." class="form-textarea-full"></textarea>
                <small>Saved for staff/admin view only and not shown to the customer.</small>
            </div>
        </div>
    </section>

    <!-- Section 2: Venue & Accommodation -->
    <section class="booking-card">
        <h3 class="card-title">Venue and accommodation</h3>

        <!-- Venue Category Tabs -->
        <div class="booking-tabs" role="tablist" aria-label="Booking category">
            <button type="button" class="tab-btn active" id="walkin-tab-event" role="tab" aria-selected="true" aria-controls="tab-event" tabindex="0" data-target="tab-event">Event Hall</button>
            <button type="button" class="tab-btn" id="walkin-tab-hotel" role="tab" aria-selected="false" aria-controls="tab-hotel" tabindex="-1" data-target="tab-hotel">Hotel Rooms</button>
            <button type="button" class="tab-btn" id="walkin-tab-villa" role="tab" aria-selected="false" aria-controls="tab-villa" tabindex="-1" data-target="tab-villa">Resort Villa</button>
        </div>

        <!-- Venue Selection Partials -->
        <?php include 'includes/partials/admin/admin_tab_event.php'; ?>
        <?php include 'includes/partials/admin/admin_tab_hotel.php'; ?>
        <?php include 'includes/partials/admin/admin_tab_villa.php'; ?>
    </section>

    <!-- Section 3: Payment & Checkout -->
    <section class="booking-card">
        <h3 class="card-title">Payment and review</h3>

        <fieldset class="form-group walkin-payment-fieldset">
            <legend id="payment-scheme-label">Payment scheme</legend>
            <div class="walkin-choice-grid payment-scheme-options" role="radiogroup" aria-labelledby="payment-scheme-label">
                <label class="booking-choice-tile"><input type="radio" name="payment-scheme" value="1" checked> Full payment <span>100%</span></label>
                <label class="booking-choice-tile"><input type="radio" name="payment-scheme" value="0.5"> Down payment <span>50%</span></label>
                <label class="booking-choice-tile"><input type="radio" name="payment-scheme" value="0.2"> Reservation fee <span>20%</span></label>
            </div>
        </fieldset>

        <fieldset class="form-group walkin-payment-fieldset">
            <legend id="payment-method-label">Payment method</legend>
            <div class="walkin-choice-grid payment-method-options" role="radiogroup" aria-labelledby="payment-method-label">
                <label class="booking-choice-tile"><input type="radio" name="payment-method" value="cash" checked> Cash</label>
                <label class="booking-choice-tile"><input type="radio" name="payment-method" value="gcash"> GCash</label>
                <label class="booking-choice-tile"><input type="radio" name="payment-method" value="maya"> Maya</label>
                <label class="booking-choice-tile"><input type="radio" name="payment-method" value="bank"> Bank transfer</label>
            </div>
        </fieldset>

        <div class="form-group hidden" id="transaction-wrapper">
            <label for="transaction-id">Reference / transaction ID</label>
            <input type="text" id="transaction-id" placeholder="Enter transaction or reference number">
        </div>

        <!-- Dynamic Line Item Builder -->
        <div class="form-group custom-items-box">
            <div class="custom-items-header">
                <div>
                    <h4 class="custom-items-title">Custom Line Items</h4>
                    <p class="custom-items-desc">Add negotiated fees (Catering, A/V, etc.)</p>
                </div>
                <button type="button" class="btn-action btn-add-custom-item" id="wi-btn-add-item"><i class="fa-solid fa-plus"></i> Add Item</button>
            </div>

            <div id="wi-line-items" class="custom-items-list"></div>
        </div>

        <!-- Booking Summary Card -->
        <div class="checkout-summary" role="region" aria-labelledby="walkin-review-title">
            <div class="summary-heading">
                <h4 class="summary-title" id="walkin-review-title">Review booking</h4>
                <p>Check the selected dates and amount due before confirming.</p>
            </div>
            <div class="summary-row">
                <span>Selected dates</span>
                <span id="summary-dates" class="selected-date-text">Please select dates</span>
            </div>
            <div id="summary-breakdown" aria-live="polite" aria-atomic="false">
                <p class="summary-empty-state">Choose a venue and dates to see the price breakdown.</p>
            </div>
            <div class="summary-total">
                <span>Total amount</span>
                <span id="summary-total-val" class="color-gold">—</span>
            </div>
            <div class="summary-total payable">
                <span>Amount due now</span>
                <span id="summary-due-val">—</span>
            </div>
            <div class="action-buttons">
                <button type="button" class="btn-confirm-walkin">Confirm walk-in booking</button>
                <button type="button" class="btn-cancel-walkin">Clear booking</button>
            </div>
        </div>
        <div class="form-note" role="note">
            <p><strong>Reservation notice:</strong> Confirming this booking reserves the selected dates and makes them unavailable to other guests. Please check the guest, venue, dates, and payment details before confirming.</p>
        </div>
    </section>
</div>

<!-- Admin Walk-in Modals Partial -->
<?php include 'includes/partials/admin/admin_walkin_modals.php'; ?>
