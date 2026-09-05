<div class="sales-container">
    <div class="sales-header">
        <div><h2>Sales</h2><p>Recorded successful payments, excluding cancelled bookings. This is not accounting or net revenue.</p></div>
        <div class="sales-presets" role="group" aria-label="Sales report range">
            <button type="button" data-sales-preset="today">Today</button>
            <button type="button" data-sales-preset="last7">Last 7 Days</button>
            <button type="button" data-sales-preset="month" class="active">This Month</button>
            <button type="button" data-sales-preset="custom">Custom</button>
        </div>
    </div>
    <form class="sales-custom-range" id="sales-custom-range" hidden>
        <label>From <input type="date" id="sales-start" required></label>
        <label>To <input type="date" id="sales-end" required></label>
        <button type="submit">Apply range</button>
    </form>
    <div class="sales-summary" aria-live="polite">
        <div><span>Total sales</span><strong id="sales-total">₱0.00</strong></div>
        <div><span>Successful payments</span><strong id="sales-count">0</strong></div>
        <div><span>Average payment</span><strong id="sales-average">₱0.00</strong></div>
    </div>
    <section class="sales-report-panel" aria-labelledby="sales-report-title">
        <h3 id="sales-report-title">Daily recorded sales</h3>
        <div id="sales-empty" class="sales-empty" hidden>No successful payments recorded for this range.</div>
        <div id="sales-chart" class="sales-chart" role="img" aria-label="Daily recorded sales chart"></div>
        <div class="sales-table-wrap"><table class="sales-table"><thead><tr><th>Date</th><th>Payments</th><th>Total</th></tr></thead><tbody id="sales-days"></tbody></table></div>
    </section>
</div>
