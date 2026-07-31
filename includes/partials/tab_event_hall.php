<!-- EVENT HALL TAB -->
<div class="tab-content active" id="tab-event-hall">
    <h2 class="section-title">Event Inquiry & Date Reservation</h2>
    <p style="color: var(--color-dark-light); margin-bottom: 20px;">
        Every event is unique. Use this form to hold your preferred date and give us a rough estimate of your needs. Our
        Event Coordinator will contact you to finalize the catering, styling, and final contract!
    </p>

    <div
        style="background: #fdf2e2; border-left: 4px solid var(--color-gold); padding: 15px 20px; border-radius: 4px; margin-bottom: 25px;">
        <h4 style="margin-top: 0; margin-bottom: 10px; color: var(--color-dark); font-size: 1rem;">📅 How Event Booking
            Works:</h4>
        <ol style="margin: 0; padding-left: 20px; font-size: 0.9rem; color: var(--color-dark); line-height: 1.6;">
            <li><strong>Hold the Date:</strong> Submit this inquiry to temporarily lock your date (No payment required
                yet).</li>
            <li><strong>Consultation:</strong> We will call you within 24 hours to discuss menus, themes, and exact
                guest counts.</li>
            <li><strong>Downpayment:</strong> Once details are finalized, you can pay your 50% downpayment via your User
                Dashboard.</li>
        </ol>
    </div>

    <div class="dynamic-img-wrapper">
        <img id="event-img"
            src="https://images.unsplash.com/photo-1519225421980-715cb0215aed?auto=format&fit=crop&w=800"
            alt="Event Hall">
    </div>

    <!-- 1. WHAT: DYNAMIC DATABASE DROPDOWN & EVENT TYPE -->
    <div class="form-row">
        <div class="form-group">
            <label>Select Venue Space</label>
            <select id="event-venue">
                <option value="" disabled selected>Select an Event Hall...</option>
                <?php foreach($event_halls as $hall): ?>
                <option value="<?php echo $hall['base_rate']; ?>" data-id="<?php echo $hall['id']; ?>"
                    data-name="<?php echo htmlspecialchars($hall['name']); ?>" data-type="Event Hall">
                    <?php echo htmlspecialchars($hall['name']); ?> (Base Rate:
                    ₱<?php echo number_format($hall['base_rate']); ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Estimated Event Style</label>
            <select id="event-style">
                <option value="0">Minimalist (Standard Setup)</option>
                <option value="0">Classic Elegance Setup</option>
            </select>
        </div>
    </div>

    <div class="form-group">
        <label>What are you celebrating?</label>
        <div class="radio-group" id="event-type-group">
            <label><input type="radio" name="event-type" value="0" data-text="Plain Hall" checked> Plain Hall</label>
            <label><input type="radio" name="event-type" value="0" data-text="Wedding"> Wedding</label>
            <label><input type="radio" name="event-type" value="0" data-text="Birthday"> Birthday</label>
            <label><input type="radio" name="event-type" value="0" id="event-others-radio" data-text="Custom Event">
                Others</label>
        </div>
        <input type="text" id="event-type-others" class="hidden custom-input"
            placeholder="Please specify your event type (e.g. Corporate Seminar)...">
    </div>

    <!-- 2. WHEN: CALENDAR UI -->
    <div style="margin-top: 2rem; margin-bottom: 2rem;">
        <label class="small-label">HOLD YOUR DATES</label>
        <?php
        $calendarId = 'cal-ui-event';
        include 'includes/partials/booking_calendar.php';
        ?>
    </div>

    <!-- 3. WHO & EXTRAS: GUESTS AND ADD-ONS -->
    <div class="form-group">
        <label>Estimated Number of Guests</label>
        <input type="number" id="event-guests" min="10" placeholder="e.g. 100">
        <small style="display:block; margin-top:5px; color:#888;">Don't worry, you can adjust this later during
            consultation.</small>
    </div>

    <!-- ADD-ONS -->
    <?php include 'includes/partials/addons_section.php'; ?>

</div>