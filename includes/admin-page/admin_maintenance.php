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
        <label class="small-label maint-section-label">SELECT VENUE CATEGORY</label>

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
            <div class="maint-calendar-wrapper">
                <?php
                    $calendarId = 'cal-ui-maint';
                    include 'includes/partials/booking_calendar.php';
                ?>
            </div>

            <!-- Form Inputs Section -->
            <div class="booking-card form-section maint-form-card">

                <!-- NEW DYNAMIC SPECIFIC VENUE DROPDOWN -->
                <div class="form-group">
                    <label for="maint-specific-venue" id="label-specific-venue" class="maint-uppercase-label">WHICH
                        EVENT HALL?</label>
                    <select id="maint-specific-venue">
                        <!-- Options injected by JavaScript based on active tab -->
                    </select>
                </div>

                <div class="form-group">
                    <label for="maint-area">SPECIFIC AFFECTED AREA <span
                            class="maint-optional-text">(Optional)</span></label>
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

                <div class="form-group maint-mb-0">
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
            <div class="sticky-summary checkout-summary maint-summary-box">
                <h3 class="summary-title">Maintenance Summary</h3>

                <div class="summary-container active">
                    <p>Category <span class="sum-val maint-sum-category" id="sum-maint-category">Event Hall</span></p>
                    <p>Unit <span class="sum-val maint-sum-unit" id="sum-maint-unit">--</span></p>
                    <p>Date <span class="sum-val" id="sum-maint-date">--</span></p>
                    <p>Duration <span class="sum-val" id="sum-maint-duration">--</span></p>
                    <p>Area <span class="sum-val" id="sum-maint-area">--</span></p>
                    <p>Type <span class="sum-val" id="sum-maint-type">--</span></p>
                    <p class="maint-sum-last-row">Booking Block <span class="sum-val maint-sum-block"
                            id="sum-maint-block">OFF</span></p>
                </div>

                <div class="action-buttons maint-action-buttons">
                    <button class="btn btn-confirm-walkin maint-btn-full" id="btn-schedule-maint">SCHEDULE
                        MAINTENANCE</button>
                    <button class="btn btn-modal-outline maint-btn-clear" id="btn-clear-maint">CLEAR FORM</button>
                </div>
            </div>
        </div>
    </div>
</div>