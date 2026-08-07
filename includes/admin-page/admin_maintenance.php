<?php
require_once 'config/db_connect.php';

// Fetch Upcoming Maintenance
$upcoming_maint = $conn->query("
    SELECT m.id, m.start_date, m.end_date, m.maintenance_type, m.is_blocking, v.name as venue_name 
    FROM maintenance m 
    JOIN venues v ON m.venue_id = v.id 
    WHERE m.end_date >= CURDATE() 
    ORDER BY m.start_date ASC
");

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
            <button class="tab-btn" data-venue="Hotel Room">Hotel Room</button>
            <button class="tab-btn" data-venue="Resort Villa">Resort Villa</button>
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
    <!-- BOTTOM SECTION: UPCOMING MAINTENANCE TABLE -->
    <div class="table-card" style="margin-top: 30px;">
        <h3 class="card-title">Active & Upcoming Maintenance</h3>
        <div class="table-responsive">
            <table class="bookings-table">
                <thead>
                    <tr>
                        <th>VENUE</th>
                        <th>START DATE</th>
                        <th>END DATE</th>
                        <th>TYPE</th>
                        <th>STATUS</th>
                        <th>ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($upcoming_maint && $upcoming_maint->num_rows > 0): ?>
                    <?php while($m = $upcoming_maint->fetch_assoc()): ?>
                    <tr>
                        <td style="font-weight: 600;"><?php echo htmlspecialchars($m['venue_name']); ?></td>
                        <td><?php echo date('M j, Y', strtotime($m['start_date'])); ?></td>
                        <td><?php echo date('M j, Y', strtotime($m['end_date'])); ?></td>
                        <td><?php echo htmlspecialchars($m['maintenance_type']); ?></td>
                        <td>
                            <?php if($m['is_blocking']): ?>
                            <span class="status-badge status-refunded"
                                style="background:#fee2e2; color:#dc2626;">Blocked</span>
                            <?php else: ?>
                            <span class="status-badge status-paid" style="background:#e0f2fe; color:#0284c7;">Note
                                Only</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn-action btn-cancel btn-delete-maint"
                                data-id="<?php echo $m['id']; ?>">Cancel / Delete</button>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    <?php else: ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 20px;">No upcoming maintenance scheduled.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    </d iv>