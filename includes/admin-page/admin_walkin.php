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

        <!-- TAB 1: EVENT HALL -->
        <div class="tab-content active" id="tab-event">

            <div class="calendar-ui" id="cal-ui-event">
                <div class="cal-header">
                    <button class="cal-nav prev-month"><i class="fa-solid fa-arrow-left"></i></button>
                    <h4 class="cal-month-year">Month Year</h4>
                    <button class="cal-nav next-month"><i class="fa-solid fa-arrow-right"></i></button>
                </div>
                <div class="cal-weekdays">
                    <span>SUN</span><span>MON</span><span>TUE</span><span>WED</span><span>THU</span><span>FRI</span><span>SAT</span>
                </div>
                <div class="cal-days-grid"></div>
                <div class="cal-legend">
                    <div class="legend-item"><span class="dot selected"></span> Selected</div>
                    <div class="legend-item"><span class="dot booked"></span> Booked</div>
                    <div class="legend-item"><span class="dot available"></span> Available</div>
                    <div class="legend-item"><span class="dot unavailable"></span> Unavailable</div>
                </div>
            </div>

            <div class="dynamic-img-wrapper">
                <img id="event-img"
                    src="https://images.unsplash.com/photo-1519225421980-715cb0215aed?auto=format&fit=crop&w=1200&q=80"
                    alt="Event Hall">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Select Venue Space</label>
                    <select id="event-venue">
                        <?php foreach($event_halls as $hall): ?>
                        <option value="<?php echo $hall['base_rate']; ?>" data-id="<?php echo $hall['id']; ?>">
                            <?php echo htmlspecialchars($hall['name']); ?>
                            (₱<?php echo number_format($hall['base_rate']); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Select Style</label>
                    <select id="event-style">
                        <option value="0">Minimalist (Standard)</option>
                        <option value="5000">Classic Elegance (+₱5,000)</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Event Type</label>
                <div class="radio-group-inline">
                    <label><input type="radio" name="event-type" value="0" checked> Plain Hall</label>
                    <label><input type="radio" name="event-type" value="10000"> Wedding (+₱10,000)</label>
                    <label><input type="radio" name="event-type" value="5000"> Birthday (+₱5,000)</label>
                    <label><input type="radio" name="event-type" value="0" id="event-others-radio">
                        Others</label>
                </div>
                <input type="text" id="event-type-others" class="hidden mt-10" placeholder="Specify event type...">
            </div>

            <div class="form-group">
                <label>Number of Guests</label>
                <input type="number" id="event-guests" placeholder="e.g. 100" min="1" max="300" value="50">
            </div>

            <!-- Enhance Your Event -->
            <div class="addons-section">
                <h4 class="addon-title">Enhance Your Event</h4>

                <div class="addon-block">
                    <label class="toggle-label">
                        <input type="checkbox" id="check-catering"> Include Catering
                    </label>
                    <div class="addon-content hidden" id="catering-options">
                        <div class="tier-cards">
                            <label class="tier-card">
                                <input type="radio" name="catering-tier" value="750" checked>
                                <div class="tier-header">
                                    <h4>Silver Tier</h4>
                                </div>
                                <p class="tier-desc">Standard Buffet</p>
                                <span class="tier-price">₱750 / head</span>
                                <ul class="tier-menu">
                                    <li>1 Soup, 1 Salad</li>
                                    <li>3 Main Courses</li>
                                    <li>1 Dessert, Iced Tea</li>
                                </ul>
                            </label>
                            <label class="tier-card">
                                <input type="radio" name="catering-tier" value="1200">
                                <div class="tier-header">
                                    <h4>Gold Tier</h4>
                                </div>
                                <p class="tier-desc">Premium Course</p>
                                <span class="tier-price">₱1,200 / head</span>
                                <ul class="tier-menu">
                                    <li>Premium Soup & Salad</li>
                                    <li>4 Main Courses</li>
                                    <li>2 Desserts, Drinks</li>
                                </ul>
                            </label>
                            <label class="tier-card">
                                <input type="radio" name="catering-tier" value="1800">
                                <div class="tier-header">
                                    <h4>Platinum Tier</h4>
                                </div>
                                <p class="tier-desc">Luxury Dining</p>
                                <span class="tier-price">₱1,800 / head</span>
                                <ul class="tier-menu">
                                    <li>Gourmet Appetizers</li>
                                    <li>5 Main Courses</li>
                                    <li>Dessert Buffet & Wine</li>
                                </ul>
                            </label>
                        </div>
                        <div class="form-group" style="margin-top: 1.5rem;">
                            <label for="catering-notes">Catering Notes / Special Requests</label>
                            <textarea id="catering-notes"
                                placeholder="e.g., Peanut allergies, vegetarian meals for 5 pax..." rows="3"></textarea>
                        </div>
                    </div>
                </div>

                <div class="addon-block">
                    <label class="toggle-label">
                        <input type="checkbox" id="check-rooms"> Reserve Hotel Rooms
                    </label>
                    <div class="addon-content hidden" id="rooms-options">
                        <div class="mix-match">
                            <div class="mix-row">
                                <div class="mix-info">
                                    <img src="https://images.unsplash.com/photo-1611892440504-42a792e24d32?auto=format&fit=crop&w=150"
                                        alt="Deluxe">
                                    <div>
                                        <h5>Deluxe Room</h5>
                                        <p>2 Pax | ₱4,500 / night</p>
                                    </div>
                                </div>
                                <div class="counter">
                                    <button type="button" class="btn-minus" data-target="qty-deluxe">-</button>
                                    <span class="val" id="qty-deluxe">0</span>
                                    <button type="button" class="btn-plus" data-target="qty-deluxe">+</button>
                                </div>
                            </div>
                            <div class="mix-row">
                                <div class="mix-info">
                                    <img src="https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?auto=format&fit=crop&w=150"
                                        alt="VIP">
                                    <div>
                                        <h5>VIP Suite</h5>
                                        <p>4 Pax | ₱8,500 / night</p>
                                    </div>
                                </div>
                                <div class="counter">
                                    <button type="button" class="btn-minus" data-target="qty-vip">-</button>
                                    <span class="val" id="qty-vip">0</span>
                                    <button type="button" class="btn-plus" data-target="qty-vip">+</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="addon-block">
                    <label class="toggle-label"><input type="checkbox" id="check-av"> Premium A/V
                        Setup</label>
                </div>
            </div>
        </div>

        <!-- TAB 2: HOTEL ROOMS -->
        <div class="tab-content" id="tab-hotel">
            <div class="calendar-ui" id="cal-ui-hotel">
                <div class="cal-header">
                    <button class="cal-nav prev-month"><i class="fa-solid fa-arrow-left"></i></button>
                    <h4 class="cal-month-year">Month Year</h4>
                    <button class="cal-nav next-month"><i class="fa-solid fa-arrow-right"></i></button>
                </div>
                <div class="cal-weekdays">
                    <span>SUN</span><span>MON</span><span>TUE</span><span>WED</span><span>THU</span><span>FRI</span><span>SAT</span>
                </div>
                <div class="cal-days-grid"></div>
            </div>

            <div class="dynamic-img-wrapper">
                <img id="hotel-img"
                    src="https://images.unsplash.com/photo-1611892440504-42a792e24d32?auto=format&fit=crop&w=1200&q=80"
                    alt="Hotel Room">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Select Room Category</label>
                    <select id="hotel-room-type">
                        <option value="" disabled selected>Select category...</option>
                        <?php foreach(array_keys($grouped_hotel_rooms) as $type): ?>
                        <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Select Specific Room</label>
                    <!-- Disabled until they pick a category -->
                    <select id="hotel-room-name" disabled>
                        <option value="" disabled selected>Select category first...</option>
                    </select>
                </div>
            </div>

            <div class="form-row" style="margin-top: 15px;">
                <div class="form-group">
                    <label>Number of Guests <span class="extra-pax-note">Exceeding base: ₱800/head</span></label>
                    <input type="number" id="hotel-guests" min="1" value="2">
                    <small id="hotel-extra-fee" class="hidden">Extra Pax Fee: ₱0</small>
                </div>
            </div>

            <!-- Pass the grouped data to Javascript! -->
            <script>
            // We MUST attach it to 'window' so external JS files can access it!
            window.hotelRoomData = <?php echo json_encode($grouped_hotel_rooms); ?>;
            </script>
        </div>

        <!-- TAB 3: RESORT VILLA -->
        <div class="tab-content" id="tab-villa">
            <div class="calendar-ui" id="cal-ui-villa">
                <div class="cal-header">
                    <button class="cal-nav prev-month"><i class="fa-solid fa-arrow-left"></i></button>
                    <h4 class="cal-month-year">Month Year</h4>
                    <button class="cal-nav next-month"><i class="fa-solid fa-arrow-right"></i></button>
                </div>
                <div class="cal-weekdays">
                    <span>SUN</span><span>MON</span><span>TUE</span><span>WED</span><span>THU</span><span>FRI</span><span>SAT</span>
                </div>
                <div class="cal-days-grid"></div>
            </div>

            <div class="dynamic-img-wrapper">
                <img id="villa-img"
                    src="https://images.unsplash.com/photo-1582268611958-ebfd161ef9cf?auto=format&fit=crop&w=1200&q=80"
                    alt="Resort Villa">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Select Villa <span class="capacity-note">Base: 4 Pax | Max: 8 Pax</span></label>
                    <select id="villa-type">
                        <?php foreach($villas as $villa): ?>
                        <option value="<?php echo $villa['base_rate']; ?>" data-id="<?php echo $villa['id']; ?>">
                            <?php echo htmlspecialchars($villa['name']); ?>
                            (₱<?php echo number_format($villa['base_rate']); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Stay Type</label>
                <div class="block-radios">
                    <label><input type="radio" name="stay-type" value="0" checked> Day Time (8AM -
                        5PM)</label>
                    <label><input type="radio" name="stay-type" value="2000"> Overnight (+₱2,000)</label>
                </div>
            </div>

            <div class="form-group">
                <label>Number of Guests <span class="extra-pax-note">Exceeding base:
                        ₱1,000/head</span></label>
                <input type="number" id="villa-guests" min="1" max="8" value="4">
                <small id="villa-extra-fee" class="hidden">Extra Pax Fee: ₱0</small>
            </div>
        </div>
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

<!-- Modals -->
<!-- 1. Confirm Dates Modal -->
<div class="modal-overlay" id="confirm-dates-modal">
    <div class="modal-content">
        <h2 class="modal-title">Confirm Dates</h2>
        <p class="modal-text">You have selected:</p>
        <h3 class="modal-highlight-date" id="confirm-date-display">--</h3>
        <p class="modal-text">Proceeding will lock these dates for 30 minutes while you complete your booking.</p>
        <div class="modal-actions">
            <button class="btn-modal-primary" id="btn-confirm-dates">CONFIRM</button>
            <button class="btn-modal-outline" id="btn-cancel-dates">CANCEL</button>
        </div>
    </div>
</div>

<!-- 2. Change Dates Modal -->
<div class="modal-overlay" id="change-dates-modal">
    <div class="modal-content">
        <h2 class="modal-title">Change Dates?</h2>
        <p class="modal-text">You currently have dates locked for this session.</p>
        <p class="modal-text">Would you like to cancel your current selection and pick new dates?</p>
        <div class="modal-actions">
            <button class="btn-modal-primary" id="btn-override-yes">YES, CHANGE DATES</button>
            <button class="btn-modal-outline" id="btn-override-no">NO, KEEP CURRENT</button>
        </div>
    </div>
</div>