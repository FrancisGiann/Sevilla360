<section class="database-backup-page" aria-label="Database backup and recovery">
    <div class="database-backup-intro">
        <p>Signed, compressed database archives. Files stay outside the website directory; downloads are intended for off-host storage.</p>
        <button type="button" class="database-backup-refresh" id="database-backup-refresh">Refresh status</button>
    </div>

    <div class="database-backup-alert" id="database-backup-maintenance" role="status" hidden></div>
    <div class="database-backup-alert database-backup-alert-info" id="database-backup-capability" role="status" hidden></div>
    <div class="database-backup-alert" id="database-backup-message" role="status" aria-live="polite" hidden></div>

    <section class="database-backup-section" aria-labelledby="database-backup-tools-title">
        <div class="database-backup-section-heading">
            <div><h3 id="database-backup-tools-title">Create or import an archive</h3><p>Manual jobs run through the CLI worker. Scheduled snapshots retain seven days.</p></div>
        </div>
        <div class="database-backup-tools">
            <div class="database-backup-tool">
                <p class="database-backup-tool-title">Create a current snapshot</p>
                <p>Queue a fresh compressed and signed database-only backup.</p>
                <button type="button" class="database-backup-button" id="database-backup-create">Create backup</button>
            </div>
            <form class="database-backup-tool" id="database-backup-upload-form" enctype="multipart/form-data">
                <label class="database-backup-file-label" for="database-backup-file">Upload a signed archive</label>
                <p>Only Sevilla360 archive files are accepted. Arbitrary SQL uploads are rejected.</p>
                <div class="database-backup-upload-row">
                    <input id="database-backup-file" name="backup_file" type="file" accept=".s360db,application/octet-stream" required>
                    <button type="submit" class="database-backup-button database-backup-button-secondary">Upload and verify</button>
                </div>
            </form>
        </div>
    </section>

    <section class="database-backup-section database-backup-restore" aria-labelledby="database-backup-restore-title">
        <div class="database-backup-section-heading">
            <div><h3 id="database-backup-restore-title">Restore a snapshot</h3><p>The worker checks compatibility on staging and creates a safety backup before production changes.</p></div>
        </div>
        <form id="database-backup-restore-form" autocomplete="off">
            <div class="database-backup-restore-fields">
                <label>Archive
                    <select id="database-backup-restore-id" required>
                        <option value="">Choose an archive for restore preflight</option>
                    </select>
                </label>
                <label>Current administrator password
                    <input id="database-backup-password" type="password" autocomplete="current-password" required maxlength="1024">
                </label>
                <label>Type RESTORE to confirm
                    <input id="database-backup-confirmation" type="text" autocomplete="off" required maxlength="7" pattern="RESTORE">
                </label>
            </div>
            <button type="submit" class="database-backup-button database-backup-button-danger" id="database-backup-restore">Queue restore</button>
            <p class="database-backup-help">Web writes pause during production restore. The worker verifies the restored database before reopening writes. If automatic recovery fails, maintenance stays active.</p>
        </form>
    </section>

    <section class="database-backup-section" aria-labelledby="database-backup-archives-title">
        <div class="database-backup-section-heading"><div><h3 id="database-backup-archives-title">Private archives</h3><p>Status checks signed metadata only. Payload checksum and compression are verified before download, upload acceptance, and restore.</p></div><span id="database-backup-archive-count" class="database-backup-count">0</span></div>
        <div class="database-backup-table-wrap">
            <table class="database-backup-table">
                <thead><tr><th>Created</th><th>Type</th><th>Size</th><th>Header check</th><th>Actions</th></tr></thead>
                <tbody id="database-backup-archives"><tr><td colspan="5" class="database-backup-empty">Loading archives…</td></tr></tbody>
            </table>
        </div>
    </section>

    <section class="database-backup-section" aria-labelledby="database-backup-jobs-title">
        <div class="database-backup-section-heading"><div><h3 id="database-backup-jobs-title">Recent jobs</h3><p>The status route remains available during database maintenance.</p></div><span id="database-backup-job-count" class="database-backup-count">0</span></div>
        <div class="database-backup-table-wrap">
            <table class="database-backup-table">
                <thead><tr><th>Request</th><th>State</th><th>Progress</th><th>Details</th><th>Safety archive</th></tr></thead>
                <tbody id="database-backup-jobs"><tr><td colspan="5" class="database-backup-empty">Loading jobs…</td></tr></tbody>
            </table>
        </div>
    </section>
</section>
