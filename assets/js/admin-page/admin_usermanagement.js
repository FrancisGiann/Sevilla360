document.addEventListener("DOMContentLoaded", () => {
  
    // --- 1. Tab Switching & Search ---
    const tabs = document.querySelectorAll(".um-tab");
    const tables = document.querySelectorAll(".um-table");
    const searchInput = document.getElementById("umSearch");
  
    tabs.forEach((tab) => {
      tab.addEventListener("click", () => {
        tabs.forEach((t) => t.classList.remove("active"));
        tables.forEach((tbl) => tbl.classList.remove("active"));
        tab.classList.add("active");
        document.getElementById(tab.getAttribute("data-target")).classList.add("active");
        if(searchInput) searchInput.value = ''; // Reset search on tab switch
        filterTables('');
      });
    });

    if (searchInput) {
        searchInput.addEventListener('input', (e) => filterTables(e.target.value.toLowerCase()));
    }

    function filterTables(term) {
        document.querySelectorAll(".um-table.active tbody tr").forEach(row => {
            row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
        });
    }
    // --- Customer Type Filter Logic ---
    const custFilters = document.querySelectorAll(".cust-filter");
    const custRows = document.querySelectorAll(".cust-row");

    custFilters.forEach(btn => {
        btn.addEventListener("click", () => {
            // 1. Reset all buttons
            custFilters.forEach(f => f.classList.remove("active"));

            // 2. Highlight clicked button
            btn.classList.add("active");

            // 3. Filter the rows
            const filterValue = btn.getAttribute("data-filter");
            
            custRows.forEach(row => {
                if (filterValue === "All" || row.getAttribute("data-type") === filterValue) {
                    row.style.display = ""; // Show
                } else {
                    row.style.display = "none"; // Hide
                }
            });
        });
    });
  
    // --- 2. Modal Controls ---
    const staffModal = document.getElementById("staffModal");
    const historyModal = document.getElementById("historyModal");
  
    function closeModal() {
        staffModal.classList.remove("active");
        historyModal.classList.remove("active");
        document.getElementById('staffForm').reset();
    }
  
    document.querySelectorAll(".close-staff-modal, .close-history-modal").forEach(btn => btn.addEventListener("click", closeModal));
    window.addEventListener("click", (e) => { if (e.target === staffModal || e.target === historyModal) closeModal(); });
  
    // --- 3. Staff Management (Add/Edit/Delete) ---
    document.getElementById("openAddStaffBtn").addEventListener("click", () => {
        document.getElementById('staffForm').reset();
        document.getElementById('staff_user_id').value = '';
        document.getElementById('staffModalTitle').innerText = "Add New Staff";
        document.getElementById('pw_hint').style.display = 'none';
        document.getElementById('staff_password').required = true;
        staffModal.classList.add("active");
    });

    document.querySelectorAll(".btn-staff-modal").forEach((btn) => {
        btn.addEventListener("click", function() {
            document.getElementById('staffModalTitle').innerText = "Edit Staff Account";
            document.getElementById('staff_user_id').value = this.getAttribute('data-id');
            document.getElementById('staff_name').value = this.getAttribute('data-name');
            document.getElementById('staff_email').value = this.getAttribute('data-email');
            document.getElementById('staff_role').value = this.getAttribute('data-role');
            document.getElementById('staff_status').value = this.getAttribute('data-status');
            
            document.getElementById('staff_password').value = '';
            document.getElementById('staff_password').required = false;
            document.getElementById('pw_hint').style.display = 'block';
            
            staffModal.classList.add("active");
        });
    });

    document.getElementById('staffForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const btn = document.getElementById('btnSaveStaff');
        const origText = btn.innerText;
        btn.innerText = 'Saving...'; btn.disabled = true;

        const userId = document.getElementById('staff_user_id').value;
        const payload = {
            action: userId ? 'edit' : 'add',
            user_id: userId,
            name: document.getElementById('staff_name').value,
            email: document.getElementById('staff_email').value,
            role: document.getElementById('staff_role').value,
            status: document.getElementById('staff_status').value,
            password: document.getElementById('staff_password').value
        };

        fetch('actions/admin/manage_staff.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) { alert(data.message); window.location.reload(); }
            else { alert("Error: " + data.message); btn.innerText = origText; btn.disabled = false; }
        });
    });

    document.querySelectorAll(".btn-delete-staff").forEach(btn => {
        btn.addEventListener('click', function() {
            if (confirm("Are you sure you want to permanently delete this staff member?")) {
                fetch('actions/admin/manage_staff.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', user_id: this.getAttribute('data-id') })
                })
                .then(res => res.json())
                .then(data => {
                    if(data.success) window.location.reload(); else alert("Error: " + data.message);
                });
            }
        });
    });
  
    // --- 4. Customer History Modal ---
    document.querySelectorAll(".btn-history-modal").forEach((btn) => {
        btn.addEventListener("click", function() {
            const custId = this.getAttribute('data-id');
            const tbody = document.getElementById('historyTbody');
            tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;">Loading...</td></tr>';
            historyModal.classList.add("active");

            fetch(`actions/admin/get_customer_history.php?id=${custId}`)
            .then(res => res.json())
            .then(res => {
                if (!res.success) { tbody.innerHTML = `<tr><td colspan="3" style="text-align:center;color:red;">${res.message}</td></tr>`; return; }
                tbody.innerHTML = '';
                if (res.data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;">No completed bookings found.</td></tr>';
                } else {
                    res.data.forEach(b => {
                        const d = new Date(b.start_date).toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
                        const amt = `₱${parseFloat(b.total_amount).toLocaleString('en-US', {minimumFractionDigits:2})}`;
                        tbody.insertAdjacentHTML('beforeend', `<tr><td>${d}</td><td>${b.venue_name}</td><td>${amt}</td></tr>`);
                    });
                }
            });
        });
    });

    // --- 5. Suspend/Activate Customer Logic ---
    document.querySelectorAll(".btn-suspend").forEach((btn) => {
        btn.addEventListener("click", function() {
            const userId = this.getAttribute('data-id');
            const newStatus = this.getAttribute('data-action');
            const actionText = newStatus === 'suspended' ? 'suspend' : 're-activate';

            if (confirm(`Are you sure you want to ${actionText} this customer's account?`)) {
                const origText = this.innerText;
                this.innerText = 'Processing...';
                this.disabled = true;

                fetch('actions/admin/suspend_user.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user_id: userId, action: newStatus })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert("Error: " + data.message);
                        this.innerText = origText;
                        this.disabled = false;
                    }
                });
            }
        });
    });
});