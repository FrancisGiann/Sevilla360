<?php
require_once 'config/db_connect.php';

// Fetch all available venues grouped by category
$venues_query = $conn->query("SELECT category, name FROM venues WHERE status = 'Available' ORDER BY category, name");
$grouped_venues = [
    'Event Hall' => [],
    'Hotel Room' => [],
    'Resort Villa' => []
];

while ($row = $venues_query->fetch_assoc()) {
    $grouped_venues[$row['category']][] = $row['name'];
}
?>

<script>
window.venueData = <?php echo json_encode($grouped_venues); ?>;
</script>

<div class="admin-maintenance-container admin-booking-container">

    <!-- Top Section: Venue Selection -->
    <div class="maintenance-venue-section">
        <label class="small-label"
            style="display: block; margin-bottom: 15px; font-weight: 600; font-size: 0.95rem;">SELECT VENUE
            CATEGORY</label>

        <!-- COMBINED TABS -->
        <div class="booking-tabs venue-tabs" id="maintenance-tabs">
            <button class="tab-btn active" data-venue="Event Hall">Event Hall</button>
            <button class="tab-btn" data-venue="Resort Villa">Resort Villa</button>
            <button class="tab-btn" data-venue="Hotel Room">Hotel Room</button>
        </div>
    </div>

    <div class="maintenance-grid">
        <!-- Middle Section: Calendar & Forms -->
        <div class="maintenance-main">

            <!-- Calendar UI -->
            <div style="margin-bottom: 20px;">
                <label class="small-label" style="display: block; margin-bottom: 10px; font-weight: 600;">AVAILABILITY
                    CALENDAR</label>
                <?php
        $calendarId = 'cal-ui-maint';
        include 'includes/partials/booking_calendar.php';
    ?>
            </div>

            <!-- Form Inputs Section -->
            <div class="booking-card form-section" style="padding: 40px;">

                <!-- NEW DYNAMIC SPECIFIC VENUE DROPDOWN -->
                <div class="form-group">
                    <label for="maint-specific-venue" id="label-specific-venue" style="text-transform: uppercase;">WHICH
                        EVENT HALL?</label>
                    <select id="maint-specific-venue">
                        <!-- Options injected by JavaScript based on active tab -->
                    </select>
                </div>

                <div class="form-group">
                    <label for="maint-area">SPECIFIC AFFECTED AREA <span
                            style="color: #888; font-weight: 400; font-size: 0.85rem;">(Optional)</span></label>
                    <input type="text" id="maint-area" placeholder="e.g., Master Bathroom, Air Conditioning Unit...">
                </div>

                <div class="form-group">
                    <label for="maint-type">MAINTENANCE TYPE</label>
                    <select id="maint-type">
                        <option value="" disabled selected>Select a type...</option>
                        <option value="Electrical / Wiring">Electrical / Wiring</option>
                        <option value="Plumbing">Plumbing</option>
                        <option value="Deep Cleaning">Deep Cleaning</option>
                        <option value="Renovation">Renovation</option>
                        <option value="Pool / Garden Maintenance">Pool / Garden Maintenance</option>
                        <option value="General Inspection">General Inspection</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="maint-notes">DESCRIPTION / NOTES</label>
                    <textarea id="maint-notes" rows="4"
                        placeholder="Add specific details regarding the maintenance..."></textarea>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="toggle-label-ui" for="maint-block">
                        <span class="toggle-text">BLOCK UNIT FROM NEW BOOKINGS</span>
                        <div class="custom-toggle">
                            <input type="checkbox" id="maint-block">
                            <span class="toggle-slider"></span>
                        </div>
                    </label>
                </div>
            </div>
        </div>

        <!-- Right Section: Summary Sidebar -->
        <div class="maintenance-sidebar">
            <div class="sticky-summary checkout-summary" style="margin-top: 0;">
                <h3 class="summary-title">Maintenance Summary</h3>

                <div class="summary-container active">
                    <p>Category <span class="sum-val" id="sum-maint-category"
                            style="color: var(--color-dark); font-weight: 500;">Event Hall</span></p>
                    <p>Unit <span class="sum-val" id="sum-maint-unit"
                            style="color: var(--color-gold); font-weight: 600;">--</span></p>
                    <p>Date <span class="sum-val" id="sum-maint-date">--</span></p>
                    <p>Duration <span class="sum-val" id="sum-maint-duration">--</span></p>
                    <p>Area <span class="sum-val" id="sum-maint-area">--</span></p>
                    <p>Type <span class="sum-val" id="sum-maint-type">--</span></p>
                    <p style="border-bottom: none;">Booking Block
                        <span class="sum-val" id="sum-maint-block" style="font-weight: 600; color: #888;">OFF</span>
                    </p>
                </div>

                <div class="action-buttons" style="margin-top: 30px;">
                    <button class="btn btn-confirm-walkin" id="btn-schedule-maint"
                        style="width: 100%; margin-bottom: 15px;">SCHEDULE MAINTENANCE</button>
                    <button class="btn btn-modal-outline" id="btn-clear-maint"
                        style="width: 100%; color: var(--color-dark-light); border-color: rgba(42, 37, 34, 0.2);">CLEAR
                        FORM</button>
                </div>
            </div>
        </div>
    </div>
</div>