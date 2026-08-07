<div class="calendar-page-container">
    <div class="calendar-header-card">
        <div>
            <h3 style="margin:0; font-family:var(--font-heading); font-size:1.8rem; color:var(--color-dark);">Master
                Schedule</h3>
            <p style="margin: 5px 0 0 0; color:var(--color-dark-light);">Click on any event block to manage the booking.
            </p>
        </div>
        <!-- Replace the <div class="legend-box"> in admin_calendar.php with this: -->
        <div class="legend-box">
            <span class="legend-item">
                <div class="color-box" style="background:#c27c7c;"></div> Pending
            </span>
            <span class="legend-item">
                <div class="color-box" style="background:#4a4440;"></div> Events
            </span>
            <span class="legend-item">
                <div class="color-box" style="background:#d6a870;"></div> Rooms
            </span>
            <span class="legend-item">
                <div class="color-box" style="background:#88a096;"></div> Villas
            </span>
            <span class="legend-item">
                <div class="color-box" style="background:#e08f24;"></div> Maintenance
            </span>
        </div>
    </div>

    <!-- The actual Calendar Grid -->
    <div class="calendar-wrapper-card">
        <div id="full-master-calendar"></div>
    </div>
</div>