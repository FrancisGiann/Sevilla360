<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking & Checkout - SEVILLA360</title>
    
    <!-- External Stylesheets -->
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/booking.css">
</head>
<body>

    <!-- Header / Navbar -->
    <nav class="navbar">
        <div class="container">
            <a href="index.php" class="navbar-brand">Sevilla360</a>
            
            <div class="hamburger" id="hamburger">
                <span></span><span></span><span></span>
            </div>

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
    <section class="booking-section bg-white">
        <div class="container checkout-layout">
            
            <!-- LEFT COLUMN: Booking Flow (65%) -->
            <div class="checkout-left">
                
                <!-- Working Tabs Navigation -->
                <div class="booking-tabs">
                    <div class="tab-item active" data-target="tab-hall" data-summary="summary-hall">Event Hall</div>
                    <div class="tab-item" data-target="tab-hotel" data-summary="summary-hotel">Hotel Rooms</div>
                    <div class="tab-item" data-target="tab-villa" data-summary="summary-villa">Resort Villa</div>
                </div>

                <!-- ========================================== -->
                <!-- TAB 1: EVENT HALL (Active by default)      -->
                <!-- ========================================== -->
                <div id="tab-hall" class="tab-content active">
                    
                    <!-- Calendar Section -->
                    <div class="calendar-wrapper">
                        <div class="calendar-header">
                            <span class="prev-month" style="cursor:pointer;">&#8592;</span>
                            <span>October 2024</span>
                            <span class="next-month" style="cursor:pointer;">&#8594;</span>
                        </div>
                        <div class="calendar-grid">
                            <div class="day-name">Sun</div><div class="day-name">Mon</div><div class="day-name">Tue</div><div class="day-name">Wed</div><div class="day-name">Thu</div><div class="day-name">Fri</div><div class="day-name">Sat</div>
                            <div></div><div></div>
                            <div class="day-cell unavailable">1</div><div class="day-cell unavailable">2</div>
                            <div class="day-cell booked">3</div><div class="day-cell available">4</div>
                            <div class="day-cell available">5</div><div class="day-cell available">6</div>
                            <div class="day-cell available">7</div><div class="day-cell booked">8</div>
                            <div class="day-cell available">9</div><div class="day-cell available">10</div>
                            <div class="day-cell available">11</div><div class="day-cell available">12</div>
                            <div class="day-cell available">13</div><div class="day-cell available">14</div>
                            <div class="day-cell selected">15</div><div class="day-cell available">16</div>
                            <div class="day-cell booked">17</div><div class="day-cell available">18</div>
                            <div class="day-cell available">19</div><div class="day-cell available">20</div>
                            <div class="day-cell available">21</div><div class="day-cell available">22</div>
                            <div class="day-cell available">23</div><div class="day-cell available">24</div>
                            <div class="day-cell available">25</div><div class="day-cell available">26</div>
                            <div class="day-cell available">27</div><div class="day-cell available">28</div>
                            <div class="day-cell available">29</div><div class="day-cell available">30</div>
                            <div class="day-cell available">31</div>
                        </div>
                        <div class="calendar-legend">
                            <div class="legend-item"><div class="legend-dot" style="background: var(--color-gold);"></div> Selected</div>
                            <div class="legend-item"><div class="legend-dot" style="background: #e74c3c;"></div> Booked</div>
                            <div class="legend-item"><div class="legend-dot" style="background: #2ecc71;"></div> Available</div>
                            <div class="legend-item"><div class="legend-dot" style="background: #e0e0e0;"></div> Unavailable</div>
                        </div>
                    </div>

                    <h3 class="section-title">Event Hall Details</h3>

                    <!-- Visual Feedback: Hall Image -->
                    <div class="venue-preview-container">
                        <img id="venue-image-display" src="https://images.unsplash.com/photo-1519225421980-715cb0215aed?ixlib=rb-4.0.3&auto=format&fit=crop&w=1200&q=80" alt="Venue Preview">
                    </div>
                    
                    <div style="display: flex; gap: 1.5rem;">
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label">Select Venue Space</label>
                            <select class="form-control" id="venue-selector">
                                <option value="main">Infinity Hall - Main Floor</option>
                                <option value="sunset">Infinity Hall - Sunset Deck</option>
                                <option value="garden">Infinity Hall - Grand Garden</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label">Select Style</label>
                            <select class="form-control">
                                <option>Banquet Style</option>
                                <option>Theater Style</option>
                                <option>U-Shape</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Event Type</label>
                        <div class="radio-group">
                            <label class="custom-radio"><input type="radio" name="event_type" checked> Plain Hall</label>
                            <label class="custom-radio"><input type="radio" name="event_type"> Wedding (+ ₱ 10,000)</label>
                            <label class="custom-radio"><input type="radio" name="event_type"> Birthday (+ ₱ 5,000)</label>
                            <label class="custom-radio"><input type="radio" name="event_type"> Others</label>
                        </div>
                    </div>

                    <div class="form-group" style="max-width: 200px;">
                        <label class="form-label">Number of Guests</label>
                        <input type="number" class="form-control" placeholder="e.g., 150" min="10">
                    </div>

                    <!-- Optional Add-ons -->
                    <h3 class="section-title mt-5" style="margin-top: 3rem;">Optional Add-ons</h3>
                    
                    <div class="addon-item">
                        <label class="custom-radio">
                            <input type="checkbox" id="addon-catering"> 
                            <span style="font-weight: 500;">Include Sevilla Catering Services</span>
                        </label>
                        
                        <div class="menu-card" id="catering-details">
                            <h4>Select Your Catering Package</h4>
                            <p style="font-size: 0.9rem; color: var(--color-dark-light); margin-bottom: 0.5rem;">Choose the perfect culinary tier for your guests.</p>
                            
                            <div class="catering-options-grid">
                                <label class="catering-option-card">
                                    <input type="radio" name="catering_tier" value="standard" checked>
                                    <h5>Silver Tier</h5>
                                    <p class="price">+ ₱ 1,000 / head</p>
                                    <ul>
                                        <li>2 Choice Appetizers</li>
                                        <li>2 Main Courses</li>
                                        <li>1 Standard Dessert</li>
                                        <li>Iced Tea & Water</li>
                                    </ul>
                                </label>
                                
                                <label class="catering-option-card">
                                    <input type="radio" name="catering_tier" value="deluxe">
                                    <h5>Gold Tier</h5>
                                    <p class="price">+ ₱ 1,500 / head</p>
                                    <ul>
                                        <li>3 Choice Appetizers</li>
                                        <li>3 Main Courses</li>
                                        <li>2 Premium Desserts</li>
                                        <li>Free-Flowing Juices</li>
                                    </ul>
                                </label>

                                <label class="catering-option-card">
                                    <input type="radio" name="catering_tier" value="premium">
                                    <h5>Platinum Tier</h5>
                                    <p class="price">+ ₱ 2,500 / head</p>
                                    <ul>
                                        <li>Unlimited Appetizers</li>
                                        <li>5 Main Courses</li>
                                        <li>Live Carving Station</li>
                                        <li>Open Bar (Local)</li>
                                    </ul>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Rooms Add-on (Mix & Match) -->
                    <div class="addon-item">
                        <label class="custom-radio">
                            <input type="checkbox" id="addon-rooms"> 
                            <span style="font-weight: 500;">Reserve Hotel Rooms for Event Guests</span>
                        </label>
                        
                        <div class="room-selection-wrapper" id="room-details">
                            <p style="font-size: 0.9rem; color: var(--color-dark-light); margin-bottom: 1rem;">Mix and match rooms to perfectly accommodate your VIPs and general attendees.</p>
                            
                            <div class="room-item">
                                <div class="room-info">
                                    <h5>VIP Presidential Suite</h5>
                                    <p>Panoramic ocean views, private jacuzzi, and butler service.</p>
                                    <span class="room-price">₱ 18,500 / night</span>
                                </div>
                                <div class="qty-control">
                                    <button type="button" class="qty-btn qty-minus">-</button>
                                    <input type="number" class="qty-input" value="0" min="0" readonly>
                                    <button type="button" class="qty-btn qty-plus">+</button>
                                </div>
                            </div>
                            
                            <div class="room-item">
                                <div class="room-info">
                                    <h5>Deluxe Ocean View</h5>
                                    <p>Spacious balcony, king-size premium bed, luxury amenities.</p>
                                    <span class="room-price">₱ 8,200 / night</span>
                                </div>
                                <div class="qty-control">
                                    <button type="button" class="qty-btn qty-minus">-</button>
                                    <input type="number" class="qty-input" value="0" min="0" readonly>
                                    <button type="button" class="qty-btn qty-plus">+</button>
                                </div>
                            </div>
                            
                            <div class="room-item">
                                <div class="room-info">
                                    <h5>Economy Standard Room</h5>
                                    <p>Comfortable essential room with garden view. Perfect for groups.</p>
                                    <span class="room-price">₱ 4,500 / night</span>
                                </div>
                                <div class="qty-control">
                                    <button type="button" class="qty-btn qty-minus">-</button>
                                    <input type="number" class="qty-input" value="0" min="0" readonly>
                                    <button type="button" class="qty-btn qty-plus">+</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="addon-item">
                        <label class="custom-radio">
                            <input type="checkbox"> 
                            <span style="font-weight: 500;">Full Audio/Visual & Lighting Setup (+ ₱ 15,000)</span>
                        </label>
                    </div>

                    <h3 class="section-title mt-5" style="margin-top: 3rem;">Payment Scheme</h3>
                    <div class="form-group">
                        <div class="radio-group">
                            <label class="custom-radio"><input type="radio" name="payment" checked> Full Payment (100%)</label>
                            <label class="custom-radio"><input type="radio" name="payment"> Down Payment (50%)</label>
                            <label class="custom-radio"><input type="radio" name="payment"> Reservation Fee (20%)</label>
                        </div>
                    </div>
                </div>


                <!-- ========================================== -->
                <!-- TAB 2: HOTEL ROOMS ONLY                    -->
                <!-- ========================================== -->
                <div id="tab-hotel" class="tab-content">
                    
                    <!-- Hotel Calendar Section -->
                    <div class="calendar-wrapper">
                        <div class="calendar-header">
                            <span class="prev-month" style="cursor:pointer;">&#8592;</span>
                            <span>October 2024 (Hotel Availability)</span>
                            <span class="next-month" style="cursor:pointer;">&#8594;</span>
                        </div>
                        <div class="calendar-grid">
                            <div class="day-name">Sun</div><div class="day-name">Mon</div><div class="day-name">Tue</div><div class="day-name">Wed</div><div class="day-name">Thu</div><div class="day-name">Fri</div><div class="day-name">Sat</div>
                            <div></div><div></div>
                            <div class="day-cell unavailable">1</div><div class="day-cell unavailable">2</div>
                            <div class="day-cell available">3</div><div class="day-cell available">4</div>
                            <div class="day-cell available">5</div><div class="day-cell booked">6</div>
                            <div class="day-cell booked">7</div><div class="day-cell available">8</div>
                            <div class="day-cell available">9</div><div class="day-cell available">10</div>
                            <div class="day-cell available">11</div><div class="day-cell available">12</div>
                            <div class="day-cell available">13</div><div class="day-cell available">14</div>
                            <div class="day-cell available">15</div><div class="day-cell available">16</div>
                            <div class="day-cell available">17</div><div class="day-cell available">18</div>
                            <div class="day-cell available">19</div><div class="day-cell available">20</div>
                            <div class="day-cell booked">21</div><div class="day-cell available">22</div>
                            <div class="day-cell available">23</div><div class="day-cell available">24</div>
                            <div class="day-cell available">25</div><div class="day-cell available">26</div>
                            <div class="day-cell available">27</div><div class="day-cell available">28</div>
                            <div class="day-cell available">29</div><div class="day-cell available">30</div>
                            <div class="day-cell available">31</div>
                        </div>
                        <div class="calendar-legend">
                            <div class="legend-item"><div class="legend-dot" style="background: var(--color-gold);"></div> Selected</div>
                            <div class="legend-item"><div class="legend-dot" style="background: #e74c3c;"></div> Fully Booked</div>
                            <div class="legend-item"><div class="legend-dot" style="background: #2ecc71;"></div> Available</div>
                        </div>
                    </div>

                    <h3 class="section-title">Direct Hotel Booking</h3>
                    <p style="color: var(--color-dark-light); margin-bottom: 2rem;">Please select your check-in and check-out dates using the calendar above.</p>
                    
                    <!-- Visual Feedback: Hotel Image -->
                    <div class="venue-preview-container">
                        <img id="hotel-image-display" src="https://images.unsplash.com/photo-1566665797739-1674de7a421a?ixlib=rb-4.0.3&auto=format&fit=crop&w=1200&q=80" alt="Hotel Room Preview">
                    </div>

                    <div style="display: flex; gap: 1.5rem; margin-bottom: 2rem;">
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label">Select Room Type</label>
                            <select class="form-control" id="hotel-selector">
                                <option value="deluxe">Deluxe Ocean View</option>
                                <option value="vip">VIP Presidential Suite</option>
                                <option value="standard">Economy Standard Room</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label">Number of Guests</label>
                            <input type="number" class="form-control" value="2" min="1">
                        </div>
                    </div>

                    <!-- Hotel Inclusions UI -->
                    <div class="menu-card" style="display: block; margin-bottom: 2rem;">
                        <h4>Room Inclusions & Amenities</h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-top: 1rem;">
                            <div>
                                <strong style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px; color: var(--color-dark-light);">Room Features</strong>
                                <ul style="list-style: none; padding: 0; margin-top: 10px; font-size: 0.9rem; color: var(--color-dark);">
                                    <li style="margin-bottom: 5px;">• Air-conditioning</li>
                                    <li style="margin-bottom: 5px;">• Smart Flat-screen TV</li>
                                    <li style="margin-bottom: 5px;">• Comfortable Premium Bedding</li>
                                    <li style="margin-bottom: 5px;">• En-suite Hot & Cold Shower</li>
                                    <li style="margin-bottom: 5px;">• Fresh Towels & Toiletries</li>
                                </ul>
                            </div>
                            <div>
                                <strong style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px; color: var(--color-dark-light);">Resort Perks</strong>
                                <ul style="list-style: none; padding: 0; margin-top: 10px; font-size: 0.9rem; color: var(--color-dark);">
                                    <li style="margin-bottom: 5px;">• Complimentary High-Speed Wi-Fi</li>
                                    <li style="margin-bottom: 5px;">• Access to Main Resort Pool</li>
                                    <li style="margin-bottom: 5px;">• Free Parking Space</li>
                                    <li style="margin-bottom: 5px;">• Daily Housekeeping</li>
                                    <li style="margin-bottom: 5px;">• Complimentary Bottled Water</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>


                <!-- ========================================== -->
                <!-- TAB 3: RESORT VILLA                        -->
                <!-- ========================================== -->
                <div id="tab-villa" class="tab-content">

                    <!-- Villa Calendar Section -->
                    <div class="calendar-wrapper">
                        <div class="calendar-header">
                            <span class="prev-month" style="cursor:pointer;">&#8592;</span>
                            <span>October 2024 (Villa Availability)</span>
                            <span class="next-month" style="cursor:pointer;">&#8594;</span>
                        </div>
                        <div class="calendar-grid">
                            <div class="day-name">Sun</div><div class="day-name">Mon</div><div class="day-name">Tue</div><div class="day-name">Wed</div><div class="day-name">Thu</div><div class="day-name">Fri</div><div class="day-name">Sat</div>
                            <div></div><div></div>
                            <div class="day-cell unavailable">1</div><div class="day-cell unavailable">2</div>
                            <div class="day-cell booked">3</div><div class="day-cell booked">4</div>
                            <div class="day-cell booked">5</div><div class="day-cell booked">6</div>
                            <div class="day-cell available">7</div><div class="day-cell available">8</div>
                            <div class="day-cell booked">9</div><div class="day-cell booked">10</div>
                            <div class="day-cell available">11</div><div class="day-cell available">12</div>
                            <div class="day-cell available">13</div><div class="day-cell booked">14</div>
                            <div class="day-cell booked">15</div><div class="day-cell booked">16</div>
                            <div class="day-cell available">17</div><div class="day-cell available">18</div>
                            <div class="day-cell booked">19</div><div class="day-cell booked">20</div>
                            <div class="day-cell available">21</div><div class="day-cell available">22</div>
                            <div class="day-cell booked">23</div><div class="day-cell booked">24</div>
                            <div class="day-cell booked">25</div><div class="day-cell available">26</div>
                            <div class="day-cell available">27</div><div class="day-cell available">28</div>
                            <div class="day-cell available">29</div><div class="day-cell booked">30</div>
                            <div class="day-cell booked">31</div>
                        </div>
                        <div class="calendar-legend">
                            <div class="legend-item"><div class="legend-dot" style="background: var(--color-gold);"></div> Selected</div>
                            <div class="legend-item"><div class="legend-dot" style="background: #e74c3c;"></div> Booked</div>
                            <div class="legend-item"><div class="legend-dot" style="background: #2ecc71;"></div> Available</div>
                        </div>
                    </div>

                    <h3 class="section-title">Exclusive Resort Villas</h3>
                    <p style="color: var(--color-dark-light); margin-bottom: 2rem;">Please select your desired dates using the calendar above.</p>
                    
                    <!-- Visual Feedback: Villa Image -->
                    <div class="venue-preview-container">
                        <img id="villa-image-display" src="https://images.unsplash.com/photo-1582268611958-ebfd161ef9cf?ixlib=rb-4.0.3&auto=format&fit=crop&w=1200&q=80" alt="Villa Preview">
                    </div>

                    <div class="form-group" style="margin-bottom: 2rem;">
                        <label class="form-label">Select Villa</label>
                        <select class="form-control" id="villa-selector">
                            <option value="grand">The Grand Sevilla Villa (12 Pax)</option>
                            <option value="oceanfront">Oceanfront Pool Villa (8 Pax)</option>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom: 1.5rem;">
                        <label class="form-label">Select Stay Type</label>
                        <div class="radio-group" style="flex-direction: column; gap: 1rem;">
                            <label class="custom-radio">
                                <input type="radio" name="stay_type" checked> 
                                <div>
                                    <strong style="display: block; font-size: 1rem;">Villa Day Time Stay — ₱3,500</strong>
                                    <span style="font-size: 0.85rem; color: var(--color-dark-light);">7:00 AM - 5:00 PM (Good for 4 Persons)</span>
                                </div>
                            </label>
                            <label class="custom-radio">
                                <input type="radio" name="stay_type"> 
                                <div>
                                    <strong style="display: block; font-size: 1rem;">Villa Overnight — ₱6,500</strong>
                                    <span style="font-size: 0.85rem; color: var(--color-dark-light);">2:00 PM - 12:00 NN (Good for 4 Persons, includes complimentary breakfast)</span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Villa Inclusions UI -->
                    <div class="menu-card" style="display: block; margin-bottom: 2rem;">
                        <h4>What's included in your stay?</h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-top: 1rem;">
                            <div>
                                <strong style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px; color: var(--color-dark-light);">What's inside the house?</strong>
                                <ul style="list-style: none; padding: 0; margin-top: 10px; font-size: 0.9rem; color: var(--color-dark);">
                                    <li style="margin-bottom: 5px;">• Flat-screen TV</li>
                                    <li style="margin-bottom: 5px;">• Comfortable Bed</li>
                                    <li style="margin-bottom: 5px;">• Airconditioner</li>
                                    <li style="margin-bottom: 5px;">• Hot and Cold Shower</li>
                                    <li style="margin-bottom: 5px;">• Refrigerator</li>
                                    <li style="margin-bottom: 5px;">• Toiletry Items (Toothbrush, paste, soap, shampoo)</li>
                                </ul>
                            </div>
                            <div>
                                <strong style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px; color: var(--color-dark-light);">What's outside the house?</strong>
                                <ul style="list-style: none; padding: 0; margin-top: 10px; font-size: 0.9rem; color: var(--color-dark);">
                                    <li style="margin-bottom: 5px;">• Small Private Swimming Pool</li>
                                    <li style="margin-bottom: 5px;">• Private Garden Area</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- RIGHT COLUMN: Sticky Summary (35%) -->
            <div class="checkout-right">
                <div class="summary-card">
                    <h4 class="summary-title">Booking Summary</h4>
                    
                    <!-- SUMMARY CONTAINER 1: EVENT HALL (Active by default) -->
                    <div id="summary-hall" class="summary-container active">
                        <ul class="summary-list">
                            <li><span>Venue:</span> <span style="font-weight:500;">Infinity Hall - Main Floor</span></li>
                            <li><span>Date:</span> <span style="font-weight:500;">Oct 15, 2024</span></li>
                            <li><span>Style:</span> <span style="font-weight:500;">Banquet Style</span></li>
                            <li><span>Event Type:</span> <span style="font-weight:500;">Plain Hall</span></li>
                            <li><span>Guests:</span> <span style="font-weight:500;">150 Pax</span></li>
                            <br>
                            <li><span>Base Venue Rate:</span> <span>₱ 45,000.00</span></li>
                            <li><span>Add-ons:</span> <span>₱ 0.00</span></li>
                            <li><span>Subtotal:</span> <span>₱ 45,000.00</span></li>
                        </ul>
                        <div class="summary-total">
                            <span>TOTAL DUE:</span>
                            <span>₱ 45,000.00</span>
                        </div>
                    </div>

                    <!-- SUMMARY CONTAINER 2: HOTEL ROOMS -->
                    <div id="summary-hotel" class="summary-container">
                        <ul class="summary-list">
                            <li><span style="color:var(--color-dark-light);">Select dates on the left to view summary.</span></li>
                        </ul>
                        <div class="summary-total" style="border-top: none; color: #999;">
                            <span>TOTAL DUE:</span>
                            <span>₱ 0.00</span>
                        </div>
                    </div>

                    <!-- SUMMARY CONTAINER 3: VILLA -->
                    <div id="summary-villa" class="summary-container">
                        <ul class="summary-list">
                            <li><span style="color:var(--color-dark-light);">Select dates on the left to view summary.</span></li>
                        </ul>
                        <div class="summary-total" style="border-top: none; color: #999;">
                            <span>TOTAL DUE:</span>
                            <span>₱ 0.00</span>
                        </div>
                    </div>

                    <!-- Universal Sticky Bottom Elements -->
                    <div class="timer-box mt-3">
                        Session Expires in <span class="timer-text" id="countdown-timer">30:00</span>
                    </div>
                    <div class="tc-group">
                        <input type="checkbox" id="tc-checkbox" style="accent-color: var(--color-gold); margin-top: 3px;">
                        <label for="tc-checkbox">I have read and agree to the <span class="tc-link" id="open-tc">Terms and Conditions</span>.</label>
                    </div>
                    <div class="action-buttons">
                        <button class="btn btn-primary btn-full">PROCEED PAYMENT VIA PAYMONGO</button>
                        <button class="btn btn-cancel btn-full">CANCEL</button>
                    </div>

                </div>
            </div>

        </div>
    </section>

    <!-- Terms and Conditions Modal -->
    <div class="modal-overlay" id="tc-modal">
        <div class="modal-content">
            <h3 style="font-family: var(--font-heading); color: var(--color-gold);">Terms & Conditions</h3>
            <div style="font-size: 0.9rem; color: var(--color-dark-light); margin-bottom: 2rem; max-height: 300px; overflow-y: auto; padding-right: 10px;">
                <p><strong>1. Reservations & Deposits</strong><br>A non-refundable reservation fee is required to secure your requested date. Full payment must be cleared 7 days prior to the event.</p><br>
                <p><strong>2. Cancellations</strong><br>Cancellations made within 30 days of the event will result in forfeiture of the 50% down payment. No-shows will be charged the full amount.</p><br>
                <p><strong>3. Damages</strong><br>The client is responsible for any damage to resort property caused by guests or external suppliers.</p><br>
            </div>
            <button class="btn btn-primary" id="agree-btn" style="width: 100%;">I AGREE</button>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-grid reveal">
                <div class="footer-col">
                    <h4 style="font-family: var(--font-heading); font-size: 1.5rem;">Sevilla360</h4>
                    <p>M.I. Sevilla Resort & Events Place<br>Where every event becomes a memory.</p>
                </div>
                <div class="footer-col">
                    <h4>Quick Links</h4>
                    <a href="#about">About Us</a>
                    <a href="#events">Our Venues</a>
                    <a href="#accommodations">Accommodations</a>
                </div>
                <div class="footer-col">
                    <h4>Contact Us</h4>
                    <p>+1 (800) 123-4567</p>
                    <p>reservations@sevilla360.com</p>
                </div>
                <div class="footer-col">
                    <h4>Newsletter</h4>
                    <p>Subscribe for exclusive offers and updates.</p>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; <?php echo date("Y"); ?> M.I. Sevilla Resort. Powered by Sevilla360.</p>
            </div>
        </div>
    </footer>

    <!-- Script -->
    <script src="assets/js/booking.js"></script>

</body>
</html>