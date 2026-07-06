<!-- RESORT VILLA  TAB-->
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
            <span class="legend-item"><span class="dot booked"></span> Booked</span>
            <span class="legend-item"><span class="dot available"></span> Available</span>
            <span class="legend-item"><span class="dot unavailable"></span> Unavailable</span>
        </div>
    </div>

    <div class="dynamic-img-wrapper">
        <img id="villa-img"
            src="https://images.unsplash.com/photo-1582268611958-ebfd161ef9cf?auto=format&fit=crop&w=800"
            alt="Resort Villa">
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
        <small class="extra-pax-note">Additional ₱1,000 per head exceeding base capacity. <span
                id="villa-extra-fee"></span></small>
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