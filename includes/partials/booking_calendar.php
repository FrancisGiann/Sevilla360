<div class="calendar-ui" id="<?= $calendarId ?>">
    <div class="cal-header">
        <button type="button" class="cal-nav prev-month">&larr;</button>
        <h4 class="cal-month-year">Month Year</h4>
        <button type="button" class="cal-nav next-month">&rarr;</button>
    </div>

    <div class="cal-weekdays">
        <span>SUN</span><span>MON</span><span>TUE</span>
        <span>WED</span><span>THU</span><span>FRI</span><span>SAT</span>
    </div>

    <div class="cal-days-grid"></div>

    <div class="cal-legend">
        <span class="legend-item"><span class="dot selected"></span> Selected</span>
        <span class="legend-item"><span class="dot booked"></span> Booked</span>
        <span class="legend-item"><span class="dot available"></span> Available</span>
        <span class="legend-item"><span class="dot unavailable"></span> Unavailable</span>
    </div>
</div>