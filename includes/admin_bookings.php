<div class="admin-bookings-container">
    <p class="bookings-subtitle">MANAGE CUSTOMER RESERVATIONS</p>
    <!-- 1. NEW CONSISTENT HEADER -->
    <div class="bookings-page-header">

        <!-- Search & Dropdowns -->
        <div class="top-controls">
            <div class="search-bar">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input type="text" placeholder="Search by name, id, or venue">
            </div>
            <select class="control-select">
                <option>All Venues</option>
                <option>Event Hall</option>
                <option>Standard Room</option>
                <option>Resort Villa</option>
            </select>
            <select class="control-select">
                <option>This Month</option>
                <option>Last Month</option>
                <option>This Year</option>
            </select>
        </div>
    </div>

    <!-- Table Card -->
    <div class="table-card">
        <h3 class="card-title">Booking History</h3>

        <!-- 3. CONSISTENT GOLD TABS (Replaced the brown pills) -->
        <div class="booking-tabs" id="bookingFilters">
            <button class="tab-btn active" data-filter="all">All</button>
            <button class="tab-btn" data-filter="pending">Pending</button>
            <button class="tab-btn" data-filter="paid">Paid</button>
            <button class="tab-btn" data-filter="cancelled">Cancelled</button>
        </div>

        <div class="table-responsive">
            <table class="bookings-table">
                <thead>
                    <tr>
                        <th>BOOKING ID</th>
                        <th>VENUE</th>
                        <th>CUSTOMER</th>
                        <th>DATE</th>
                        <th>AMOUNT</th>
                        <th>STATUS</th>
                        <th>ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>#12312</td>
                        <td>Event Hall</td>
                        <td>Francis Empleo</td>
                        <td>Jun 13, 2026</td>
                        <td>P 20,000</td>
                        <td><span class="status-badge status-pending">Pending Payment</span></td>
                        <td class="action-cells">
                            <button class="btn-action btn-confirm">Confirm</button>
                            <button class="btn-action btn-cancel">Cancel</button>
                            <button class="btn-icon"><i class="fa-solid fa-circle-info"></i></button>
                        </td>
                    </tr>
                    <tr>
                        <td>#12312</td>
                        <td>Standard Room</td>
                        <td>Lebron James</td>
                        <td>May 5-6, 2026</td>
                        <td>P 20,000</td>
                        <td><span class="status-badge status-paid">Paid</span></td>
                        <td class="action-cells">
                            <button class="btn-action btn-reschedule open-reschedule">Reschedule</button>
                            <button class="btn-action btn-refund open-refund">Refund</button>
                            <button class="btn-icon"><i class="fa-solid fa-circle-info"></i></button>
                        </td>
                    </tr>
                    <tr>
                        <td>#12312</td>
                        <td>Resort Villa</td>
                        <td>Kai Sotto</td>
                        <td>Apr 4-5, 2026</td>
                        <td>P 20,000</td>
                        <td><span class="status-badge status-partial">Partial</span></td>
                        <td class="action-cells">
                            <button class="btn-action btn-confirm">Confirm</button>
                            <button class="btn-icon"><i class="fa-solid fa-circle-info"></i></button>
                        </td>
                    </tr>
                    <tr class="faded-row">
                        <td>#12312</td>
                        <td>Event Hall</td>
                        <td>James Ready</td>
                        <td>April 1, 2026</td>
                        <td class="faded-text">P 20,000</td>
                        <td><span class="status-badge status-refunded">Refunded</span></td>
                        <td class="action-cells">
                            <button class="btn-action btn-view">View Details</button>
                        </td>
                    </tr>
                    <tr>
                        <td>#12312</td>
                        <td>Event Hall</td>
                        <td>Alex</td>
                        <td>March 30, 2026</td>
                        <td class="faded-text">P 10,000</td>
                        <td><span class="status-badge status-pending-refund">Pending Refund</span></td>
                        <td class="action-cells">
                            <button class="btn-action btn-refund open-refund">Refund</button>
                            <button class="btn-action btn-view">View Details</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modals Overlay -->
    <div class="modal-overlay" id="modalOverlay">

        <!-- Refund Modal -->
        <div class="admin-modal" id="refundModal">
            <h3 class="modal-main-title">Process Refund - Booking #12312</h3>
            <h4 class="modal-subtitle">Transaction Summary</h4>
            <div class="summary-grid">
                <span class="label">Customer Name:</span> <span class="value">Alex</span>
                <span class="label">Venue Type:</span> <span class="value">Event Hall</span>
                <span class="label">Date:</span> <span class="value">March 30, 2026</span>
                <span class="label">Total Paid by Guest:</span> <span class="value">P20,000</span>
                <span class="label">PayMongo Fee:</span> <span class="value">P461</span>
                <span class="label">Reason:</span>
                <span class="value">Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor
                    incididunt ut labore et dolore magna aliqua.</span>
            </div>
            <div class="refund-total">
                <span class="label">Refund Amount:</span>
                <span class="value amount">P19,539</span>
            </div>
            <div class="modal-actions">
                <button class="btn-modal btn-modal-cancel close-modal">Cancel</button>
                <button class="btn-modal btn-modal-refund">Refund</button>
            </div>
        </div>

        <!-- Reschedule Modal -->
        <div class="admin-modal" id="rescheduleModal">
            <h3 class="modal-main-title text-center">Reschedule Booking</h3>
            <h4 class="modal-subtitle">Booking Summary</h4>

            <div class="summary-grid reschedule-grid">
                <span class="label">Customer Name:</span> <span class="value">Alex</span>
                <span class="label">Venue Type:</span> <span class="value">Event Hall</span>
                <span class="label">Original Date:</span> <span class="value">May 5-6, 2026</span>

                <span class="label align-center">New Date:</span>
                <div class="date-picker-wrapper">
                    <div class="date-input-display" id="rescheduleDateInput">
                        <span id="selectedNewDate">April 20, 2026</span>
                        <i class="fa-regular fa-calendar"></i>
                    </div>

                    <!-- Hidden Calendar Dropdown -->
                    <div class="calendar-dropdown" id="rescheduleCalendarDropdown">
                        <div class="calendar-header">
                            <span class="cal-nav">&#8592;</span>
                            <strong>February 2026</strong>
                            <span class="cal-nav">&#8594;</span>
                        </div>
                        <div class="calendar-grid">
                            <div class="day-name">SUN</div>
                            <div class="day-name">MON</div>
                            <div class="day-name">TUE</div>
                            <div class="day-name">WED</div>
                            <div class="day-name">THU</div>
                            <div class="day-name">FRI</div>
                            <div class="day-name">SAT</div>

                            <!-- Sample Dates matching wireframe -->
                            <div class="cal-day empty"></div>
                            <div class="cal-day num">1</div>
                            <div class="cal-day num">2</div>
                            <div class="cal-day num">3</div>
                            <div class="cal-day num">4</div>
                            <div class="cal-day num">5</div>
                            <div class="cal-day num">6</div>
                            <div class="cal-day num">7</div>
                            <div class="cal-day num">8</div>
                            <div class="cal-day num">9</div>
                            <div class="cal-day num">10</div>
                            <div class="cal-day num">11</div>
                            <div class="cal-day num">12</div>
                            <div class="cal-day num">13</div>
                            <div class="cal-day available">14</div>
                            <div class="cal-day booked">15</div>
                            <div class="cal-day booked">16</div>
                            <div class="cal-day available">17</div>
                            <div class="cal-day available">18</div>
                            <div class="cal-day available">19</div>
                            <div class="cal-day available">20</div>
                            <div class="cal-day available">21</div>
                            <div class="cal-day available">22</div>
                            <div class="cal-day selected" data-date="February 23, 2026">23</div>
                            <div class="cal-day available">24</div>
                            <div class="cal-day available">25</div>
                            <div class="cal-day available">26</div>
                            <div class="cal-day booked">27</div>
                            <div class="cal-day booked">28</div>
                            <div class="cal-day empty"></div>
                            <div class="cal-day empty"></div>
                            <div class="cal-day empty"></div>
                            <div class="cal-day empty"></div>
                            <div class="cal-day empty"></div>
                            <div class="cal-day empty"></div>
                        </div>
                    </div>
                </div>

                <span class="label align-center">Reason:</span> <span class="value">Typhoon</span>
            </div>

            <div class="modal-actions">
                <button class="btn-modal btn-modal-cancel close-modal">Cancel</button>
                <button class="btn-modal btn-modal-refund">Reschedule</button>
            </div>
        </div>

    </div>
</div>