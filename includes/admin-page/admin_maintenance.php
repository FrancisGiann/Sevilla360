<?php
require_once 'config/db_connect.php';

$upcoming_maint = $conn->query("
    SELECT m.id, m.start_date, m.end_date, m.maintenance_type, m.is_blocking, m.status, v.name as venue_name 
    FROM maintenance m 
    JOIN venues v ON m.venue_id = v.id 
    WHERE (m.status = 'Scheduled' OR m.status IS NULL) AND m.end_date >= m.start_date
    ORDER BY m.start_date ASC
");

$past_maint = $conn->query("
    SELECT m.id, m.start_date, m.end_date, m.maintenance_type, m.is_blocking, m.status, m.completed_at, v.name as venue_name 
    FROM maintenance m 
    JOIN venues v ON m.venue_id = v.id 
    WHERE m.status = 'Completed' OR m.end_date < m.start_date OR m.end_date < CURDATE()
    ORDER BY m.id DESC LIMIT 50
");

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

    <!-- Top Section: Venue Selection & Category Tabs -->
    <div class="maintenance-venue-section">
        <label class="small-label maint-section-label">SELECT VENUE CATEGORY</label>

        <div class="booking-tabs venue-tabs" id="maintenance-tabs">
            <button class="tab-btn active" data-venue="Event Hall">Event Hall</button>
            <button class="tab-btn" data-venue="Hotel Room">Hotel Room</button>
            <button class="tab-btn" data-venue="Resort Villa">Resort Villa</button>
        </div>
    </div>

    <div class="maintenance-grid">
        <!-- Middle Main Area: Availability Calendar & Form -->
        <div class="maintenance-main">

            <!-- Availability Calendar UI -->
            <div class="maint-calendar-wrapper">
                <?php
                    $calendarId = 'cal-ui-maint';
                    include 'includes/partials/booking_calendar.php';
                ?>
            </div>

            <!-- Maintenance Form Inputs -->
            <div class="booking-card form-section maint-form-card">

                <div class="form-group">
                    <label for="maint-specific-venue" id="label-specific-venue" class="maint-uppercase-label">WHICH EVENT HALL?</label>
                    <select id="maint-specific-venue"></select>
                </div>

                <div class="form-group">
                    <label for="maint-area">SPECIFIC AFFECTED AREA <span class="maint-optional-text">(Optional)</span></label>
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
                    <textarea id="maint-notes" rows="4" placeholder="Add specific details regarding the maintenance..."></textarea>
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

            <!-- Active & History Maintenance Table -->
            <div class="table-card maint-table-card">
                <h3 class="card-title">Maintenance Records</h3>

                <div class="booking-tabs maint-table-tabs" id="maintTableSubTabs">
                    <button class="tab-btn active" data-maint-view="active">Active & Upcoming</button>
                    <button class="tab-btn" data-maint-view="history">Past / Completed History</button>
                </div>

                <!-- Active & Upcoming Maintenance Table -->
                <div class="table-responsive" id="view-maint-active">
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
                                <td class="font-weight-600"><?php echo htmlspecialchars($m['venue_name']); ?></td>
                                <td><?php echo date('M j, Y', strtotime($m['start_date'])); ?></td>
                                <td><?php echo date('M j, Y', strtotime($m['end_date'])); ?></td>
                                <td><?php echo htmlspecialchars($m['maintenance_type']); ?></td>
                                <td>
                                    <?php if($m['is_blocking']): ?>
                                    <span class="status-badge status-refunded status-badge-blocked">Blocked</span>
                                    <?php else: ?>
                                    <span class="status-badge status-paid status-badge-note">Note Only</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="maint-action-cell">
                                        <button class="btn-action btn-done btn-complete-maint"
                                            data-id="<?php echo $m['id']; ?>"
                                            title="Finish early & free up calendar">Mark Done</button>
                                        <button class="btn-action btn-cancel btn-delete-maint"
                                            data-id="<?php echo $m['id']; ?>"
                                            title="Completely delete this record">Delete</button>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="6" class="maint-empty-row">No active upcoming maintenance scheduled.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Past / Completed Maintenance History Table -->
                <div class="table-responsive hidden-element" id="view-maint-history">
                    <table class="bookings-table">
                        <thead>
                            <tr>
                                <th>VENUE</th>
                                <th>SCHEDULED DATES</th>
                                <th>TYPE</th>
                                <th>STATUS</th>
                                <th>COMPLETED ON</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($past_maint && $past_maint->num_rows > 0): ?>
                            <?php while($pm = $past_maint->fetch_assoc()): ?>
                            <tr>
                                <td class="font-weight-600"><?php echo htmlspecialchars($pm['venue_name']); ?></td>
                                <td>
                                    <?php 
                                        $s = date('M j, Y', strtotime($pm['start_date']));
                                        $e = date('M j, Y', strtotime($pm['end_date']));
                                        echo ($s === $e) ? $s : "$s — $e";
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($pm['maintenance_type']); ?></td>
                                <td>
                                    <span class="status-badge status-confirmed">Completed</span>
                                </td>
                                <td>
                                    <?php echo !empty($pm['completed_at']) ? date('M j, Y h:i A', strtotime($pm['completed_at'])) : date('M j, Y', strtotime($pm['end_date'])); ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="5" class="maint-empty-row">No past maintenance history recorded yet.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>

        <!-- Right Sidebar: Maintenance Summary & Actions -->
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
                    <p class="maint-sum-last-row">Booking Block <span class="sum-val maint-sum-block" id="sum-maint-block">OFF</span></p>
                </div>

                <div class="action-buttons maint-summary-actions">
                    <button type="button" class="btn-confirm-walkin btn-confirm-maint" id="btn-schedule-maint">SCHEDULE MAINTENANCE</button>
                    <button type="button" class="btn-cancel-walkin btn-reset-maint" id="btn-clear-maint">CLEAR FORM</button>
                </div>
            </div>
        </div>
    </div>
</div>