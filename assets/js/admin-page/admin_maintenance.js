document.addEventListener("DOMContentLoaded", () => {
    if (!document.getElementById("cal-ui-maint")) return;

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const maintTabs = document.querySelectorAll("#maintenance-tabs .tab-btn");
    const specificVenueSelect = document.getElementById("maint-specific-venue");
    const specificVenueLabel = document.getElementById("label-specific-venue");
    const sumMaintCategory = document.getElementById("sum-maint-category");
    const sumMaintUnit = document.getElementById("sum-maint-unit");
    
    let currentCategory = "Event Hall";

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

    // Sub-tab switching between Active Maintenance & Past Maintenance History
    const tableSubTabs = document.querySelectorAll("#maintTableSubTabs .tab-btn");
    const activeView = document.getElementById("view-maint-active");
    const historyView = document.getElementById("view-maint-history");

    if (tableSubTabs.length > 0 && activeView && historyView) {
        tableSubTabs.forEach(tab => {
            tab.addEventListener("click", (e) => {
                tableSubTabs.forEach(t => t.classList.remove("active"));
                e.target.classList.add("active");

                const view = e.target.getAttribute("data-maint-view");
                if (view === "history") {
                    activeView.classList.add("hidden-element");
                    historyView.classList.remove("hidden-element");
                } else {
                    historyView.classList.add("hidden-element");
                    activeView.classList.remove("hidden-element");
                }
            });
        });
    }

    const inputArea = document.getElementById("maint-area");
    const selectType = document.getElementById("maint-type");
    const toggleBlock = document.getElementById("maint-block");

    specificVenueSelect.addEventListener("change", (e) => {
        sumMaintUnit.innerText = e.target.value;
        maintCalendar.fetchBookedDates(currentCategory, e.target.value);
    });

    inputArea.addEventListener("input", (e) => { document.getElementById("sum-maint-area").innerText = e.target.value.trim() || "--"; });
    selectType.addEventListener("change", (e) => { document.getElementById("sum-maint-type").innerText = e.target.value; });
    toggleBlock.addEventListener("change", (e) => {
        const sumBlock = document.getElementById("sum-maint-block");
        sumBlock.innerText = e.target.checked ? "ON" : "OFF";
        sumBlock.className = 'sum-val maint-sum-block ' + (e.target.checked ? 'sum-block-on' : 'sum-block-off');
    });

    const maintCalendar = new SevillaCalendar("cal-ui-maint");
    window.isDatesLocked = false;

    window.requestDateConfirmation = function(startDate, endDate, calendarInstance) {
        updateSummary();
    };

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
            
            const start = new Date(maintCalendar.startDate.getFullYear(), maintCalendar.startDate.getMonth(), maintCalendar.startDate.getDate());
            const end = new Date(maintCalendar.endDate.getFullYear(), maintCalendar.endDate.getMonth(), maintCalendar.endDate.getDate());
            
            const diffTime = Math.abs(end - start);
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)); 
            const totalDays = diffDays + 1; 

            sumDuration.innerText = `${totalDays} day${totalDays > 1 ? 's' : ''}`;
        }
    }

    document.getElementById("btn-schedule-maint").addEventListener("click", async (e) => {
        const btn = e.target;
        
        if (!specificVenueSelect.value) return showAlert("Notice", "Please select a specific Unit/Venue first.");
        if (!maintCalendar.startDate) return showAlert("Notice", "Please select dates from the Availability Calendar.");
        if (!selectType.value) return showAlert("Notice", "Please select a Maintenance Type.");

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

            const res = await fetch("actions/admin/schedule_maintenance.php", { 
                method: "POST", 
                headers: { "X-CSRF-Token": csrfToken },
                body: formData 
            });
            const data = await res.text();
            const response = data.split("|");

            if (response[0] === "Success") {
                showAlert("Notice", "Maintenance successfully scheduled!");
                window.location.reload();
            } else {
                throw new Error(response[1]);
            }
        } catch (error) {
            showAlert("Notice", "Error: " + error.message);
            btn.innerText = "SCHEDULE MAINTENANCE";
            btn.disabled = false;
        }
    });

    const deleteMaintBtns = document.querySelectorAll(".btn-delete-maint");
    deleteMaintBtns.forEach(btn => {
        btn.addEventListener("click", async (e) => {
            const maintId = e.target.getAttribute("data-id");

            const confirmed = await showConfirm("Cancel Maintenance", "Are you sure you want to cancel and delete this maintenance block? This will free up the dates on the calendar immediately.");
            if (!confirmed) {
                return;
            }

            e.target.innerText = "Deleting...";
            e.target.disabled = true;

            try {
                const res = await fetch("actions/admin/delete_maintenance.php", {
                    method: "POST",
                    headers: { 
                        "Content-Type": "application/json",
                        "X-CSRF-Token": csrfToken 
                    },
                    body: JSON.stringify({ id: maintId })
                });
                
                const data = await res.json();
                
                if (data.success) {
                    showAlert("Notice", "Maintenance successfully deleted!");
                    window.location.reload();
                } else {
                    throw new Error(data.message);
                }
            } catch (error) {
                showAlert("Notice", "Error: " + error.message);
                e.target.innerText = "Cancel / Delete";
                e.target.disabled = false;
            }
        });
    });

    const completeMaintBtns = document.querySelectorAll(".btn-complete-maint");
    completeMaintBtns.forEach(btn => {
        btn.addEventListener("click", async (e) => {
            const maintId = e.target.getAttribute("data-id");

            const confirmed = await showConfirm("Complete Maintenance", "Mark this maintenance as completed? This will instantly free up the room for new bookings today, while keeping the historical record intact.");
            if (!confirmed) {
                return;
            }

            e.target.innerText = "Processing...";
            e.target.disabled = true;

            try {
                const res = await fetch("actions/admin/complete_maintenance.php", {
                    method: "POST",
                    headers: { 
                        "Content-Type": "application/json",
                        "X-CSRF-Token": csrfToken 
                    },
                    body: JSON.stringify({ id: maintId })
                });
                
                const data = await res.json();
                if (data.success) {
                    showAlert("Notice", "Maintenance marked as completed! Calendar has been updated.");
                    window.location.reload();
                } else {
                    throw new Error(data.message);
                }
            } catch (error) {
                showAlert("Notice", "Error: " + error.message);
                e.target.innerText = "Mark Done";
                e.target.disabled = false;
            }
        });
    });

    document.getElementById("btn-clear-maint").addEventListener("click", () => window.location.reload());
});