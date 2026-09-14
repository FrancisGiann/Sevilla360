/** Admin audit log: paginated list, safe detail dialog, and CSV export. */

document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('auditSearch');
    const dateInput = document.getElementById('auditDate');
    const tbody = document.getElementById('audit-tbody');
    const btnPrev = document.getElementById('btn-prev-page');
    const btnNext = document.getElementById('btn-next-page');
    const pagCurrent = document.getElementById('pag-current-page');
    const pagTotalPages = document.getElementById('pag-total-pages');
    const pagTotalRows = document.getElementById('pag-total-rows');
    const detailDialog = document.getElementById('audit-detail-dialog');
    const detailStatus = document.getElementById('audit-detail-status');
    const detailFields = document.getElementById('audit-detail-fields');
    const closeButtons = [document.getElementById('audit-detail-close'), document.getElementById('audit-detail-done')];
    const viewBookingButton = document.getElementById('audit-view-booking');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    let currentPage = 1;
    const rowsPerPage = 50;
    let searchTimeout = null;
    let lastInvoker = null;
    let activeBookingId = null;

    function setStatusRow(message, isError = false) {
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = 4;
        cell.className = isError ? 'audit-load-error' : '';
        cell.textContent = message;
        row.appendChild(cell);
        tbody.replaceChildren(row);
    }

    function formatListTimestamp(value) {
        const date = new Date(value);
        if (!Number.isFinite(date.getTime())) return String(value || 'Unknown date');
        const datePart = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        const timePart = date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
        return `${datePart} - ${timePart}`;
    }

    async function loadAuditLogs() {
        setStatusRow('Loading audit logs…');
        const response = await fetch('actions/admin/get_audit_logs.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({
                page: currentPage,
                limit: rowsPerPage,
                search: searchInput?.value.trim() || '',
                date: dateInput?.value || '',
            }),
        });
        const result = await response.json();
        if (!response.ok || !result?.success || !Array.isArray(result.data)) {
            throw new Error(typeof result?.message === 'string' ? result.message : 'Audit logs could not be loaded.');
        }
        renderTableRows(result.data);
        updatePaginationUI(result.pagination);
    }

    function renderTableRows(logs) {
        if (logs.length === 0) {
            setStatusRow('No audit logs found.');
            return;
        }

        const rows = logs.map((log) => {
            const auditId = Number(log?.id);
            const row = document.createElement('tr');
            row.dataset.auditId = Number.isSafeInteger(auditId) && auditId > 0 ? String(auditId) : '';
            row.tabIndex = 0;
            row.setAttribute('aria-haspopup', 'dialog');

            const timestamp = String(log?.created_at || 'Unknown date');
            const actor = String(log?.staff_name || 'System');
            const role = String(log?.actor_role || 'system');
            const action = String(log?.action || '');
            row.setAttribute('aria-label', `View audit details: ${action}. Actor: ${actor}, ${role}. ${formatListTimestamp(timestamp)}.`);

            const dateCell = document.createElement('td');
            dateCell.dataset.label = 'Date / Time';
            dateCell.textContent = formatListTimestamp(timestamp);

            const actorCell = document.createElement('td');
            actorCell.dataset.label = 'Staff / User';
            actorCell.className = 'audit-actor-cell';
            const actorName = document.createElement('span');
            const actorWrapper = document.createElement('div');
            actorWrapper.className = 'audit-actor-wrapper';
            actorName.textContent = actor;
            const actorRole = document.createElement('small');
            actorRole.textContent = role;
            actorWrapper.append(actorName, actorRole);
            actorCell.append(actorWrapper);

            const actionCell = document.createElement('td');
            actionCell.dataset.label = 'Action Taken';
            const actionLower = action.toLowerCase();
            actionCell.className = `action-text ${actionLower.includes('cancel') || actionLower.includes('delete') || actionLower.includes('refund') || actionLower.includes('reject') ? 'action-negative' : (actionLower.includes('confirm') || actionLower.includes('approve') || actionLower.includes('add') || actionLower.includes('success') ? 'action-positive' : 'action-neutral')}`;
            const actionText = document.createElement('span');
            actionText.className = 'action-main';
            actionText.textContent = action;
            const openButton = document.createElement('button');
            openButton.type = 'button';
            openButton.className = 'audit-row-open';
            openButton.textContent = 'View details';
            openButton.disabled = !row.dataset.auditId;
            actionCell.append(actionText, openButton);

            const ipCell = document.createElement('td');
            ipCell.dataset.label = 'IP Address';
            ipCell.className = 'audit-ip-cell';
            ipCell.textContent = String(log?.ip_address || '—');

            row.append(dateCell, actorCell, actionCell, ipCell);
            row.addEventListener('click', () => {
                if (row.dataset.auditId) openAuditDetails(row.dataset.auditId, row);
            });
            row.addEventListener('keydown', (event) => {
                if (event.target !== row || (event.key !== 'Enter' && event.key !== ' ')) return;
                event.preventDefault();
                if (row.dataset.auditId) openAuditDetails(row.dataset.auditId, row);
            });
            return row;
        });
        tbody.replaceChildren(...rows);
    }

    function updatePaginationUI(pagination) {
        const current = Number(pagination?.current_page) || 1;
        const totalPages = Math.max(1, Number(pagination?.total_pages) || 1);
        const totalRows = Math.max(0, Number(pagination?.total_rows) || 0);
        const limit = Math.max(1, Number(pagination?.limit) || rowsPerPage);
        pagCurrent.textContent = String(current);
        pagTotalPages.textContent = String(totalPages);
        const start = totalRows > 0 ? (current - 1) * limit + 1 : 0;
        const end = Math.min(current * limit, totalRows);
        pagTotalRows.textContent = `${start}-${end} of ${totalRows}`;
        if (btnPrev) btnPrev.disabled = current <= 1;
        if (btnNext) btnNext.disabled = current >= totalPages;
    }

    function appendDetail(fields, label, value) {
        const term = document.createElement('dt');
        term.textContent = label;
        const description = document.createElement('dd');
        description.textContent = String(value);
        fields.append(term, description);
    }

    function csvSafeValue(value) {
        const text = String(value ?? '');
        const trimmed = text.replace(/^[\s\p{Cc}\p{Cf}\p{Z}]+/u, '');
        return trimmed !== '' && /^[=+\-@]/u.test(trimmed) ? `'${text}` : text;
    }

    const detailLabels = Object.freeze({
        booking_reference: 'Booking reference',
        payment_method: 'Payment method',
        amount: 'Amount',
        submission_id: 'Submission ID',
        payment_id: 'Payment ID',
        reason: 'Reason',
        booking_id: 'Booking ID',
        cancellation_id: 'Refund request ID',
        refund_amount: 'Refund amount',
        decision: 'Decision',
        refund_transaction_reference_masked: 'Refund transaction reference',
        role: 'Login role',
    });

    function renderAuditDetails(data) {
        detailFields.replaceChildren();
        appendDetail(detailFields, 'Timestamp', data.created_at);
        appendDetail(detailFields, 'Actor', data.actor_name || 'System');
        appendDetail(detailFields, 'Actor role', data.actor_role || 'system');
        appendDetail(detailFields, 'Action', data.action || '');
        appendDetail(detailFields, 'Module', data.module || '');
        appendDetail(detailFields, 'IP address', data.ip_address || '—');

        const details = data.details && typeof data.details === 'object' && !Array.isArray(data.details) ? data.details : {};
        Object.entries(details).forEach(([key, value]) => {
            if (!Object.prototype.hasOwnProperty.call(detailLabels, key) || value === null || value === undefined) return;
            appendDetail(detailFields, detailLabels[key], value);
        });

        const bookingId = Number(data.entity_id);
        activeBookingId = data.entity_type === 'booking' && Number.isSafeInteger(bookingId) && bookingId > 0 ? bookingId : null;
        viewBookingButton.hidden = activeBookingId === null;
        detailStatus.textContent = Object.keys(details).length === 0 ? 'No structured details are available for this historical entry.' : '';
    }

    async function openAuditDetails(id, invoker) {
        if (!detailDialog || typeof detailDialog.showModal !== 'function') return;
        lastInvoker = invoker;
        activeBookingId = null;
        detailFields.replaceChildren();
        viewBookingButton.hidden = true;
        detailStatus.textContent = 'Loading audit details…';
        if (!detailDialog.open) detailDialog.showModal();
        try {
            const response = await fetch('actions/admin/get_audit_log_detail.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ id }),
            });
            const result = await response.json();
            if (!response.ok || !result?.success || !result.data || typeof result.data !== 'object') {
                throw new Error(typeof result?.message === 'string' ? result.message : 'Audit details could not be loaded.');
            }
            renderAuditDetails(result.data);
        } catch (error) {
            detailStatus.textContent = error instanceof Error ? error.message : 'Audit details could not be loaded.';
        }
    }

    function closeAuditDetails() {
        if (detailDialog?.open) detailDialog.close();
    }

    closeButtons.forEach((button) => button?.addEventListener('click', closeAuditDetails));
    detailDialog?.addEventListener('close', () => {
        activeBookingId = null;
        if (lastInvoker && document.contains(lastInvoker)) lastInvoker.focus();
    });
    viewBookingButton?.addEventListener('click', () => {
        if (activeBookingId === null) return;
        window.location.href = `admin_dashboard.php?page=bookings&booking_id=${encodeURIComponent(String(activeBookingId))}`;
    });

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                currentPage = 1;
                loadAuditLogs().catch(() => setStatusRow('Audit logs could not be loaded.', true));
            }, 400);
        });
    }
    dateInput?.addEventListener('change', () => {
        currentPage = 1;
        loadAuditLogs().catch(() => setStatusRow('Audit logs could not be loaded.', true));
    });
    btnPrev?.addEventListener('click', () => {
        if (currentPage <= 1) return;
        currentPage -= 1;
        loadAuditLogs().catch(() => setStatusRow('Audit logs could not be loaded.', true));
    });
    btnNext?.addEventListener('click', () => {
        currentPage += 1;
        loadAuditLogs().catch(() => setStatusRow('Audit logs could not be loaded.', true));
    });
    loadAuditLogs().catch(() => setStatusRow('Audit logs could not be loaded.', true));

    const exportButton = document.getElementById('btnExportCSV');
    exportButton?.addEventListener('click', () => {
        const csvRows = [];
        document.querySelectorAll('#auditTable tr').forEach((row) => {
            if (row.style.display === 'none' || row.querySelector('td[colspan]')) return;
            const columns = Array.from(row.querySelectorAll('td, th'), (cell) => {
                const safeValue = csvSafeValue(cell.innerText.replace(/[\r\n]+/g, ' ').trim());
                return `"${safeValue.replace(/"/g, '""')}"`;
            });
            csvRows.push(columns.join(','));
        });
        const blob = new Blob([csvRows.join('\n')], { type: 'text/csv;charset=utf-8;' });
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `Sevilla360_Audit_Log_Page${currentPage}_${new Date().toLocaleDateString('en-CA')}.csv`;
        link.hidden = true;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
    });
});
