<!-- EVENT HALL TAB -->
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
            <span class="legend-item"><span class="dot booked"></span> Booked</span>
            <span class="legend-item"><span class="dot available"></span> Available</span>
            <span class="legend-item"><span class="dot unavailable"></span> Unavailable</span>
        </div>
    </div>

    <div class="dynamic-img-wrapper">
        <img id="event-img"
            src="https://images.unsplash.com/photo-1519225421980-715cb0215aed?auto=format&fit=crop&w=800"
            alt="Event Hall">
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
        <input type="text" id="event-type-others" class="hidden custom-input"
            placeholder="Please specify your event type...">
    </div>

    <div class="form-group">
        <label>Number of Guests</label>
        <input type="number" id="event-guests" min="10" placeholder="e.g. 100">
    </div>


</div>