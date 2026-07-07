<!-- RESORT VILLA TAB -->
<div class="tab-content" id="tab-villa">
    <!--- CALENDAR UI -->
    <?php
    $calendarId = 'cal-ui-villa';
    include 'includes/partials/booking_calendar.php';
    ?>

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