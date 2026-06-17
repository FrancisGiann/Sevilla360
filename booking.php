<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Your Stay - SEVILLA360</title>
    <!-- Master Stylesheet -->
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- Booking Specific Styles -->
    <link rel="stylesheet" href="assets/css/booking.css">
</head>
<body>

    <!-- Global Header -->
    <nav class="navbar">
        <div class="container">
            <a href="index.php" class="navbar-brand">Sevilla360</a>
            <div class="hamburger" id="hamburger"><span></span><span></span><span></span></div>
            <div class="nav-links" id="nav-links">
                <a href="index.php">Home</a>
                <a href="index.php#about">About</a>
                <a href="index.php#events">Events</a>
                <a href="index.php#accommodations">Accommodations</a>
                <a href="showroom.php">Virtual Showroom</a>
                <a href="auth.php" class="btn btn-primary" style="color: white;">Login / Register</a>
            </div>
        </div>
    </nav>

    <!-- Main Booking Section -->
    <section class="booking-section">
        <div class="container booking-grid">
            
            <!-- LEFT COLUMN (65%) -->
            <div class="booking-main">
                <div class="booking-tabs">
                    <button class="tab-btn active" data-tab="event-hall">Event Hall</button>
                    <button class="tab-btn" data-tab="hotel-rooms">Hotel Rooms</button>
                    <button class="tab-btn" data-tab="resort-villa">Resort Villa</button>
                </div>

                <!-- TAB 1: EVENT HALL -->
                <div class="tab-content active" id="tab-event-hall">
                    <h2 class="section-title">Reserve an Event Hall</h2>
                    
                    <!-- Advanced Airbnb-Style Calendar UI -->
                    <div class="calendar-ui" id="cal-ui-event">
                        <div class="cal-header">
                            <button type="button" class="cal-nav prev-month">&larr;</button>
                            <h4 class="cal-month-year">October 2024</h4>
                            <button type="button" class="cal-nav next-month">&rarr;</button>
                        </div>
                        <div class="cal-weekdays">
                            <span>SUN</span><span>MON</span><span>TUE</span><span>WED</span><span>THU</span><span>FRI</span><span>SAT</span>
                        </div>
                        <div class="cal-days-grid">
                            <!-- JS Injected Days -->
                        </div>
                        <div class="cal-legend">
                            <span class="legend-item"><span class="dot selected"></span> Selected</span>
                            <span class="legend-item"><span class="dot in-range-dot"></span> In Range</span>
                            <span class="legend-item"><span class="dot booked"></span> Booked</span>
                            <span class="legend-item"><span class="dot available"></span> Available</span>
                        </div>
                    </div>

                    <div class="dynamic-img-wrapper">
                        <img id="event-img" src="https://images.unsplash.com/photo-1519225421980-715cb0215aed?auto=format&fit=crop&w=800" alt="Event Hall">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Select Venue Space</label>
                            <select id="event-venue">
                                <option value="grand-ballroom">Grand Ballroom</option>
                                <option value="garden-pavilion">Garden Pavilion</option>
                                <option value="rooftop-terrace">Rooftop Terrace</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Select Style</label>
                            <select id="event-style">
                                <option>Banquet</option>
                                <option>Theater</option>
                                <option>Cocktail</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Event Type</label>
                        <div class="radio-group" id="event-type-group">
                            <label><input type="radio" name="event-type" value="Plain Hall" checked> Plain Hall</label>
                            <label><input type="radio" name="event-type" value="Wedding"> Wedding (+ ₱10,000)</label>
                            <label><input type="radio" name="event-type" value="Birthday"> Birthday (+ ₱5,000)</label>
                            <label><input type="radio" name="event-type" value="Others"> Others</label>
                        </div>
                        <input type="text" id="event-type-others" class="hidden custom-input" placeholder="Please specify your event type...">
                    </div>

                    <div class="form-group">
                        <label>Number of Guests</label>
                        <input type="number" id="event-guests" min="10" placeholder="e.g. 100">
                    </div>

                    <!-- Add-ons -->
                    <div class="addons-section">
                        <h3 class="addon-title">Enhance Your Event</h3>
                        
                        <div class="addon-block">
                            <label class="toggle-label"><input type="checkbox" id="check-catering"> Include Catering</label>
                            <div class="addon-content hidden" id="catering-options">
                                <div class="tier-cards">
                                    <label class="tier-card">
                                        <div class="tier-header"><input type="radio" name="catering-tier"> <h4>Silver Tier</h4></div>
                                        <p class="tier-desc">Standard Buffet</p>
                                        <span class="tier-price">₱750 / head</span>
                                        <ul class="tier-menu">
                                            <li>1 Soup, 1 Salad</li>
                                            <li>3 Main Courses (Pork, Chicken, Fish)</li>
                                            <li>Steamed Rice</li>
                                            <li>1 Dessert, Iced Tea</li>
                                        </ul>
                                    </label>
                                    <label class="tier-card">
                                        <div class="tier-header"><input type="radio" name="catering-tier"> <h4>Gold Tier</h4></div>
                                        <p class="tier-desc">Premium Course</p>
                                        <span class="tier-price">₱1,200 / head</span>
                                        <ul class="tier-menu">
                                            <li>Premium Soup & Salad</li>
                                            <li>4 Main Courses (Beef Included)</li>
                                            <li>Pasta Station</li>
                                            <li>2 Desserts, Bottomless Drinks</li>
                                        </ul>
                                    </label>
                                    <label class="tier-card">
                                        <div class="tier-header"><input type="radio" name="catering-tier"> <h4>Platinum Tier</h4></div>
                                        <p class="tier-desc">Luxury Dining</p>
                                        <span class="tier-price">₱1,800 / head</span>
                                        <ul class="tier-menu">
                                            <li>Gourmet Appetizers</li>
                                            <li>5 Main Courses (Seafood & Beef)</li>
                                            <li>Carving Station</li>
                                            <li>Dessert Buffet & Wine Toast</li>
                                        </ul>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="addon-block">
                            <label class="toggle-label"><input type="checkbox" id="check-rooms"> Reserve Hotel Rooms</label>
                            <div class="addon-content hidden" id="rooms-options">
                                <div class="mix-match">
                                    <div class="mix-row">
                                        <div class="mix-info">
                                            <img src="https://images.unsplash.com/photo-1611892440504-42a792e24d32?auto=format&fit=crop&w=150" alt="Deluxe">
                                            <div>
                                                <h5>Deluxe Room</h5>
                                                <p>2 Pax | ₱4,500 / night</p>
                                            </div>
                                        </div>
                                        <div class="counter"><button type="button" class="minus">-</button><span class="val">0</span><button type="button" class="plus">+</button></div>
                                    </div>
                                    <div class="mix-row">
                                        <div class="mix-info">
                                            <img src="https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?auto=format&fit=crop&w=150" alt="VIP">
                                            <div>
                                                <h5>VIP Suite</h5>
                                                <p>4 Pax | ₱8,500 / night</p>
                                            </div>
                                        </div>
                                        <div class="counter"><button type="button" class="minus">-</button><span class="val">0</span><button type="button" class="plus">+</button></div>
                                    </div>
                                    <div class="mix-row">
                                        <div class="mix-info">
                                            <img src="https://images.unsplash.com/photo-1566665797739-1674de7a421a?auto=format&fit=crop&w=150" alt="Standard">
                                            <div>
                                                <h5>Standard Room</h5>
                                                <p>2 Pax | ₱3,000 / night</p>
                                            </div>
                                        </div>
                                        <div class="counter"><button type="button" class="minus">-</button><span class="val">0</span><button type="button" class="plus">+</button></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="addon-block">
                            <label class="toggle-label"><input type="checkbox" id="check-av"> Premium A/V Setup</label>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="small-label">PAYMENT SCHEME</label>
                        <div class="radio-group">
                            <label><input type="radio" name="payment-scheme" value="100% Full" checked> 100% Full</label>
                            <label><input type="radio" name="payment-scheme" value="50% Downpayment"> 50% Downpayment</label>
                            <label><input type="radio" name="payment-scheme" value="20% Reservation"> 20% Reservation</label>
                        </div>
                    </div>
                </div>

                <!-- TAB 2: HOTEL ROOMS -->
                <div class="tab-content" id="tab-hotel-rooms">
                    <h2 class="section-title">Book a Hotel Room</h2>
                    
                    <div class="calendar-ui" id="cal-ui-hotel">
                        <div class="cal-header">
                            <button type="button" class="cal-nav prev-month">&larr;</button>
                            <h4 class="cal-month-year">October 2024</h4>
                            <button type="button" class="cal-nav next-month">&rarr;</button>
                        </div>
                        <div class="cal-weekdays">
                            <span>SUN</span><span>MON</span><span>TUE</span><span>WED</span><span>THU</span><span>FRI</span><span>SAT</span>
                        </div>
                        <div class="cal-days-grid"></div>
                        <div class="cal-legend">
                            <span class="legend-item"><span class="dot selected"></span> Selected</span>
                            <span class="legend-item"><span class="dot in-range-dot"></span> In Range</span>
                            <span class="legend-item"><span class="dot booked"></span> Booked</span>
                            <span class="legend-item"><span class="dot available"></span> Available</span>
                        </div>
                    </div>

                    <div class="dynamic-img-wrapper">
                        <img id="hotel-img" src="https://images.unsplash.com/photo-1611892440504-42a792e24d32?auto=format&fit=crop&w=800" alt="Hotel Room">
                    </div>

                    <div class="form-group">
                        <label>Room Type</label>
                        <select id="hotel-type">
                            <option value="deluxe">Deluxe Room</option>
                            <option value="vip">VIP Suite</option>
                            <option value="standard">Standard Room</option>
                        </select>
                        <small class="capacity-note">Base Capacity: 2 Pax | Maximum: 4 Pax</small>
                    </div>

                    <div class="form-group">
                        <label>Number of Guests</label>
                        <input type="number" id="hotel-guests" min="1" max="4" value="2">
                        <small class="extra-pax-note">Additional ₱800 per head exceeding base capacity. <span id="hotel-extra-fee"></span></small>
                    </div>

                    <div class="inclusions-card">
                        <div class="inc-col">
                            <h4>Room Features</h4>
                            <ul><li>King-sized Bed</li><li>En-suite Bathroom</li><li>Smart TV & Wi-Fi</li></ul>
                        </div>
                        <div class="inc-col">
                            <h4>Resort Perks</h4>
                            <ul><li>Free Breakfast for 2</li><li>Pool Access</li><li>Gym Access</li></ul>
                        </div>
                    </div>
                </div>

                <!-- TAB 3: RESORT VILLA -->
                <div class="tab-content" id="tab-resort-villa">
                    <h2 class="section-title">Reserve a Resort Villa</h2>

                    <div class="calendar-ui" id="cal-ui-villa">
                        <div class="cal-header">
                            <button type="button" class="cal-nav prev-month">&larr;</button>
                            <h4 class="cal-month-year">October 2024</h4>
                            <button type="button" class="cal-nav next-month">&rarr;</button>
                        </div>
                        <div class="cal-weekdays">
                            <span>SUN</span><span>MON</span><span>TUE</span><span>WED</span><span>THU</span><span>FRI</span><span>SAT</span>
                        </div>
                        <div class="cal-days-grid"></div>
                        <div class="cal-legend">
                            <span class="legend-item"><span class="dot selected"></span> Selected</span>
                            <span class="legend-item"><span class="dot in-range-dot"></span> In Range</span>
                            <span class="legend-item"><span class="dot booked"></span> Booked</span>
                            <span class="legend-item"><span class="dot available"></span> Available</span>
                        </div>
                    </div>

                    <div class="dynamic-img-wrapper">
                        <img id="villa-img" src="https://images.unsplash.com/photo-1582268611958-ebfd161ef9cf?auto=format&fit=crop&w=800" alt="Resort Villa">
                    </div>

                    <div class="form-group">
                        <label>Select Villa</label>
                        <select id="villa-type">
                            <option value="casita">La Casita (Poolside)</option>
                            <option value="hacienda">Hacienda Suite</option>
                        </select>
                        <small class="capacity-note">Base Capacity: 4 Pax | Maximum: 8 Pax</small>
                    </div>

                    <div class="form-group">
                        <label class="small-label" style="margin-top: 1.5rem;">STAY TYPE</label>
                        <div class="radio-group block-radios">
                            <label>
                                <input type="radio" name="villa-stay" value="Day Time Stay" checked> 
                                <span class="stay-title">Villa Day Time Stay — ₱3,500</span>
                            </label>
                            <label>
                                <input type="radio" name="villa-stay" value="Overnight"> 
                                <span class="stay-title">Villa Overnight — ₱6,500</span>
                            </label>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top: 1.5rem;">
                        <label>Number of Guests</label>
                        <input type="number" id="villa-guests" min="1" max="8" value="4">
                        <small class="extra-pax-note">Additional ₱1,000 per head exceeding base capacity. <span id="villa-extra-fee"></span></small>
                    </div>

                    <div class="inclusions-card villa-inclusions">
                        <div class="villa-rules">
                            <div class="rule-box" id="rule-day">
                                <strong>VILLA DAY TIME STAY</strong>
                                <p>- ₱3500 for 4 persons</p>
                                <p>- Check in: 7AM Check out: 5PM</p>
                            </div>
                            <div class="rule-box hidden" id="rule-night">
                                <strong>VILLA OVERNIGHT</strong>
                                <p>- ₱6500 for 4 persons</p>
                                <p>- Complimentary breakfast for 4 persons</p>
                                <p>- Check in: 2PM Check out: 12PM</p>
                            </div>
                        </div>

                        <div class="inc-col">
                            <h4 class="script-subtitle">What's inside the house?</h4>
                            <ul>
                                <li>TV</li>
                                <li>Bed</li>
                                <li>Airconditioner</li>
                                <li>Hot and cold shower</li>
                                <li>Refrigerator</li>
                                <li>Toiletry items (Toothbrush, toothpaste, soap)</li>
                            </ul>
                        </div>
                        <div class="inc-col" style="margin-top: 1.5rem;">
                            <h4 class="script-subtitle">What's outside the house?</h4>
                            <ul>
                                <li>Small private swimming pool</li>
                                <li>Garden</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RIGHT COLUMN: STICKY SUMMARY (35%) -->
            <div class="booking-sidebar">
                <div class="sticky-summary">
                    <h3 style="font-family: var(--font-heading); margin-bottom: 1.5rem; font-size: 1.6rem; border-bottom: 1px solid rgba(0,0,0,0.1); padding-bottom: 10px;">Booking Summary</h3>
                    
                    <div class="summary-container active" id="sum-event-hall">
                        <p><strong>Service:</strong> <span class="sum-val">Event Hall</span></p>
                        <p><strong>Venue:</strong> <span class="sum-val" id="sum-ev-venue">Grand Ballroom</span></p>
                        <p><strong>Event Type:</strong> <span class="sum-val" id="sum-ev-type">Plain Hall</span></p>
                        <p><strong>Dates:</strong> <span class="sum-val sum-dates-display">--</span></p>
                        <p><strong>Guests:</strong> <span class="sum-val" id="sum-ev-guests">--</span></p>
                        <p><strong>Payment Scheme:</strong> <span class="sum-val" id="sum-ev-payment">100% Full</span></p>
                    </div>

                    <div class="summary-container" id="sum-hotel-rooms">
                        <p><strong>Service:</strong> <span class="sum-val">Hotel Room</span></p>
                        <p><strong>Room Type:</strong> <span class="sum-val" id="sum-ht-type">Deluxe Room</span></p>
                        <p><strong>Dates:</strong> <span class="sum-val sum-dates-display">--</span></p>
                        <p><strong>Guests:</strong> <span class="sum-val" id="sum-ht-guests">2</span></p>
                        <p><strong>Extra Pax Fee:</strong> <span class="sum-val" id="sum-ht-fee">₱0</span></p>
                    </div>

                    <div class="summary-container" id="sum-resort-villa">
                        <p><strong>Service:</strong> <span class="sum-val">Resort Villa</span></p>
                        <p><strong>Villa:</strong> <span class="sum-val" id="sum-vl-type">La Casita (Poolside)</span></p>
                        <p><strong>Stay:</strong> <span class="sum-val" id="sum-vl-stay">Day Time Stay</span></p>
                        <p><strong>Dates:</strong> <span class="sum-val sum-dates-display">--</span></p>
                        <p><strong>Guests:</strong> <span class="sum-val" id="sum-vl-guests">4</span></p>
                        <p><strong>Extra Pax Fee:</strong> <span class="sum-val" id="sum-vl-fee">₱0</span></p>
                    </div>

                    <!-- Universal Summary Footer -->
                    <div class="summary-footer">
                        <div class="timer-box" id="timer-box">
                            <span id="timer-text">Select your dates to start session.</span>
                            <span id="countdown-wrapper" style="display: none;">Session expires in: <span id="countdown">30:00</span></span>
                        </div>
                        
                        <div class="terms-group">
                            <input type="checkbox" id="terms-check">
                            <label for="terms-check">I agree to the <a href="#" id="open-terms">Terms & Conditions</a></label>
                        </div>

                        <button class="btn btn-paymongo" id="btn-proceed">PROCEED VIA PAYMONGO</button>
                        <button class="btn btn-cancel" id="btn-cancel">CANCEL</button>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <!-- Date Confirmation Modal -->
    <div class="modal-overlay" id="date-confirm-modal">
        <div class="modal-content">
            <h3 style="font-family: var(--font-heading); font-size: 1.8rem; margin-bottom: 15px;">Confirm Dates</h3>
            <div class="modal-body" style="text-align: center; margin-bottom: 25px;">
                <p style="font-size: 1.1rem; color: var(--color-dark);">You have selected:<br><strong id="selected-date-text" style="color: var(--color-gold); display: block; margin-top: 10px; font-size: 1.2rem;"></strong></p>
                <p style="font-size: 0.9rem; margin-top: 15px;">Proceeding will lock these dates for 30 minutes while you complete your booking.</p>
            </div>
            <div style="display: flex; gap: 10px; justify-content: center;">
                <button class="btn btn-primary" id="btn-confirm-date">Confirm</button>
                <button class="btn btn-outline" id="btn-cancel-date" style="color: var(--color-dark); border-color: var(--color-dark);">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Override Lock Date Modal -->
    <div class="modal-overlay" id="override-date-modal">
        <div class="modal-content">
            <h3 style="font-family: var(--font-heading); font-size: 1.8rem; margin-bottom: 15px;">Change Dates?</h3>
            <div class="modal-body" style="text-align: center; margin-bottom: 25px;">
                <p style="font-size: 1.1rem; color: var(--color-dark);">You currently have dates locked for this session.</p>
                <p style="font-size: 0.9rem; margin-top: 10px;">Would you like to cancel your current selection and pick new dates?</p>
            </div>
            <div style="display: flex; gap: 10px; justify-content: center;">
                <button class="btn btn-primary" id="btn-override-yes">Yes, Change Dates</button>
                <button class="btn btn-outline" id="btn-override-no" style="color: var(--color-dark); border-color: var(--color-dark);">No, Keep Current</button>
            </div>
        </div>
    </div>

    <!-- T&C Modal -->
    <div class="modal-overlay" id="tnc-modal">
        <div class="modal-content">
            <h3>Terms and Conditions</h3>
            <div class="modal-body">
                <p>Welcome to Sevilla360 Booking System. By proceeding, you agree to our standard reservation rules, cancellation policies, and resort etiquette guidelines.</p>
                <p>1. All bookings are final upon payment processing.</p>
                <p>2. Maximum capacities are strictly implemented.</p>
                <p>3. Damage to resort property will be billed to the client's account.</p>
            </div>
            <button class="btn btn-primary" id="btn-agree">I Agree</button>
        </div>
    </div>

    <!-- Global Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-grid reveal">
                <div class="footer-col">
                    <h4 style="font-family: var(--font-heading); font-size: 1.5rem;">Sevilla360</h4>
                    <p>M.I. Sevilla Resort & Events Place<br>Where every event becomes a memory.</p>
                </div>
                <div class="footer-col">
                    <h4>Quick Links</h4>
                    <a href="#about">About Us</a><a href="#events">Our Venues</a><a href="#accommodations">Accommodations</a>
                </div>
                <div class="footer-col">
                    <h4>Contact Us</h4>
                    <p>+1 (800) 123-4567</p><p>reservations@sevilla360.com</p>
                </div>
                <div class="footer-col">
                    <h4>Newsletter</h4>
                    <p>Subscribe for exclusive offers.</p>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; <?php echo date("Y"); ?> M.I. Sevilla Resort.</p>
            </div>
        </div>
    </footer>

    <script src="assets/js/booking.js"></script>
</body>
</html>