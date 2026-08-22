/**
 * SEVILLA360 - Admin Audit Log Scripts
 * Handles Server-Side Pagination, Smart Text Search, and Date Filtering
 */

document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('auditSearch');
    const dateInput = document.getElementById('auditDate');
    const tbody = document.getElementById('audit-tbody');

    const btnPrev = document.getElementById("btn-prev-page");
    const btnNext = document.getElementById("btn-next-page");
    const pagCurrent = document.getElementById("pag-current-page");
    const pagTotalPages = document.getElementById("pag-total-pages");
    const pagTotalRows = document.getElementById("pag-total-rows");

    let currentPage = 1;
    const rowsPerPage = 50;
    let searchTimeout = null;

    function loadAuditLogs() {
        tbody.innerHTML = `<tr><td colspan="4" style="text-align: center; padding: 40px; color: #888;">
                            <i class="fa-solid fa-circle-notch fa-spin" style="font-size: 1.5rem; margin-bottom: 10px; color: var(--color-gold);"></i><br>
                            Loading Audit Logs...
                           </td></tr>`;

        const searchTerm = searchInput ? searchInput.value.trim() : '';
        const dateTerm = dateInput ? dateInput.value : '';

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        fetch('actions/admin/get_audit_logs.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({
                page: currentPage,
                limit: rowsPerPage,
                search: searchTerm,
                date: dateTerm
            })
        })
        .then(res => res.json())
        .then(res => {
            if (!res.success) {
                tbody.innerHTML = `<tr><td colspan="4" style="text-align: center; color: red; padding: 20px;">Error: ${res.message}</td></tr>`;
                return;
            }
            renderTableRows(res.data);
            updatePaginationUI(res.pagination);
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="4" style="text-align: center; color: red; padding: 20px;">Network Error occurred.</td></tr>`;
        });
    }

    function renderTableRows(logs) {
        if (logs.length === 0) {
            tbody.innerHTML = `<tr><td colspan="4" style="text-align: center; padding: 20px; color: #888;">No audit logs found.</td></tr>`;
            return;
        }

        let html = '';
        logs.forEach(log => {
            // 1. Format the Date
            const dateObj = new Date(log.created_at);
            const dStr = dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            const tStr = dateObj.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
            const displayDate = `${dStr} - ${tStr}`;

            // 2. Dynamic Color Coding
            const actionLower = log.action.toLowerCase();
            let textClass = 'action-neutral'; // Default gray/blue

            if (actionLower.includes('cancel') || actionLower.includes('delete') || actionLower.includes('refund') || actionLower.includes('reject')) {
                textClass = 'action-negative'; // Red
            } else if (actionLower.includes('confirm') || actionLower.includes('approve') || actionLower.includes('add') || actionLower.includes('success')) {
                textClass = 'action-positive'; // Green
            }

            const staffName = log.staff_name || 'System';
            const moduleName = log.module || '';
            const ipAddress = log.ip_address || '';

            html += `
                <tr>
                    <td data-label="Date / Time">${displayDate}</td>
                    <td data-label="Staff / User" style="font-weight: 500;">${staffName}</td>
                    <td data-label="Action Taken" class="action-text ${textClass}">
                        <span class="action-main">${log.action}</span>
                        <span class="action-module" style="display:block; font-size: 0.75rem; color: #888; font-weight: normal;">Module: ${moduleName}</span>
                    </td>
                    <td data-label="IP Address" style="font-family: monospace; color: #666;">${ipAddress}</td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
    }

    function updatePaginationUI(pag) {
        pagCurrent.innerText = pag.current_page;
        pagTotalPages.innerText = pag.total_pages;

        let startItem = 0;
        let endItem = 0;
        if (pag.total_rows > 0) {
            startItem = (pag.current_page - 1) * pag.limit + 1;
            endItem = Math.min(pag.current_page * pag.limit, pag.total_rows);
        }

        pagTotalRows.innerText = `${startItem}-${endItem} of ${pag.total_rows}`;

        if (btnPrev) btnPrev.disabled = (pag.current_page <= 1);
        if (btnNext) btnNext.disabled = (pag.current_page >= pag.total_pages);
    }

    // Listen to keystrokes in the search bar
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => { currentPage = 1; loadAuditLogs(); }, 400); // 400ms typing delay
        });
    }

    // Listen to changes in the date picker
    if (dateInput) {
        dateInput.addEventListener('change', () => {
            currentPage = 1;
            loadAuditLogs();
        });
    }

    // Pagination button listeners
    if (btnPrev) btnPrev.addEventListener("click", () => { if (currentPage > 1) { currentPage--; loadAuditLogs(); } });
    if (btnNext) btnNext.addEventListener("click", () => { currentPage++; loadAuditLogs(); });

    // Initial load
    loadAuditLogs();

    // --- EXPORT TO CSV LOGIC ---
    // Exports only the current visible page
    const exportBtn = document.getElementById('btnExportCSV');
    if (exportBtn) {
        exportBtn.addEventListener('click', () => {
            let csv = [];
            const rows = document.querySelectorAll('#auditTable tr');

            for (let i = 0; i < rows.length; i++) {
                let row = rows[i];
                if (row.style.display === 'none' || row.querySelector("td[colspan]")) continue;

                let cols = row.querySelectorAll('td, th');
                let rowArray = [];

                for (let j = 0; j < cols.length; j++) {
                    // Extract main text (ignore the small "Module:" text if we can, or just grab all text)
                    let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, " ").trim();
                    data = data.replace(/"/g, '""');
                    rowArray.push('"' + data + '"');
                }
                csv.push(rowArray.join(','));
            }

            const csvString = csv.join('\n');
            const blob = new Blob([csvString], { type: 'text/csv;charset=utf-8;' });
            const url = window.URL.createObjectURL(blob);

            const link = document.createElement('a');
            link.setAttribute('href', url);
            link.setAttribute('download', `Sevilla360_Audit_Log_Page${currentPage}_${new Date().toLocaleDateString('en-CA')}.csv`);
            link.style.visibility = 'hidden';

            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        });
    }
});