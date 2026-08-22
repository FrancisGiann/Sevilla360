<?php
// Ensure this is only accessible by superadmins
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo '<div class="unauthorized-access"><h3>Unauthorized Access</h3></div>';
    exit;
}
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
            <tbody id="audit-tbody">
                <tr>
                    <td colspan="4" style="text-align: center; padding: 40px; color: #888;">
                        <i class="fa-solid fa-circle-notch fa-spin" style="font-size: 1.5rem; margin-bottom: 10px; color: var(--color-gold);"></i><br>
                        Loading Audit Logs...
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Server-Side Pagination Controls -->
        <div class="pagination-controls pagination-wrapper" style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
            <div class="pagination-info" style="font-size: 0.9rem; color: #666;">
                Showing <span id="pag-total-rows" class="pagination-bold-dark" style="font-weight:600; color:#333;">0</span> logs
            </div>
            <div class="pagination-controls-right" style="display: flex; align-items: center; gap: 15px;">
                <span class="pagination-page-label" style="font-size: 0.9rem; color: #666;">Page <span id="pag-current-page" class="text-gold-bold" style="font-weight:600; color:var(--color-gold);">1</span> of <span id="pag-total-pages">1</span></span>
                <button id="btn-prev-page" class="btn-outline btn-pag" disabled style="padding: 6px 12px; border: 1px solid #ddd; background: #fff; cursor: pointer; border-radius: 4px;">&laquo; Prev</button>
                <button id="btn-next-page" class="btn-outline btn-pag" disabled style="padding: 6px 12px; border: 1px solid #ddd; background: #fff; cursor: pointer; border-radius: 4px;">Next &raquo;</button>
            </div>
        </div>
    </div>
</div>
