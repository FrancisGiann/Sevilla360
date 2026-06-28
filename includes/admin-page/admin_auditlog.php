<!-- Audit Log Container -->
<div class="audit-log-container">

    <!-- Header & Controls -->
    <div class="audit-log-header">
        <div class="audit-titles">
            <p>Super Admin Access Only - Track all staff activity.</p>
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
        </div>
    </div>

    <!-- Table Card -->
    <div class="audit-table-card">
        <table class="audit-table" id="auditTable">
            <thead>
                <tr>
                    <th>Date/Time</th>
                    <th>Staff Name</th>
                    <th>Action Taken</th>
                    <th>IP Address</th>
                </tr>
            </thead>
            <tbody>
                <!-- Row 1: Positive -->
                <tr>
                    <td>Oct 24, 2023 - 09:14 AM</td>
                    <td>Elena Rossi</td>
                    <td class="action-text action-positive">Confirmed Walk-in</td>
                    <td>192.168.1.45</td>
                </tr>

                <!-- Row 2: Negative -->
                <tr>
                    <td>Oct 24, 2023 - 10:30 AM</td>
                    <td>Marcus Thorne</td>
                    <td class="action-text action-negative">Deleted Booking #1042</td>
                    <td>10.0.0.212</td>
                </tr>

                <!-- Row 3: Neutral -->
                <tr>
                    <td>Oct 24, 2023 - 11:05 AM</td>
                    <td>Sarah Jenkins</td>
                    <td class="action-text action-neutral">Updated Rate Settings</td>
                    <td>192.168.1.88</td>
                </tr>

                <!-- Row 4: Positive -->
                <tr>
                    <td>Oct 24, 2023 - 01:22 PM</td>
                    <td>Elena Rossi</td>
                    <td class="action-text action-positive">Added Staff (Housekeeping)</td>
                    <td>192.168.1.45</td>
                </tr>

                <!-- Row 5: Negative -->
                <tr>
                    <td>Oct 24, 2023 - 03:45 PM</td>
                    <td>James Arlington</td>
                    <td class="action-text action-negative">Processed Refund ($450)</td>
                    <td>172.16.254.1</td>
                </tr>

                <!-- Row 6: Neutral -->
                <tr>
                    <td>Oct 24, 2023 - 04:10 PM</td>
                    <td>Sarah Jenkins</td>
                    <td class="action-text action-neutral">Exported Guest List</td>
                    <td>192.168.1.88</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>