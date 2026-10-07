(() => {
    'use strict';

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const statusUrl = 'actions/admin/get_database_backup_status.php';
    const message = document.getElementById('database-backup-message');
    const archiveBody = document.getElementById('database-backup-archives');
    const jobsBody = document.getElementById('database-backup-jobs');
    const restoreId = document.getElementById('database-backup-restore-id');
    const createButton = document.getElementById('database-backup-create');
    const uploadButton = document.querySelector('#database-backup-upload-form button[type="submit"]');
    const restoreButton = document.getElementById('database-backup-restore');
    const formatDate = (value) => {
        if (!value) return '—';
        const parsed = new Date(value);
        return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleString();
    };
    const formatSize = (bytes) => {
        const size = Number(bytes);
        if (!Number.isFinite(size) || size < 0) return '—';
        if (size < 1024) return `${size} B`;
        if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`;
        return `${(size / (1024 * 1024)).toFixed(1)} MB`;
    };
    const setMessage = (text, isError = false) => {
        message.textContent = text;
        message.classList.toggle('database-backup-alert-info', !isError);
        message.hidden = !text;
    };
    const makeCell = (row, value) => {
        const cell = document.createElement('td');
        cell.textContent = value == null || value === '' ? '—' : String(value);
        row.appendChild(cell);
        return cell;
    };
    const setEmpty = (body, columns, text) => {
        body.replaceChildren();
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = columns;
        cell.className = 'database-backup-empty';
        cell.textContent = text;
        row.appendChild(cell);
        body.appendChild(row);
    };
    const downloadButton = (id) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'database-backup-action';
        button.dataset.archiveId = String(id);
        button.textContent = 'Download';
        return button;
    };

    async function requestJson(url, options = {}) {
        const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', ...options });
        let data;
        try { data = await response.json(); } catch (_) { throw new Error('The server returned an unreadable response.'); }
        if (!response.ok || !data.success) throw new Error(data.message || 'The request failed.');
        return data;
    }

    function renderArchives(archives, canRestore) {
        archiveBody.replaceChildren();
        restoreId.replaceChildren();
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Choose an archive for restore preflight';
        restoreId.appendChild(placeholder);
        document.getElementById('database-backup-archive-count').textContent = String(archives.length);
        if (!archives.length) {
            setEmpty(archiveBody, 5, 'No private archives have been created or uploaded.');
            return;
        }
        for (const archive of archives) {
            const row = document.createElement('tr');
            makeCell(row, formatDate(archive.created_at));
            makeCell(row, archive.kind);
            makeCell(row, formatSize(archive.bytes));
            const integrityCell = document.createElement('td');
            const badge = document.createElement('span');
            const headerValid = archive.header_valid === true;
            badge.className = `database-backup-status database-backup-status-${headerValid ? 'valid' : 'invalid'}`;
            badge.textContent = headerValid ? 'Signed metadata · payload unchecked' : 'Metadata invalid';
            integrityCell.appendChild(badge);
            row.appendChild(integrityCell);
            const actions = document.createElement('td');
            if (headerValid) actions.appendChild(downloadButton(archive.id));
            else actions.textContent = '—';
            if (headerValid) {
                const option = document.createElement('option');
                option.value = archive.id;
                option.textContent = `${formatDate(archive.created_at)} · ${archive.kind} · ${formatSize(archive.bytes)}`;
                restoreId.appendChild(option);
            }
            row.appendChild(actions);
            archiveBody.appendChild(row);
        }
        restoreButton.disabled = !canRestore || restoreId.options.length < 2;
    }

    function renderJobs(jobs) {
        jobsBody.replaceChildren();
        document.getElementById('database-backup-job-count').textContent = String(jobs.length);
        if (!jobs.length) {
            setEmpty(jobsBody, 5, 'No recent backup jobs.');
            return;
        }
        for (const job of jobs) {
            const row = document.createElement('tr');
            makeCell(row, `${job.action || 'job'} · ${formatDate(job.created_at)}`);
            const stateCell = document.createElement('td');
            const badge = document.createElement('span');
            const status = String(job.status || 'unknown').replace(/[^a-z_]/gi, '').toLowerCase();
            badge.className = `database-backup-status database-backup-status-${status}`;
            badge.textContent = String(job.status || 'unknown').replaceAll('_', ' ');
            stateCell.appendChild(badge);
            row.appendChild(stateCell);
            makeCell(row, job.phase);
            makeCell(row, job.message);
            const safetyCell = document.createElement('td');
            if (job.safety_archive_id) {
                safetyCell.appendChild(downloadButton(job.safety_archive_id));
            } else {
                safetyCell.textContent = '—';
            }
            row.appendChild(safetyCell);
            jobsBody.appendChild(row);
        }
    }

    async function refresh() {
        try {
            const data = await requestJson(statusUrl, { method: 'GET' });
            const capability = document.getElementById('database-backup-capability');
            const messages = [...(data.capabilities.messages || [])];
            if (data.capabilities.restore_message) messages.push(data.capabilities.restore_message);
            capability.textContent = messages.join(' ');
            capability.hidden = messages.length === 0;
            createButton.disabled = !data.capabilities.enabled;
            uploadButton.disabled = !data.capabilities.restore_enabled;
            if (data.maintenance) {
                const gate = document.getElementById('database-backup-maintenance');
                gate.textContent = `Production database maintenance is active: ${data.maintenance.phase || data.maintenance.state || 'in progress'}. Restore and backup status remain available.`;
                gate.hidden = false;
            } else {
                document.getElementById('database-backup-maintenance').hidden = true;
            }
            renderArchives(data.archives || [], data.capabilities.restore_enabled);
            renderJobs(data.jobs || []);
            setMessage('');
            if ((data.jobs || []).some((job) => ['queued', 'running'].includes(job.status))) window.setTimeout(refresh, 5000);
        } catch (error) {
            setMessage(error.message || 'Backup status could not be loaded.', true);
        }
    }

    createButton.addEventListener('click', async () => {
        createButton.disabled = true;
        try {
            const data = await requestJson('actions/admin/create_database_backup.php', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf } });
            setMessage(data.message);
        } catch (error) {
            setMessage(error.message, true);
        } finally {
            createButton.disabled = false;
            await refresh();
        }
    });

    document.getElementById('database-backup-upload-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        uploadButton.disabled = true;
        try {
            const data = await requestJson('actions/admin/upload_database_backup.php', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf },
                body: new FormData(form),
            });
            form.reset();
            setMessage(data.message);
        } catch (error) {
            setMessage(error.message, true);
        } finally {
            uploadButton.disabled = false;
            await refresh();
        }
    });

    document.getElementById('database-backup-restore-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const passwordInput = document.getElementById('database-backup-password');
        const confirmationInput = document.getElementById('database-backup-confirmation');
        restoreButton.disabled = true;
        try {
            const data = await requestJson('actions/admin/restore_database_backup.php', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json' },
                body: JSON.stringify({ archive_id: restoreId.value, password: passwordInput.value, confirmation: confirmationInput.value }),
            });
            passwordInput.value = '';
            confirmationInput.value = '';
            setMessage(data.message);
        } catch (error) {
            passwordInput.value = '';
            setMessage(error.message, true);
        } finally {
            restoreButton.disabled = false;
            await refresh();
        }
    });

    document.getElementById('database-backup-refresh').addEventListener('click', refresh);
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('button[data-archive-id]');
        if (!button) return;
        button.disabled = true;
        try {
            const body = new URLSearchParams({ id: button.dataset.archiveId });
            const response = await fetch('actions/admin/download_database_backup.php', {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body,
            });
            if (!response.ok) {
                const error = await response.json().catch(() => ({}));
                throw new Error(error.message || 'The archive could not be downloaded.');
            }
            const blob = await response.blob();
            const objectUrl = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = objectUrl;
            link.download = 'sevilla360-database-backup.s360db';
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
        } catch (error) {
            setMessage(error.message || 'The archive could not be downloaded.', true);
        } finally {
            button.disabled = false;
        }
    });
    refresh();
})();
