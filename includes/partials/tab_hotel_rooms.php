<!-- HOTEL ROOMS TAB -->
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
            <span class="legend-item"><span class="dot booked"></span> Booked</span>
            <span class="legend-item"><span class="dot available"></span> Available</span>
            <span class="legend-item"><span class="dot unavailable"></span> Unavailable</span>
        </div>
    </div>

    <div class="dynamic-img-wrapper">
        <img id="hotel-img"
            src="https://images.unsplash.com/photo-1611892440504-42a792e24d32?auto=format&fit=crop&w=800"
            alt="Hotel Room">
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
</div>