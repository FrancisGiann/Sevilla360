<?php
// Ensure this is only accessible by superadmins
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo '<div class="unauthorized-access"><h3>Unauthorized Access</h3></div>';
    exit;
}

require_once 'config/db_connect.php';

// Fetch the last 150 Audit Logs
// We use COALESCE to grab the Staff Name, or fallback to the User Email, or fallback to 'System'
$query = "
    SELECT 
        a.created_at, 
        a.action, 
        a.module, 
        a.ip_address, 
        COALESCE(s.full_name, u.email, 'System') as staff_name
    FROM audit_logs a
    LEFT JOIN users u ON a.user_id = u.id
    LEFT JOIN staff s ON u.id = s.user_id
    ORDER BY a.created_at DESC
    LIMIT 150
";
$result = $conn->query($query);
$logs = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
?>

<!-- Audit Log Container -->
<div class="audit-log-container">

    <!-- Header & Controls -->
    <div class="audit-log-header">
        <div class="audit-titles">
            <p>Admin Access Only - Track all staff activity.</p>
        </div>
        <div class="audit-controls">
            <div class="input-wrapper">
                <!-- SVG Search Icon -->
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <input type="text" id="auditSearch" placeholder="Search staff or action...">
            </div>
            <div class="input-wrapper">
                <input type="date" id="auditDate">
            </div>

            <button id="btnExportCSV" class="btn-export-csv"><i class="fa-solid fa-file-csv"></i> Export</button>
        </div>
    </div>

    <!-- Table Card -->
    <div class="audit-table-card">
        <table class="audit-table" id="auditTable">
            <thead>
                <tr>
                    <th>Date/Time</th>
                    <th>Staff / User</th>
                    <th>Action Taken</th>
                    <th>IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="4" style="text-align: center; padding: 20px; color: #888;">No audit logs recorded yet.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($logs as $log): 
                        // 1. Format the Date
                        $dateObj = new DateTime($log['created_at']);
                        $displayDate = $dateObj->format('M d, Y - h:i A');
                        $filterDate = $dateObj->format('Y-m-d'); // Hidden attribute for the JS Date Picker

                        // 2. Dynamic Color Coding
                        $actionLower = strtolower($log['action']);
                        $text_class = 'action-neutral'; // Default gray/blue

                        if (strpos($actionLower, 'cancel') !== false || strpos($actionLower, 'delete') !== false || strpos($actionLower, 'refund') !== false || strpos($actionLower, 'reject') !== false) {
                            $text_class = 'action-negative'; // Red
                        } elseif (strpos($actionLower, 'confirm') !== false || strpos($actionLower, 'approve') !== false || strpos($actionLower, 'add') !== false || strpos($actionLower, 'success') !== false) {
                            $text_class = 'action-positive'; // Green
                        }
                    ?>
                <!-- data-date is used by the Javascript Date filter! -->
                <tr data-date="<?php echo $filterDate; ?>">
                    <td><?php echo $displayDate; ?></td>
                    <td style="font-weight: 500;"><?php echo htmlspecialchars($log['staff_name']); ?></td>
                    <td class="action-text <?php echo $text_class; ?>">
                        <?php echo htmlspecialchars($log['action']); ?>
                        <span style="display:block; font-size: 0.75rem; color: #888; font-weight: normal;">Module:
                            <?php echo htmlspecialchars($log['module']); ?></span>
                    </td>
                    <td style="font-family: monospace; color: #666;"><?php echo htmlspecialchars($log['ip_address']); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>