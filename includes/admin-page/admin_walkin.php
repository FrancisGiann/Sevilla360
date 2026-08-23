<?php
require_once 'config/db_connect.php';
require_once 'includes/media_helper.php';

// Fetch Event Halls with CMS image
$halls_query = $conn->query("SELECT v.id, v.name, e.base_rate, e.capacity_theater, e.capacity_classroom, e.capacity_banquet FROM venues v JOIN event_halls e ON v.id = e.venue_id WHERE v.status = 'Available'");
$event_halls = $halls_query->fetch_all(MYSQLI_ASSOC);
foreach ($event_halls as &$hall) {
    $hall['image'] = get_venue_image($conn, $hall['name']);
}
unset($hall);

// Fetch Hotel Rooms — individual physical rooms grouped by room_type
$rooms_query = $conn->query("
    SELECT 
        h.room_type, 
        v.name AS building_name,
        h.base_capacity,
        h.nightly_rate,
        h.extra_pax_rate,
        v.description AS venue_description,
        v.amenities AS venue_amenities,
        COUNT(v.id) AS total_inventory
    FROM venues v 
    JOIN hotel_rooms h ON v.id = h.venue_id 
    WHERE v.status = 'Available'
    GROUP BY h.room_type, v.name, h.base_capacity, h.nightly_rate, h.extra_pax_rate, v.description, v.amenities
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
    $room['image'] = $room_img_cache[$img_key];
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
$villas_query = $conn->query("SELECT v.id, v.name, vi.day_rate AS base_rate, vi.overnight_rate, vi.base_capacity, vi.max_capacity, vi.extra_pax_rate FROM venues v JOIN villas vi ON v.id = vi.venue_id WHERE v.status = 'Available'");
$villas = $villas_query->fetch_all(MYSQLI_ASSOC);
foreach ($villas as &$villa) {
    $villa['image'] = get_venue_image($conn, $villa['name']);
}
unset($villa);
?>
<div class="admin-booking-container">

    <!-- Direct Walk-in Header -->
    <div class="walkin-header">
        <p class="walkin-subtitle">MANAGE DIRECT BOOKINGS AND RESERVATIONS</p>
    </div>

    <!-- Section 1: Guest Information -->
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
        <div class="form-row">
            <div class="form-group full-width">
                <label>Special Requests</label>
                <textarea id="guest-notes" rows="3" placeholder="Enter any specific guest requirements..." class="form-textarea-full"></textarea>
            </div>
        </div>
        <div class="form-row" id="walkin-admin-notes-row" style="display: none;">
            <div class="form-group full-width">
                <label>Internal Preparation Notes (Admin Only)</label>
                <textarea id="admin-notes" rows="3" placeholder="Style, theme, setup time, decoration or preparation instructions..." class="form-textarea-full"></textarea>
                <small>Saved for staff/admin view only and not shown to the customer.</small>
            </div>
        </div>
    </section>

    <!-- Section 2: Venue & Accommodation -->
    <section class="booking-card">
        <h3 class="card-title">2. Venue & Accommodation</h3>

        <!-- Venue Category Tabs -->
        <div class="booking-tabs">
            <button class="tab-btn active" data-target="tab-event">Event Hall</button>
            <button class="tab-btn" data-target="tab-hotel">Hotel Rooms</button>
            <button class="tab-btn" data-target="tab-villa">Resort Villa</button>
        </div>

        <!-- Venue Selection Partials -->
        <?php include 'includes/partials/admin/admin_tab_event.php'; ?>
        <?php include 'includes/partials/admin/admin_tab_hotel.php'; ?>
        <?php include 'includes/partials/admin/admin_tab_villa.php'; ?>
    </section>

    <!-- Section 3: Payment & Checkout -->
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
            <p><strong>Note:</strong> Confirming this booking will mark the selected dates as reserved and unavailable for other bookings. Please ensure all details are correct before proceeding.</p>
        </div>
    </section>
</div>

<!-- Admin Walk-in Modals Partial -->
<?php include 'includes/partials/admin/admin_walkin_modals.php'; ?>
