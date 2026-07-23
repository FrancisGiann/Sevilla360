document.addEventListener("DOMContentLoaded", () => {
    if (!document.getElementById("cal-ui-maint")) return;

    const maintTabs = document.querySelectorAll("#maintenance-tabs .tab-btn");
    const specificVenueSelect = document.getElementById("maint-specific-venue");
    const specificVenueLabel = document.getElementById("label-specific-venue");
    const sumMaintCategory = document.getElementById("sum-maint-category");
    const sumMaintUnit = document.getElementById("sum-maint-unit");

    let currentCategory = "Event Hall";

    // --- 1. Populate Dropdown dynamically from DB ---
    function populateSpecificVenues(category) {
        specificVenueSelect.innerHTML = '<option value="" disabled selected>Select a unit...</option>';
        
        const venues = window.venueData ? window.venueData[category] : null;

        if (venues && venues.length > 0) {
            venues.forEach(venueName => {
                const opt = document.createElement("option");
                opt.value = venueName;
                opt.innerText = venueName;
                specificVenueSelect.appendChild(opt);
            });
        } else {
            specificVenueSelect.innerHTML = '<option value="" disabled selected>No units available</option>';
        }

        specificVenueLabel.innerText = `WHICH ${category.toUpperCase()}?`;
        sumMaintUnit.innerText = "--";
    }

    populateSpecificVenues(currentCategory);

    // Tab Clicking
    maintTabs.forEach(tab => {
        tab.addEventListener("click", (e) => {
            maintTabs.forEach(t => t.classList.remove("active"));
            e.target.classList.add("active");

            currentCategory = e.target.getAttribute("data-venue");
            sumMaintCategory.innerText = currentCategory;
            populateSpecificVenues(currentCategory);
            maintCalendar.clearSelection();
            updateSummary();
        });
    });

    // --- 2. Form Inputs Mirroring ---
    const inputArea = document.getElementById("maint-area");
    const selectType = document.getElementById("maint-type");
    const toggleBlock = document.getElementById("maint-block");

    specificVenueSelect.addEventListener("change", (e) => {
        sumMaintUnit.innerText = e.target.value;
        // Tell the global SevillaCalendar to fetch the booked dates!
        maintCalendar.fetchBookedDates(currentCategory, e.target.value);
    });

    inputArea.addEventListener("input", (e) => { document.getElementById("sum-maint-area").innerText = e.target.value.trim() || "--"; });
    selectType.addEventListener("change", (e) => { document.getElementById("sum-maint-type").innerText = e.target.value; });
    toggleBlock.addEventListener("change", (e) => {
        const sumBlock = document.getElementById("sum-maint-block");
        sumBlock.innerText = e.target.checked ? "ON" : "OFF";
        sumBlock.style.color = e.target.checked ? "#e06666" : "#888";
    });

    // --- 3. Use the Global Calendar Engine ---
    // Instantiate the global SevillaCalendar!
    const maintCalendar = new SevillaCalendar("cal-ui-maint");
    window.isDatesLocked = false; // Maintenance doesn't use the 30-min lock

    // When SevillaCalendar finishes a selection, it calls this global function.
    // We intercept it to update our maintenance summary without popping up a modal.
    window.requestDateConfirmation = function(startDate, endDate, calendarInstance) {
        updateSummary();
    };

    // Also update summary when clicking (for single day selections)
    document.getElementById("cal-ui-maint").addEventListener("click", () => updateSummary());

    function updateSummary() {
        const sumDate = document.getElementById("sum-maint-date");
        const sumDuration = document.getElementById("sum-maint-duration");
        
        if (!maintCalendar.startDate) {
            sumDate.innerText = "--";
            sumDuration.innerText = "--";
            return;
        }

        const opts = { month: "short", day: "numeric", year: "numeric" };
        const startStr = maintCalendar.startDate.toLocaleDateString("en-US", opts);

        if (!maintCalendar.endDate) {
            sumDate.innerText = startStr;
            sumDuration.innerText = "1 day";
        } else {
            sumDate.innerText = `${startStr} - ${maintCalendar.endDate.toLocaleDateString("en-US", opts)}`;
            // Total nights from calendar + 1 gives us total days of maintenance
            sumDuration.innerText = `${maintCalendar.totalNights + 1} days`;
        }
    }


    // --- 4. Submit Form to Backend ---
    document.getElementById("btn-schedule-maint").addEventListener("click", async (e) => {
        const btn = e.target;
        
        if (!specificVenueSelect.value) return alert("Please select a specific Unit/Venue first.");
        if (!maintCalendar.startDate) return alert("Please select dates from the Availability Calendar.");
        if (!selectType.value) return alert("Please select a Maintenance Type.");

        const formatLocal = (d) => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;

        const formData = new FormData();
        formData.append("category", currentCategory);
        formData.append("venue_name", specificVenueSelect.value);
        formData.append("area", inputArea.value);
        formData.append("type", selectType.value);
        formData.append("notes", document.getElementById("maint-notes").value);
        formData.append("block_unit", toggleBlock.checked);
        formData.append("start_date", formatLocal(maintCalendar.startDate));
        formData.append("end_date", maintCalendar.endDate ? formatLocal(maintCalendar.endDate) : formatLocal(maintCalendar.startDate));

        try {
            btn.innerText = "SCHEDULING...";
            btn.disabled = true;

            const res = await fetch("actions/admin/schedule_maintenance.php", { method: "POST", body: formData });
            const data = await res.text();
            const response = data.split("|");

            if (response[0] === "Success") {
                alert("Maintenance successfully scheduled!");
                window.location.reload();
            } else {
                throw new Error(response[1]);
            }
        } catch (error) {
            alert("Error: " + error.message);
            btn.innerText = "SCHEDULE MAINTENANCE";
            btn.disabled = false;
        }
    });

    // Clear Form
    document.getElementById("btn-clear-maint").addEventListener("click", () => window.location.reload());
});