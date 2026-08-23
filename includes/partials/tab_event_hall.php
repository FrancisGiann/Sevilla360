<?php
// Fetch system settings safely for dynamic pricing
if (!isset($sys_settings)) {
    $sys_query = $conn->query("SELECT setting_key, setting_value FROM system_settings");
    $sys_settings = [];
    if ($sys_query) {
        while($r = $sys_query->fetch_assoc()) {
            $sys_settings[$r['setting_key']] = $r['setting_value'];
        }
    }
}
$type_wed = $sys_settings['event_type_wedding'] ?? 10000;
$type_bday = $sys_settings['event_type_birthday'] ?? 5000;

// Default image: first hall's CMS image or placeholder
$default_event_img = (!empty($event_halls) && !empty($event_halls[0]['image']))
    ? $event_halls[0]['image']
    : 'assets/img/placeholder.jpg';
?>

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
            <li><strong>Hold the Date:</strong> Submit this inquiry to temporarily flag your date (No payment required
                yet).</li>
            <li><strong>Consultation:</strong> We will call you within 24 hours to discuss menus, themes, and exact
                guest counts.</li>
            <li><strong>Downpayment:</strong> Once details are finalized, you can pay your downpayment via your User
                Dashboard.</li>
        </ol>
    </div>

    <div class="dynamic-img-wrapper">
        <img id="event-img"
            src="<?php echo htmlspecialchars($default_event_img); ?>"
            alt="Event Hall">
    </div>

    <div class="form-row">
        <!-- 1. WHAT: DYNAMIC DATABASE DROPDOWN (WITH CAPACITIES) -->
        <div class="form-group" style="width: 100%;">
            <label>Select Venue Space</label>
            <select id="event-venue">
                <option value="" disabled selected>Select an Event Hall...</option>
                <?php foreach($event_halls as $hall): ?>
                <option value="<?php echo $hall['base_rate']; ?>" data-id="<?php echo $hall['id']; ?>"
                    data-name="<?php echo htmlspecialchars($hall['name']); ?>" data-type="Event Hall"
                    data-theater="<?php echo $hall['capacity_theater'] ?? 0; ?>"
                    data-classroom="<?php echo $hall['capacity_classroom'] ?? 0; ?>"
                    data-banquet="<?php echo $hall['capacity_banquet'] ?? 0; ?>"
                    data-img="<?php echo htmlspecialchars($hall['image']); ?>">
                    <?php echo htmlspecialchars($hall['name']); ?> (Base Rate:
                    ₱<?php echo number_format($hall['base_rate']); ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Event Setup / Seating Style</label>
            <select id="event-style">
                <option value="theater">Theater Style</option>
                <option value="classroom">Classroom Style</option>
                <option value="banquet">Banquet Type</option>
            </select>
        </div>
    </div>

    <div class="form-group">
        <label>What are you celebrating?</label>
        <div class="radio-group" id="event-type-group">
            <label><input type="radio" name="event-type" value="0" data-text="Plain Hall" checked> Plain Hall</label>
            <!-- The customer doesn't see the price, but the system calculates it! -->
            <label><input type="radio" name="event-type" value="<?php echo $type_wed; ?>" data-text="Wedding">
                Wedding</label>
            <label><input type="radio" name="event-type" value="<?php echo $type_bday; ?>" data-text="Birthday">
                Birthday</label>
            <label><input type="radio" name="event-type" value="0" id="event-others-radio" data-text="Custom Event">
                Others</label>
        </div>
        <input type="text" id="event-type-others" class="hidden custom-input"
            placeholder="Please specify your event type (e.g. Corporate Seminar)...">
    </div>

    <div style="margin-top: 2rem; margin-bottom: 2rem;">
        <label class="small-label">SELECT INQUIRY DATES</label>
        <?php
        $calendarId = 'cal-ui-event';
        include 'includes/partials/booking_calendar.php';
        ?>
        <p style="color: #c27c7c; font-size: 0.85rem; margin-top: 10px; font-weight: 500;">
            <i>* Note: Dates are subject to availability. Multiple inquiries may be received for the same date. The slot
                is awarded to the first finalized contract.</i>
        </p>
    </div>

    <div class="form-group">
        <label>Estimated Number of Guests</label>
        <input type="number" id="event-guests" min="10" placeholder="e.g. 100">
        <small style="display:block; margin-top:5px; color:#888;">Note: Maximum capacity varies depending on your chosen
            Event Setup Style.</small>
    </div>

    <?php include 'includes/partials/addons_section.php'; ?>
</div>
