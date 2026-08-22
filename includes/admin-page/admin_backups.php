<?php
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo '<div class="unauthorized-access"><h3>Unauthorized Access</h3></div>'; exit;
}
require_once 'config/db_connect.php';

// Fetch Backups
$backups_query = $conn->query("
    SELECT b.id, b.filename, b.file_size, b.created_at, s.full_name
    FROM backups b 
    LEFT JOIN staff s ON b.created_by = s.user_id 
    ORDER BY b.created_at DESC
");
$backup_list = $backups_query->fetch_all(MYSQLI_ASSOC);

// Helper function to format bytes
function formatBytes($bytes, $precision = 2) { 
    $units = array('B', 'KB', 'MB', 'GB', 'TB'); 
    $bytes = max($bytes, 0); 
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024)); 
    $pow = min($pow, count($units) - 1); 
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow]; 
}
?>

<div class="um-container backups-container">
    <div class="um-header">
        <div class="um-tabs">
            <h3 style="margin: 0; color: var(--color-dark); font-family: 'Playfair Display', serif;">Database Backups</h3>
        </div>
        <div class="um-controls">
            <div class="um-search-wrapper">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="backupSearch" placeholder="Search backups...">
            </div>
            
            <input type="file" id="importBackupInput" accept=".sql" style="display: none;">
            <button class="btn btn-secondary" id="btnImportBackup">
                <span class="btn-text"><i class="fa-solid fa-upload"></i> Import Backup</span>
                <span class="btn-spinner hidden"><i class="fa-solid fa-circle-notch fa-spin"></i> Uploading...</span>
            </button>

            <button class="btn btn-primary" id="btnCreateBackup">
                <span class="btn-text"><i class="fa-solid fa-plus"></i> Create New Backup</span>
                <span class="btn-spinner hidden"><i class="fa-solid fa-circle-notch fa-spin"></i> Generating...</span>
            </button>
        </div>
    </div>

    <div class="um-card">
        <table class="um-table active" id="backupsTable">
            <thead>
                <tr>
                    <th>Filename</th>
                    <th>Date Created</th>
                    <th>Size</th>
                    <th>Created By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($backup_list)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 30px; color: #777;">No backups found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($backup_list as $b): ?>
                    <?php $isProtected = str_starts_with($b['filename'], 'sevilla360_imported_') || str_starts_with($b['filename'], 'sevilla360_pre_restore_'); ?>
                    <tr>
                        <td><i class="fa-solid fa-file-lines" style="color: #666; margin-right: 8px;"></i> <?php echo htmlspecialchars($b['filename']); ?></td>
                        <td><?php echo date('M d, Y h:i A', strtotime($b['created_at'])); ?></td>
                        <td><?php echo formatBytes($b['file_size']); ?></td>
                        <td><?php echo $isProtected ? 'Protected' : htmlspecialchars($b['full_name'] ?? 'System'); ?></td>
                        <td class="action-cells">
                            <button class="btn-icon btn-download" data-id="<?php echo $b['id']; ?>" data-filename="<?php echo htmlspecialchars($b['filename']); ?>" title="Download">
                                <i class="fa-solid fa-download"></i>
                            </button>
                            <button class="btn-icon btn-restore" data-id="<?php echo $b['id']; ?>" data-filename="<?php echo htmlspecialchars($b['filename']); ?>" title="Restore">
                                <i class="fa-solid fa-clock-rotate-left"></i>
                            </button>
                            <button class="btn-icon btn-delete" data-id="<?php echo $b['id']; ?>" data-filename="<?php echo htmlspecialchars($b['filename']); ?>" title="Delete">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Restore Confirmation Modal -->
<div class="global-modal-overlay" id="restoreModal">
    <div class="global-modal-content" style="max-width: 450px;">
        <div class="global-modal-header" style="border-bottom: 1px solid #eee; padding-bottom: 15px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="color: #ef4444; margin: 0; display: flex; align-items: center; gap: 8px;"><i class="fa-solid fa-triangle-exclamation"></i> Critical Action</h3>
            <button class="global-modal-close" id="closeRestoreModalBtn" style="background:none; border:none; font-size: 1.2rem; cursor: pointer; color: #777;"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="global-modal-body">
            <p style="margin-bottom: 15px; font-size: 1rem;">You are about to restore the database from backup: <strong id="restoreFilename"></strong>.</p>
            
            <div style="background-color: #fff1f2; border-left: 4px solid #ef4444; padding: 12px 15px; border-radius: 4px; margin-bottom: 20px; text-align: left;">
                <p style="color: #991b1b; font-weight: 600; margin-top: 0; margin-bottom: 8px; font-size: 0.95rem;">
                    <i class="fa-solid fa-circle-info"></i> The following data will be completely overwritten:
                </p>
                <ul style="color: #b91c1c; font-size: 0.85rem; padding-left: 20px; margin: 0; line-height: 1.5;">
                    <li><strong>Bookings & Payments</strong> made after this backup date will be permanently lost.</li>
                    <li><strong>Customer Accounts</strong> registered after this backup date will be erased.</li>
                    <li><strong>Staff Roles & Settings</strong> will revert to their exact state at the time of backup.</li>
                    <li><strong>Audit Logs</strong> and <strong>Media CMS</strong> changes will be rolled back.</li>
                    <li>A signed <strong>pre-restore safety backup</strong> is created automatically before this action.</li>
                </ul>
            </div>

            <div style="text-align: left;">
                <label for="restoreConfirmInput" style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 8px; color: #555;">Type <strong style="color:#ef4444;">RESTORE</strong> to confirm:</label>
                <input type="text" id="restoreConfirmInput" class="form-control" placeholder="RESTORE" autocomplete="off" style="width: 100%; border: 1px solid #ccc; border-radius: 4px; padding: 10px; font-family: monospace; font-size: 1rem; text-align: center; letter-spacing: 2px;">
            </div>
        </div>
        <div class="global-modal-footer" style="margin-top: 25px; text-align: right; display: flex; justify-content: flex-end; gap: 10px;">
            <button class="btn btn-secondary" id="cancelRestoreBtn" style="padding: 8px 16px; border: 1px solid #ccc; background: white; border-radius: 4px; cursor: pointer;">Cancel</button>
            <button class="btn btn-primary" id="btnConfirmRestore" style="background: #ef4444; border: 1px solid #ef4444; color: white; padding: 8px 16px; border-radius: 4px; cursor: pointer;" disabled>
                <span class="btn-text">Restore Database</span>
                <span class="btn-spinner hidden"><i class="fa-solid fa-circle-notch fa-spin"></i> Restoring...</span>
            </button>
        </div>
    </div>
</div>

<script src="assets/js/admin-page/admin_backups.js?v=<?= time() ?>"></script>
