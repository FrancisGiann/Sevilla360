<!-- EVENT HALL TAB -->
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

    <!-- ADD-ONS  -->
    <?php include 'includes/partials/addons_section.php'; ?>
</div>