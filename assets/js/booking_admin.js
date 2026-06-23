/**
 * SEVILLA360 - Admin Walk-in Booking Logic
 * Features: Modals for Confirm/Override Dates
 */

document.addEventListener("DOMContentLoaded", () => {
    
    const formatCurrency = (amount) => '₱' + parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

    // Global Date Locking State for the session
    window.isDatesLocked = false;
    let tempStartDate = null;
    let tempEndDate = null;
    let activeCalendar = null; // Keeps track of which tab's calendar is locked

    /* ==========================================
       1. AIRBNB-STYLE ADMIN CALENDAR & MODALS
       ========================================== */
    class AdminBookingCalendar {
        constructor(containerId) {
            this.container = document.getElementById(containerId);
            if (!this.container) return;

            this.grid = this.container.querySelector(".cal-days-grid");
            this.monthYearDisplay = this.container.querySelector(".cal-month-year");
            this.prevBtn = this.container.querySelector(".prev-month");
            this.nextBtn = this.container.querySelector(".next-month");

            this.currentDate = new Date();
            this.currentDate.setDate(1);

            this.startDate = null;
            this.endDate = null;
            this.totalNights = 1; 

            // Mock Booked Dates
            this.bookedDays = [14, 15, 22];
            this.unavailableDays = [10, 11, 28, 29];

            this.init();
        }

        init() {
            this.render();
            this.prevBtn.addEventListener("click", (e) => { e.preventDefault(); this.currentDate.setMonth(this.currentDate.getMonth() - 1); this.render(); });
            this.nextBtn.addEventListener("click", (e) => { e.preventDefault(); this.currentDate.setMonth(this.currentDate.getMonth() + 1); this.render(); });
        }

        hasInvalidDaysBetween(start, end) {
            let current = new Date(start);
            current.setDate(current.getDate() + 1);
            while (current < end) {
                if (this.bookedDays.includes(current.getDate()) || this.unavailableDays.includes(current.getDate())) return true;
                current.setDate(current.getDate() + 1);
            }
            return false;
        }

        render() {
            this.grid.innerHTML = "";
            const year = this.currentDate.getFullYear();
            const month = this.currentDate.getMonth();
            const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];

            this.monthYearDisplay.innerText = `${monthNames[month]} ${year}`;

            const firstDayIndex = new Date(year, month, 1).getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();

            for (let i = 0; i < firstDayIndex; i++) {
                const emptyCell = document.createElement("div");
                emptyCell.className = "cal-day-cell empty";
                this.grid.appendChild(emptyCell);
            }

            for (let day = 1; day <= daysInMonth; day++) {
                const cellDate = new Date(year, month, day);
                const cell = document.createElement("div");
                cell.className = "cal-day-cell";
                cell.innerText = day;

                if (this.bookedDays.includes(day)) {
                    cell.classList.add("booked");
                } else if (this.unavailableDays.includes(day)) {
                    cell.classList.add("unavailable");
                } else {
                    if (this.startDate && cellDate.getTime() === this.startDate.getTime()) {
                        cell.classList.add("selected", "start-date");
                    }
                    if (this.endDate && cellDate.getTime() === this.endDate.getTime()) {
                        cell.classList.add("selected", "end-date");
                    }
                    if (this.startDate && this.endDate && cellDate > this.startDate && cellDate < this.endDate) {
                        cell.classList.add("in-range");
                    }

                    // Click Logic 
                    cell.addEventListener("click", () => {
                        // 1. If currently locked, ask for override
                        if (window.isDatesLocked) {
                            showOverrideModal(cellDate, this);
                            return;
                        }

                        // 2. Selection Logic
                        if (this.startDate && this.endDate) {
                            this.startDate = cellDate;
                            this.endDate = null;
                            this.render();
                        } else if (!this.startDate) {
                            this.startDate = cellDate;
                            this.render();
                        } else if (this.startDate && !this.endDate) {
                            if (cellDate < this.startDate) {
                                this.startDate = cellDate; 
                                this.render();
                            } else {
                                if (this.hasInvalidDaysBetween(this.startDate, cellDate)) {
                                    alert("Selection contains unavailable or booked dates.");
                                    this.startDate = cellDate; 
                                    this.render();
                                } else {
                                    this.endDate = cellDate;
                                    this.render();
                                    // Trigger Confirmation Modal since range is complete
                                    requestDateConfirmation(this.startDate, this.endDate, this);
                                }
                            }
                        }
                    });
                }
                this.grid.appendChild(cell);
            }
        }

        updateDateDisplay() {
            const dateDisplayEl = document.getElementById('summary-dates');
            if(!this.startDate || !window.isDatesLocked) {
                dateDisplayEl.innerText = "Please select dates";
                this.totalNights = 1;
                return;
            }

            const opts = { month: "short", day: "numeric", year: "numeric" };
            const startStr = this.startDate.toLocaleDateString("en-US", opts);

            if(this.endDate && this.startDate.getTime() !== this.endDate.getTime()) {
                const endStr = this.endDate.toLocaleDateString("en-US", opts);
                dateDisplayEl.innerText = `${startStr} — ${endStr}`;
                
                const diffTime = Math.abs(this.endDate - this.startDate);
                this.totalNights = Math.ceil(diffTime / (1000 * 60 * 60 * 24)); 
            } else {
                dateDisplayEl.innerText = startStr;
                this.totalNights = 1;
            }
        }
        
        clearSelection() {
            this.startDate = null;
            this.endDate = null;
            this.render();
            this.updateDateDisplay();
        }
    }

    // Modal Triggers
    function requestDateConfirmation(startDate, endDate, calendarInstance) {
        tempStartDate = startDate;
        tempEndDate = endDate;
        activeCalendar = calendarInstance;

        const dateModal = document.getElementById("confirm-dates-modal");
        const dateDisplay = document.getElementById("confirm-date-display");

        const opts = { month: "short", day: "numeric", year: "numeric" };
        const startStr = startDate.toLocaleDateString("en-US", opts);
        const endStr = endDate.toLocaleDateString("en-US", opts);
        dateDisplay.innerText = `${startStr} — ${endStr}`;

        dateModal.classList.add("active");
    }

    function showOverrideModal(newDate, calendarInstance) {
        const overrideModal = document.getElementById("change-dates-modal");
        overrideModal.classList.add("active");

        // Clone to remove old event listeners
        const oldYes = document.getElementById("btn-override-yes");
        const newYes = oldYes.cloneNode(true);
        oldYes.parentNode.replaceChild(newYes, oldYes);

        const oldNo = document.getElementById("btn-override-no");
        const newNo = oldNo.cloneNode(true);
        oldNo.parentNode.replaceChild(newNo, oldNo);

        // YES, CHANGE DATES
        newYes.addEventListener("click", () => {
            overrideModal.classList.remove("active");
            window.isDatesLocked = false; // Unlock
            
            if(activeCalendar) activeCalendar.clearSelection(); // Clear previous
            
            // Set new start date on the clicked calendar
            activeCalendar = calendarInstance;
            activeCalendar.startDate = newDate;
            activeCalendar.endDate = null;
            activeCalendar.render();
            activeCalendar.updateDateDisplay();
            calculateSummary();
        });

        // NO, KEEP CURRENT
        newNo.addEventListener("click", () => {
            overrideModal.classList.remove("active");
        });
    }

    // Modal Button Event Listeners
    document.getElementById("btn-confirm-dates").addEventListener("click", () => {
        document.getElementById("confirm-dates-modal").classList.remove("active");
        window.isDatesLocked = true;
        activeCalendar.updateDateDisplay();
        calculateSummary();
    });

    document.getElementById("btn-cancel-dates").addEventListener("click", () => {
        document.getElementById("confirm-dates-modal").classList.remove("active");
        activeCalendar.clearSelection();
    });


    // Initialize 3 separate calendars
    const calEvent = new AdminBookingCalendar("cal-ui-event");
    const calHotel = new AdminBookingCalendar("cal-ui-hotel");
    const calVilla = new AdminBookingCalendar("cal-ui-villa");

    /* ==========================================
       2. TABS & DYNAMIC IMAGES
       ========================================== */
    const tabBtns = document.querySelectorAll('.tab-btn');
    const tabContents = document.querySelectorAll('.tab-content');
    let currentTab = 'tab-event';

    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            tabBtns.forEach(b => b.classList.remove('active'));
            tabContents.forEach(c => c.classList.remove('active'));
            
            btn.classList.add('active');
            const targetId = btn.getAttribute('data-target');
            document.getElementById(targetId).classList.add('active');
            currentTab = targetId;
            
            if(currentTab === 'tab-event') calEvent.updateDateDisplay();
            if(currentTab === 'tab-hotel') calHotel.updateDateDisplay();
            if(currentTab === 'tab-villa') calVilla.updateDateDisplay();
            
            calculateSummary();
        });
    });

    /* ==========================================
       3. UI TOGGLES (Add-ons, Forms)
       ========================================== */
    document.getElementById('check-catering').addEventListener('change', function() {
        document.getElementById('catering-options').classList.toggle('hidden', !this.checked); calculateSummary();
    });
    document.getElementById('check-rooms').addEventListener('change', function() {
        document.getElementById('rooms-options').classList.toggle('hidden', !this.checked); calculateSummary();
    });

    const eventRadios = document.querySelectorAll('input[name="event-type"]');
    const othersInput = document.getElementById('event-type-others');
    eventRadios.forEach(radio => {
        radio.addEventListener('change', (e) => {
            if(e.target.id === 'event-others-radio') othersInput.classList.remove('hidden');
            else { othersInput.classList.add('hidden'); othersInput.value = ''; }
            calculateSummary();
        });
    });

    const paymentRadios = document.querySelectorAll('input[name="payment-method"]');
    const transactionWrapper = document.getElementById('transaction-wrapper');
    paymentRadios.forEach(radio => {
        radio.addEventListener('change', (e) => {
            if(['gcash', 'maya', 'bank'].includes(e.target.value)) transactionWrapper.classList.remove('hidden');
            else transactionWrapper.classList.add('hidden');
        });
    });

    document.querySelectorAll('.btn-minus, .btn-plus').forEach(btn => {
        btn.addEventListener('click', (e) => {
            const targetId = e.target.getAttribute('data-target');
            const targetSpan = document.getElementById(targetId);
            let val = parseInt(targetSpan.textContent);
            if (e.target.classList.contains('btn-plus')) val++;
            else if (e.target.classList.contains('btn-minus') && val > 0) val--;
            targetSpan.textContent = val;
            calculateSummary();
        });
    });

    document.querySelectorAll('select, input[type="number"], input[type="radio"], input[type="checkbox"], input[type="text"]').forEach(input => {
        input.addEventListener('change', calculateSummary); input.addEventListener('input', calculateSummary);
    });

    /* ==========================================
       4. SUMMARY GENERATOR (Factoring Range Dates)
       ========================================== */
    function calcExtraPax(guestInputId, baseCapacity, feePerHead, feeLabelId) {
        const guests = parseInt(document.getElementById(guestInputId).value) || 0;
        let extraFee = 0;
        const feeLabel = document.getElementById(feeLabelId);
        if (guests > baseCapacity) {
            extraFee = (guests - baseCapacity) * feePerHead;
            feeLabel.textContent = `Extra Pax Fee: ${formatCurrency(extraFee)}`;
            feeLabel.classList.remove('hidden');
        } else {
            feeLabel.classList.add('hidden');
        }
        return extraFee;
    }

    function calculateSummary() {
        let total = 0; let summaryHTML = '';
        const addRow = (label, amount) => { summaryHTML += `<div class="summary-row"><span>${label}</span><span>${formatCurrency(amount)}</span></div>`; };

        if(!window.isDatesLocked) {
            document.getElementById('summary-breakdown').innerHTML = '<div class="summary-row" style="color:#b5884e;"><i>Please select and lock dates to calculate.</i></div>';
            document.getElementById('summary-total-val').textContent = "₱0.00";
            document.getElementById('summary-due-val').textContent = "₱0.00";
            return;
        }

        if (currentTab === 'tab-event') {
            const daysMultiplier = calEvent.totalNights;
            const venue = parseFloat(document.getElementById('event-venue').value) * daysMultiplier;
            const style = parseFloat(document.getElementById('event-style').value) * daysMultiplier;
            const typeRadio = document.querySelector('input[name="event-type"]:checked');
            const typeFee = typeRadio && typeRadio.id !== 'event-others-radio' ? parseFloat(typeRadio.value) * daysMultiplier : 0;
            const guests = parseInt(document.getElementById('event-guests').value) || 0;

            total += venue + style + typeFee;
            addRow(`Venue Rate (x${daysMultiplier} days)`, venue);
            if(style > 0) addRow('Style Upgrade', style);
            if(typeFee > 0) addRow('Event Setup Fee', typeFee);

            if(document.getElementById('check-catering').checked) {
                const tierPrice = parseFloat(document.querySelector('input[name="catering-tier"]:checked').value);
                const cateringTotal = tierPrice * guests * daysMultiplier; 
                total += cateringTotal; addRow(`Catering (${guests} pax)`, cateringTotal);
            }

            if(document.getElementById('check-rooms').checked) {
                const dltQty = parseInt(document.getElementById('qty-deluxe').textContent);
                const vipQty = parseInt(document.getElementById('qty-vip').textContent);
                const roomTotal = ((dltQty * 4500) + (vipQty * 8500)) * daysMultiplier;
                if (roomTotal > 0) { total += roomTotal; addRow(`Reserved Rooms (x${daysMultiplier} nights)`, roomTotal); }
            }

        } else if (currentTab === 'tab-hotel') {
            const nights = calHotel.totalNights;
            const room = parseFloat(document.getElementById('hotel-room').value) * nights;
            total += room; addRow(`Base Room Rate (x${nights} nights)`, room);

            const extraFeePerNight = calcExtraPax('hotel-guests', 2, 800, 'hotel-extra-fee');
            if(extraFeePerNight > 0) { const totalExtra = extraFeePerNight * nights; total += totalExtra; addRow('Extra Pax Fee', totalExtra); }

        } else if (currentTab === 'tab-villa') {
            const nights = calVilla.totalNights;
            const villa = parseFloat(document.getElementById('villa-type').value) * nights;
            const stayType = parseFloat(document.querySelector('input[name="stay-type"]:checked').value) * nights;
            
            total += villa + stayType; addRow(`Base Villa Rate (x${nights} days)`, villa);
            if(stayType > 0) addRow('Overnight Upgrade', stayType);

            const extraFeePerDay = calcExtraPax('villa-guests', 4, 1000, 'villa-extra-fee');
            if(extraFeePerDay > 0) { const totalExtra = extraFeePerDay * nights; total += totalExtra; addRow('Extra Pax Fee', totalExtra); }
        }

        const schemePct = parseFloat(document.getElementById('payment-scheme').value);
        const amountDue = total * schemePct;

        document.getElementById('summary-breakdown').innerHTML = summaryHTML || '<div class="summary-row"><span>No items selected</span></div>';
        document.getElementById('summary-total-val').textContent = formatCurrency(total);
        document.getElementById('summary-due-val').textContent = formatCurrency(amountDue);
    }

    calculateSummary();
});