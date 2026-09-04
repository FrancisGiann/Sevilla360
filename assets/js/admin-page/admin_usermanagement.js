document.addEventListener("DOMContentLoaded", () => {
    const staffTable = document.getElementById("staffTable");
    const customerTable = document.getElementById("customerTable");
    if (!staffTable || !customerTable) return;

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";
    const searchInput = document.getElementById("umSearch");
    const staffFilters = document.querySelectorAll("[data-staff-filter]");
    const staffRows = [...document.querySelectorAll(".staff-row")];
    const customerRows = [...document.querySelectorAll(".cust-row")];
    const staffEmptyRow = document.getElementById("staffEmptyRow");
    const customerEmptyRow = document.getElementById("customerEmptyRow");
    const customerFilterSelect = document.getElementById("customerFilterSelect");
    const customerFilters = document.querySelectorAll(".cust-filter");
    const staffModal = document.getElementById("staffModal");
    const historyModal = document.getElementById("historyModal");
    const staffForm = document.getElementById("staffForm");
    const formError = document.getElementById("staffFormError");
    const saveButton = document.getElementById("btnSaveStaff");
    const passwordInput = document.getElementById("staff_password");
    const passwordHint = document.getElementById("pw_hint");
    const historyBody = document.getElementById("historyTbody");
    const today = document.getElementById("staff_hire_date")?.getAttribute("max") || "";
    let activeTab = "staffTable";
    let staffFilter = "active";
    let customerFilter = "All";
    let activeModal = null;
    let lastFocusedElement = null;
    let staffSubmitting = false;

    const notify = (title, message, type = "info") => {
        if (typeof window.showAlert === "function") window.showAlert(title, message, type);
        else window.alert(`${title}: ${message}`);
    };

    const confirmAction = (title, message) => {
        if (typeof window.showConfirm === "function") return window.showConfirm(title, message);
        return Promise.resolve(window.confirm(`${title}\n\n${message}`));
    };

    const setHidden = (element, hidden) => {
        if (element) element.hidden = hidden;
    };

    function filterStaff() {
        const term = (searchInput?.value || "").trim().toLowerCase();
        let matches = 0;
        staffRows.forEach((row) => {
            const matchesStatus = staffFilter === "all" || row.dataset.status === staffFilter;
            const matchesSearch = !term || (row.dataset.search || "").toLowerCase().includes(term);
            row.hidden = !(matchesStatus && matchesSearch);
            if (!row.hidden) matches += 1;
        });
        setHidden(staffEmptyRow, matches > 0);
    }

    function filterCustomers() {
        const term = (searchInput?.value || "").trim().toLowerCase();
        let matches = 0;
        customerRows.forEach((row) => {
            const matchesType = customerFilter === "All" || row.dataset.type === customerFilter;
            const matchesSearch = !term || (row.dataset.search || "").toLowerCase().includes(term);
            row.hidden = !(matchesType && matchesSearch);
            if (!row.hidden) matches += 1;
        });
        setHidden(customerEmptyRow, matches > 0);
    }

    function filterActiveTable() {
        if (activeTab === "staffTable") filterStaff();
        else filterCustomers();
    }

    function activateTab(target) {
        activeTab = target === "customerTable" ? "customerTable" : "staffTable";
        document.querySelectorAll(".um-tab").forEach((tab) => {
            const selected = tab.dataset.target === activeTab;
            tab.classList.toggle("active", selected);
            tab.setAttribute("aria-selected", selected ? "true" : "false");
            tab.tabIndex = selected ? 0 : -1;
        });
        staffTable.classList.toggle("active", activeTab === "staffTable");
        customerTable.classList.toggle("active", activeTab === "customerTable");
        customerTable.hidden = activeTab !== "customerTable";
        const staffFilterControls = document.getElementById("staffFilterControls");
        setHidden(staffFilterControls, activeTab !== "staffTable");
        if (searchInput) searchInput.value = "";
        filterActiveTable();
    }

    document.querySelectorAll(".um-tab").forEach((tab) => {
        tab.addEventListener("click", () => activateTab(tab.dataset.target));
    });
    searchInput?.addEventListener("input", filterActiveTable);

    staffFilters.forEach((button) => {
        button.addEventListener("click", () => {
            staffFilter = button.dataset.staffFilter || "active";
            staffFilters.forEach((filter) => {
                const selected = filter.dataset.staffFilter === staffFilter;
                filter.classList.toggle("active", selected);
                filter.setAttribute("aria-pressed", selected ? "true" : "false");
            });
            filterStaff();
        });
    });

    function applyCustomerFilter(value) {
        customerFilter = ["All", "Registered", "Walk-in"].includes(value) ? value : "All";
        customerFilters.forEach((filter) => {
            const selected = filter.dataset.filter === customerFilter;
            filter.classList.toggle("active", selected);
            filter.setAttribute("aria-pressed", selected ? "true" : "false");
        });
        if (customerFilterSelect) customerFilterSelect.value = customerFilter;
        filterCustomers();
    }

    customerFilters.forEach((button) => {
        button.addEventListener("click", () => applyCustomerFilter(button.dataset.filter));
    });
    customerFilterSelect?.addEventListener("change", () => applyCustomerFilter(customerFilterSelect.value));

    function clearFormError() {
        if (!formError) return;
        formError.textContent = "";
        formError.hidden = true;
    }

    function showFormError(message) {
        if (!formError) return;
        formError.textContent = message;
        formError.hidden = false;
    }

    function openModal(modal, trigger) {
        if (!modal) return;
        lastFocusedElement = trigger || document.activeElement;
        activeModal = modal;
        modal.classList.add("active");
        modal.setAttribute("aria-hidden", "false");
        document.body.classList.add("um-modal-open");
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.remove("active");
        modal.setAttribute("aria-hidden", "true");
        if (activeModal === modal) activeModal = null;
        if (!activeModal) document.body.classList.remove("um-modal-open");
        const restoreTarget = lastFocusedElement;
        lastFocusedElement = null;
        if (restoreTarget && typeof restoreTarget.focus === "function" && document.contains(restoreTarget)) restoreTarget.focus();
    }

    function closeStaffModal() {
        closeModal(staffModal);
        staffForm?.reset();
        clearFormError();
    }

    function closeHistoryModal() {
        closeModal(historyModal);
        if (historyBody) historyBody.replaceChildren();
    }

    function modalFocusableElements(modal) {
        if (!modal) return [];
        return [...modal.querySelectorAll(
            'a[href], area[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )].filter((element) => !element.hidden && element.getAttribute("aria-hidden") !== "true");
    }

    function trapModalFocus(event) {
        if (event.key !== "Tab" || !activeModal) return;
        const focusableElements = modalFocusableElements(activeModal);
        if (!focusableElements.length) return;

        const firstFocusable = focusableElements[0];
        const lastFocusable = focusableElements[focusableElements.length - 1];
        const activeElement = document.activeElement;
        const activeIndex = focusableElements.indexOf(activeElement);
        const focusTarget = event.shiftKey
            ? (activeIndex <= 0 ? lastFocusable : null)
            : (activeIndex === -1 || activeIndex === focusableElements.length - 1 ? firstFocusable : null);

        if (focusTarget) {
            event.preventDefault();
            focusTarget.focus();
        }
    }

    document.querySelectorAll(".close-staff-modal").forEach((button) => button.addEventListener("click", closeStaffModal));
    document.querySelectorAll(".close-history-modal").forEach((button) => button.addEventListener("click", closeHistoryModal));
    [staffModal, historyModal].forEach((modal) => modal?.addEventListener("click", (event) => {
        if (event.target === modal) modal === staffModal ? closeStaffModal() : closeHistoryModal();
    }));
    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && activeModal) {
            if (activeModal === staffModal) closeStaffModal();
            else closeHistoryModal();
            return;
        }
        trapModalFocus(event);
    });

    function populateStaffForm(trigger, edit) {
        staffForm?.reset();
        clearFormError();
        document.getElementById("staff_user_id").value = edit ? (trigger.dataset.id || "") : "";
        document.getElementById("staff_name").value = edit ? (trigger.dataset.name || "") : "";
        document.getElementById("staff_email").value = edit ? (trigger.dataset.email || "") : "";
        document.getElementById("staff_phone").value = edit ? (trigger.dataset.phone || "") : "";
        document.getElementById("staff_address").value = edit ? (trigger.dataset.address || "") : "";
        document.getElementById("staff_department").value = edit ? (trigger.dataset.department || "") : "";
        document.getElementById("staff_job_title").value = edit ? (trigger.dataset.jobTitle || "") : "";
        document.getElementById("staff_hire_date").value = edit ? (trigger.dataset.hireDate || "") : "";
        document.getElementById("staff_role").value = edit ? (trigger.dataset.role || "staff") : "staff";
        document.getElementById("staffModalTitle").textContent = edit ? "Edit Staff Account" : "Add New Staff";
        passwordInput.value = "";
        passwordInput.required = !edit;
        passwordInput.placeholder = edit ? "Leave blank to keep current password" : "Enter a password";
        setHidden(passwordHint, !edit);
        openModal(staffModal, trigger);
        window.setTimeout(() => document.getElementById("staff_name")?.focus(), 0);
    }

    document.getElementById("openAddStaffBtn")?.addEventListener("click", (event) => populateStaffForm(event.currentTarget, false));
    document.querySelectorAll(".btn-staff-modal").forEach((button) => {
        button.addEventListener("click", () => populateStaffForm(button, true));
    });

    function validHireDate(value) {
        if (!value) return true;
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value) || (today && value > today)) return false;
        const parsed = new Date(`${value}T00:00:00`);
        return !Number.isNaN(parsed.getTime()) && parsed.toISOString().slice(0, 10) === value;
    }

    function validateStaffForm() {
        const name = document.getElementById("staff_name").value.trim();
        const phone = document.getElementById("staff_phone").value.trim();
        const address = document.getElementById("staff_address").value.trim();
        const department = document.getElementById("staff_department").value.trim();
        const jobTitle = document.getElementById("staff_job_title").value.trim();
        const hireDate = document.getElementById("staff_hire_date").value;
        if (!name || name.length > 150) return "Full name is required and must be 150 characters or fewer.";
        if (phone && (phone.length > 20 || !/^[0-9+().\-\s]+$/.test(phone))) return "Enter a valid work phone number.";
        if (address.length > 255) return "Residential address must be 255 characters or fewer.";
        if (department.length > 100 || jobTitle.length > 100) return "Department and job title must be 100 characters or fewer.";
        if (!validHireDate(hireDate)) return "Hire date must be a valid date that is not in the future.";
        if (passwordInput.value !== "" && window.SevillaPasswordPolicy?.validate) {
            const passwordPolicy = window.SevillaPasswordPolicy.validate(passwordInput.value);
            if (!passwordPolicy.valid) return passwordPolicy.message || "Password does not meet the required policy.";
        }
        return "";
    }

    async function requestJson(url, payload) {
        let response;
        try {
            response = await fetch(url, {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
                body: JSON.stringify(payload)
            });
        } catch (error) {
            throw new Error("Network error. Please check your connection and try again.");
        }
        const responseText = await response.text();
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (error) {
            throw new Error("The server returned an invalid response. Please refresh and try again.");
        }
        if (!response.ok || !data || data.success !== true) {
            throw new Error(data?.message || "The request could not be completed.");
        }
        return data;
    }

    staffForm?.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (staffSubmitting || !staffForm.reportValidity()) return;
        const validationMessage = validateStaffForm();
        if (validationMessage) {
            showFormError(validationMessage);
            return;
        }
        staffSubmitting = true;
        saveButton.disabled = true;
        saveButton.textContent = "Saving…";
        const userId = document.getElementById("staff_user_id").value;
        const payload = {
            action: userId ? "edit" : "add",
            user_id: userId,
            name: document.getElementById("staff_name").value.trim(),
            email: document.getElementById("staff_email").value.trim(),
            phone: document.getElementById("staff_phone").value.trim(),
            address: document.getElementById("staff_address").value.trim(),
            department: document.getElementById("staff_department").value.trim(),
            job_title: document.getElementById("staff_job_title").value.trim(),
            hire_date: document.getElementById("staff_hire_date").value,
            role: document.getElementById("staff_role").value,
            password: passwordInput.value
        };
        try {
            const data = await requestJson("actions/admin/manage_staff.php", payload);
            notify("Staff account saved", data.message, "success");
            window.location.reload();
        } catch (error) {
            showFormError(error.message || "The staff account could not be saved.");
            notify("Unable to save staff account", error.message || "Please try again.", "error");
        } finally {
            staffSubmitting = false;
            saveButton.disabled = false;
            saveButton.textContent = "Save Account";
        }
    });

    async function handleStaffLifecycle(button) {
        if (button.disabled) return;
        const action = button.dataset.action;
        const isArchive = action === "archive";
        const title = isArchive ? "Archive staff account" : "Restore staff account";
        const message = isArchive
            ? "Archive this staff account? Sign-in access will be disabled, while the account details and records are retained."
            : "Restore this staff account? Sign-in access will be enabled again.";
        if (!await confirmAction(title, message)) return;
        const originalText = button.textContent;
        button.disabled = true;
        button.textContent = isArchive ? "Archiving…" : "Restoring…";
        try {
            const data = await requestJson("actions/admin/manage_staff.php", { action, user_id: button.dataset.id });
            notify(title, data.message, "success");
            window.location.reload();
        } catch (error) {
            notify(title, error.message || "The request could not be completed.", "error");
            button.disabled = false;
            button.textContent = originalText;
        }
    }

    document.querySelectorAll(".btn-staff-lifecycle").forEach((button) => {
        button.addEventListener("click", () => handleStaffLifecycle(button));
    });

    function historyRow(values, className = "") {
        const row = document.createElement("tr");
        if (className) row.className = className;
        values.forEach((value) => {
            const cell = document.createElement("td");
            cell.textContent = value;
            row.appendChild(cell);
        });
        return row;
    }

    async function openCustomerHistory(button) {
        if (!historyBody) return;
        historyBody.replaceChildren(historyRow(["Loading booking history…", "", ""], "um-history-message"));
        openModal(historyModal, button);
        const customerId = button.dataset.id || "";
        try {
            const response = await fetch(`actions/admin/get_customer_history.php?id=${encodeURIComponent(customerId)}`, {
                headers: { "X-CSRF-Token": csrfToken, "X-Sevilla-Background": "true" }
            });
            const body = await response.text();
            let data;
            try { data = JSON.parse(body); } catch (error) { throw new Error("The server returned an invalid response."); }
            if (!response.ok || !data?.success) throw new Error(data?.message || "Booking history could not be loaded.");
            historyBody.replaceChildren();
            if (!Array.isArray(data.data) || data.data.length === 0) {
                historyBody.appendChild(historyRow(["No completed bookings found.", "", ""], "um-history-message"));
                return;
            }
            data.data.forEach((booking) => {
                const rawDate = typeof booking.start_date === "string" ? booking.start_date : "";
                const parsedDate = new Date(`${rawDate}T00:00:00`);
                const date = Number.isNaN(parsedDate.getTime())
                    ? rawDate
                    : parsedDate.toLocaleDateString("en-US", { month: "short", day: "2-digit", year: "numeric" });
                const amountNumber = Number(booking.total_amount);
                const amount = Number.isFinite(amountNumber)
                    ? `₱${amountNumber.toLocaleString("en-US", { minimumFractionDigits: 2 })}`
                    : "—";
                historyBody.appendChild(historyRow([date, String(booking.venue_name || "—"), amount]));
            });
        } catch (error) {
            historyBody.replaceChildren(historyRow([error.message || "Booking history could not be loaded.", "", ""], "um-history-message um-history-error"));
        }
    }

    document.querySelectorAll(".btn-history-modal").forEach((button) => {
        button.addEventListener("click", () => openCustomerHistory(button));
    });

    async function handleCustomerStatus(button) {
        if (button.disabled) return;
        const newStatus = button.dataset.action;
        const isSuspending = newStatus === "suspended";
        const actionText = isSuspending ? "suspend" : "reactivate";
        if (!await confirmAction("Confirm customer action", `Are you sure you want to ${actionText} this customer's account?`)) return;
        const originalText = button.textContent;
        button.disabled = true;
        button.textContent = "Processing…";
        try {
            const data = await requestJson("actions/admin/suspend_user.php", { user_id: button.dataset.id, action: newStatus });
            notify("Customer account updated", data.message, "success");
            window.location.reload();
        } catch (error) {
            notify("Unable to update customer account", error.message || "Please try again.", "error");
            button.disabled = false;
            button.textContent = originalText;
        }
    }

    document.querySelectorAll(".btn-suspend").forEach((button) => {
        button.addEventListener("click", () => handleCustomerStatus(button));
    });

    activateTab("staffTable");
});
