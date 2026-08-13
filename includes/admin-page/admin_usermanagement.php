<?php
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo '<div class="unauthorized-access"><h3>Unauthorized Access</h3></div>'; exit;
}
require_once 'config/db_connect.php';

// Fetch Staff
$staff_query = $conn->query("
    SELECT s.user_id, s.full_name, s.status, u.email, u.role 
    FROM staff s JOIN users u ON s.user_id = u.id 
    ORDER BY u.role DESC, s.full_name ASC
");
$staff_list = $staff_query->fetch_all(MYSQLI_ASSOC);

// UPDATED: Fetch Customers + Status + Total Bookings
$cust_query = $conn->query("
    SELECT c.id, c.user_id, u.status, c.first_name, c.last_name, c.email, COUNT(b.id) as total_bookings 
    FROM customers c 
    LEFT JOIN users u ON c.user_id = u.id
    LEFT JOIN bookings b ON c.id = b.customer_id 
    GROUP BY c.id ORDER BY total_bookings DESC
");
$customer_list = $cust_query->fetch_all(MYSQLI_ASSOC);
?>

<div class="um-container">
    <div class="um-header">
        <div class="um-tabs">
            <button class="um-tab active" data-target="staffTable">Staff Accounts</button>
            <button class="um-tab" data-target="customerTable">Customer Accounts</button>
        </div>
        <div class="um-controls">
            <div class="um-search-wrapper">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <input type="text" id="umSearch" placeholder="Search accounts...">
            </div>
            <button class="btn btn-primary" id="openAddStaffBtn">+ Add New Staff</button>
        </div>
    </div>

    <div class="um-card">
        <!-- STAFF TABLE -->
        <table class="um-table active" id="staffTable">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email Address</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($staff_list as $s): ?>
                <tr>
                    <td><?php echo htmlspecialchars($s['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($s['email']); ?></td>
                    <td style="text-transform: capitalize;"><?php echo htmlspecialchars($s['role']); ?></td>
                    <td><span
                            class="um-pill <?php echo $s['status'] === 'active' ? 'pill-active' : 'pill-inactive'; ?>"><?php echo ucfirst($s['status']); ?></span>
                    </td>
                    <td class="um-actions">
                        <button class="action-edit btn-staff-modal" data-id="<?php echo $s['user_id']; ?>"
                            data-name="<?php echo htmlspecialchars($s['full_name']); ?>"
                            data-email="<?php echo htmlspecialchars($s['email']); ?>"
                            data-role="<?php echo $s['role']; ?>"
                            data-status="<?php echo $s['status']; ?>">Edit</button>

                        <?php if ($s['user_id'] != $_SESSION['user_id']): ?>
                        <button class="action-delete btn-delete-staff"
                            data-id="<?php echo $s['user_id']; ?>">Delete</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- CUSTOMER TABLE -->
        <div id="customerTable" class="um-table">

            <!-- NEW: Filter Pills for Customers -->
            <div class="filter-pills">
                <button class="filter-pill active cust-filter" data-filter="All">All Customers</button>
                <button class="filter-pill cust-filter" data-filter="Registered">Registered Accounts</button>
                <button class="filter-pill cust-filter" data-filter="Walk-in">Walk-in Guests</button>
            </div>

            <table style="width: 100%; text-align: left; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email Address</th>
                        <th>Total Bookings</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customer_list as $c): 
                        // Determine if Registered or Walk-in for the JS filter
                        $rowType = ($c['user_id'] !== null) ? 'Registered' : 'Walk-in';
                    ?>
                    <!-- Added data-type for JS filtering -->
                    <tr class="cust-row" data-type="<?php echo $rowType; ?>">
                        <td style="padding: 15px 10px; border-bottom: 1px solid #eee;">
                            <?php echo htmlspecialchars($c['first_name'] . ' ' . $c['last_name']); ?></td>
                        <td style="padding: 15px 10px; border-bottom: 1px solid #eee;">
                            <?php echo htmlspecialchars($c['email']); ?></td>
                        <td style="padding: 15px 10px; border-bottom: 1px solid #eee;">
                            <?php echo $c['total_bookings']; ?></td>
                        <td style="padding: 15px 10px; border-bottom: 1px solid #eee;">
                            <?php if ($c['user_id'] !== null): ?>
                            <span
                                class="um-pill <?php echo $c['status'] === 'active' ? 'pill-active' : 'pill-inactive'; ?>">
                                <?php echo ucfirst($c['status']); ?>
                            </span>
                            <?php else: ?>
                            <span class="um-pill" style="background:#e5e7eb; color:#374151;">Walk-in</span>
                            <?php endif; ?>
                        </td>
                        <td class="um-actions" style="padding: 15px 10px; border-bottom: 1px solid #eee;">
                            <button class="action-view btn-history-modal"
                                data-id="<?php echo $c['id']; ?>">History</button>

                            <?php if ($c['user_id'] !== null): ?>
                            <?php if ($c['status'] === 'active'): ?>
                            <button class="action-delete btn-suspend" data-id="<?php echo $c['user_id']; ?>"
                                data-action="suspended">Suspend</button>
                            <?php else: ?>
                            <button class="action-edit btn-suspend" style="background:#4ade80; color:#000;"
                                data-id="<?php echo $c['user_id']; ?>" data-action="active">Activate</button>
                            <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ================= MODALS ================= -->
<!-- Add/Edit Staff Modal -->
<div class="um-modal-overlay" id="staffModal">
    <div class="um-modal-content">
        <h3 class="um-modal-title" id="staffModalTitle">Staff Account</h3>
        <form class="um-form" id="staffForm">
            <input type="hidden" id="staff_user_id" value="">
            <div class="um-form-group">
                <label>Full Name</label>
                <input type="text" id="staff_name" placeholder="Enter full name" required>
            </div>
            <div class="um-form-group">
                <label>Email Address</label>
                <input type="email" id="staff_email" placeholder="Enter email address" required>
            </div>
            <div class="um-form-group">
                <label>Password</label>
                <input type="password" id="staff_password" placeholder="Enter password (leave blank to keep current)">
                <small id="pw_hint" style="color:#888; font-size:0.8rem; display:none;">Leave blank to keep existing
                    password.</small>
            </div>
            <div class="um-form-group" style="display: flex; gap: 15px;">
                <div style="flex: 1;">
                    <label>Assign Role</label>
                    <select id="staff_role" required style="width: 100%; padding: 10px;">
                        <option value="admin">Admin</option>
                        <option value="staff">Staff</option>
                    </select>
                </div>
                <div style="flex: 1;">
                    <label>Status</label>
                    <select id="staff_status" required style="width: 100%; padding: 10px;">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="um-modal-actions">
                <button type="button" class="btn btn-outline close-staff-modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSaveStaff">Save Account</button>
            </div>
        </form>
    </div>
</div>

<!-- Customer History Modal -->
<div class="um-modal-overlay" id="historyModal">
    <div class="um-modal-content um-modal-large">
        <h3 class="um-modal-title">Booking History</h3>
        <div class="um-history-table-wrapper">
            <table class="um-history-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Venue</th>
                        <th>Amount Spent</th>
                    </tr>
                </thead>
                <tbody id="historyTbody">
                    <tr>
                        <td colspan="3" style="text-align:center;">Loading...</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="um-modal-actions">
            <button type="button" class="btn btn-outline close-history-modal">Close</button>
        </div>
    </div>
</div>