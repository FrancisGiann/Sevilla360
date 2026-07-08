<!-- EVENT HALL TAB -->
<div class="tab-content active" id="tab-event-hall">
    <h2 class="section-title">Reserve an Event Hall</h2>

    <!--  Calendar UI -->
    <?php
    $calendarId = 'cal-ui-event';
    include 'includes/partials/booking_calendar.php';
    ?>

    <div class="dynamic-img-wrapper">
        <img id="event-img"
            src="https://images.unsplash.com/photo-1519225421980-715cb0215aed?auto=format&fit=crop&w=800"
            alt="Event Hall">
    </div>

    <!-- DYNAMIC DATABASE DROPDOWN -->
    <div class="form-row">
        <div class="form-group">
            <label>Select Venue Space</label>
            <select id="event-venue">
                <option value="" disabled selected>Select an Event Hall...</option>
                <?php foreach($event_halls as $hall): ?>
                <!-- ADDED data-name AND data-type HERE -->
                <option value="<?php echo $hall['base_rate']; ?>" data-id="<?php echo $hall['id']; ?>"
                    data-name="<?php echo htmlspecialchars($hall['name']); ?>" data-type="Event Hall">
                    <?php echo htmlspecialchars($hall['name']); ?> (₱<?php echo number_format($hall['base_rate']); ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Select Style</label>
            <select id="event-style">
                <option value="0">Minimalist (Standard) - ₱0</option>
                <option value="5000">Classic Elegance (+₱5,000)</option>
            </select>
        </div>
    </div>

    <div class="form-group">
        <label>Event Type</label>
        <div class="radio-group" id="event-type-group">
            <!-- value is the price, data-text is the name! -->
            <label><input type="radio" name="event-type" value="0" data-text="Plain Hall" checked> Plain Hall</label>
            <label><input type="radio" name="event-type" value="10000" data-text="Wedding"> Wedding (+ ₱10,000)</label>
            <label><input type="radio" name="event-type" value="5000" data-text="Birthday"> Birthday (+ ₱5,000)</label>
            <label><input type="radio" name="event-type" value="0" id="event-others-radio" data-text="Custom Event">
                Others</label>
        </div>
        <input type="text" id="event-type-others" class="hidden custom-input"
            placeholder="Please specify your event type...">
    </div>

    <div class="form-group">
        <label>Number of Guests</label>
        <input type="number" id="event-guests" min="10" placeholder="e.g. 100">
    </div>

    <!-- ADD-ONS -->
    <?php include 'includes/partials/addons_section.php'; ?>

    <div class="form-group">
        <label class="small-label">PAYMENT SCHEME</label>
        <div class="radio-group">
            <label><input type="radio" name="payment-scheme" value="100% Full" checked> 100%
                Full</label>
            <label><input type="radio" name="payment-scheme" value="50% Downpayment"> 50%
                Downpayment</label>
            <label><input type="radio" name="payment-scheme" value="20% Reservation"> 20%
                Reservation</label>
        </div>
    </div>


</div>