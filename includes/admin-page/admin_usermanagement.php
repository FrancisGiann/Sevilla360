<?php
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo '<div class="unauthorized-access"><h3>Unauthorized Access</h3></div>';
    exit;
}
require_once 'config/db_connect.php';

$e = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

// Staff lifecycle is stored on staff.status. Customer suspension remains on users.status.
$staff_query = $conn->query("SELECT s.user_id, s.full_name, s.phone, s.address, s.department, s.job_title,
                                    s.hire_date, s.status, s.archived_at, u.email, u.role
                             FROM staff s
                             INNER JOIN users u ON s.user_id = u.id
                             WHERE u.role IN ('admin', 'staff')
                             ORDER BY (s.status = 'active') DESC, u.role DESC, s.full_name ASC");
$staff_list = $staff_query ? $staff_query->fetch_all(MYSQLI_ASSOC) : [];

// Fetch customers with status and total booking counts. This path intentionally
// remains independent from staff lifecycle fields.
$cust_query = $conn->query("SELECT c.id, c.user_id, u.status, c.first_name, c.last_name, c.email,
                                   COUNT(b.id) AS total_bookings
                            FROM customers c
                            LEFT JOIN users u ON c.user_id = u.id
                            LEFT JOIN bookings b ON c.id = b.customer_id
                            GROUP BY c.id
                            ORDER BY total_bookings DESC");
$customer_list = $cust_query ? $cust_query->fetch_all(MYSQLI_ASSOC) : [];
$today = date('Y-m-d');
?>

<div class="um-container">
    <div class="um-header">
        <div class="um-tabs" role="tablist" aria-label="Account type">
            <button type="button" class="um-tab active" id="staffAccountsTab" data-target="staffTable" role="tab" aria-selected="true" aria-controls="staffTable" tabindex="0">Staff Accounts</button>
            <button type="button" class="um-tab" id="customerAccountsTab" data-target="customerTable" role="tab" aria-selected="false" aria-controls="customerTable" tabindex="-1">Customer Accounts</button>
        </div>
        <div class="um-controls">
            <label class="sr-only" for="umSearch">Search accounts</label>
            <div class="um-search-wrapper">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <input type="search" id="umSearch" placeholder="Search accounts..." autocomplete="off">
            </div>
            <button type="button" class="btn btn-primary" id="openAddStaffBtn">+ Add New Staff</button>
        </div>
    </div>

    <div class="um-card">
        <div class="staff-filter-controls" id="staffFilterControls" role="group" aria-label="Staff account status">
            <span class="staff-filter-label">Show</span>
            <button type="button" class="staff-filter-btn active" data-staff-filter="active" aria-pressed="true">Active</button>
            <button type="button" class="staff-filter-btn" data-staff-filter="archived" aria-pressed="false">Archived</button>
            <button type="button" class="staff-filter-btn" data-staff-filter="all" aria-pressed="false">All</button>
        </div>
        <!-- STAFF TABLE -->
        <table class="um-table active" id="staffTable" role="tabpanel" aria-labelledby="staffAccountsTab" tabindex="0">
            <caption class="sr-only">Staff accounts</caption>
            <thead>
                <tr>
                    <th scope="col">Staff ID</th>
                    <th scope="col">Name</th>
                    <th scope="col">Work contact</th>
                    <th scope="col">Position</th>
                    <th scope="col">Access role</th>
                    <th scope="col">Hire date</th>
                    <th scope="col">Status</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($staff_list as $s):
                    $staff_id = (int)$s['user_id'];
                    $staff_status = (($s['status'] ?? '') === 'active') ? 'active' : 'archived';
                    $status_label = $staff_status === 'active' ? 'Active' : 'Archived';
                    $status_class = $staff_status === 'active' ? 'status-active' : 'status-archived';
                    $position_title = trim((string)($s['job_title'] ?? ''));
                    $department = trim((string)($s['department'] ?? ''));
                    $full_name = trim((string)($s['full_name'] ?? ''));
                    $email = trim((string)($s['email'] ?? ''));
                    $phone = trim((string)($s['phone'] ?? ''));
                    $address = trim((string)($s['address'] ?? ''));
                    $search_text = implode(' ', array_filter([$full_name, $email, $phone, $address, $position_title, $department, (string)$staff_id]));
                ?>
                <tr class="staff-row" data-status="<?= $e($staff_status) ?>" data-search="<?= $e($search_text) ?>">
                    <td data-label="Staff ID"><span class="um-staff-id">STF-<?= $e(str_pad((string)$staff_id, 5, '0', STR_PAD_LEFT)) ?></span></td>
                    <td data-label="Name">
                        <div class="um-primary-text"><?= $e($full_name) ?></div>
                    </td>
                    <td data-label="Work contact">
                        <div class="um-primary-text"><?= $e($email) ?></div>
                        <?php if ($phone !== ''): ?><div class="um-secondary-text"><?= $e($phone) ?></div><?php endif; ?>
                        <?php if ($address !== ''): ?><div class="um-secondary-text um-address"><?= $e($address) ?></div><?php endif; ?>
                    </td>
                    <td data-label="Position">
                        <?php if ($position_title !== ''): ?><div class="um-primary-text"><?= $e($position_title) ?></div><?php endif; ?>
                        <?php if ($department !== ''): ?><div class="um-secondary-text"><?= $e($department) ?></div><?php endif; ?>
                        <?php if ($position_title === '' && $department === ''): ?><span class="um-muted">—</span><?php endif; ?>
                    </td>
                    <td data-label="Access role"><span class="um-role-text"><?= $e(ucfirst((string)$s['role'])) ?></span></td>
                    <td data-label="Hire date"><span class="um-date-text"><?= $e($s['hire_date'] ?: '—') ?></span></td>
                    <td data-label="Status"><span class="um-status <?= $e($status_class) ?>"><?= $e($status_label) ?></span></td>
                    <td data-label="Actions" class="um-actions">
                        <button type="button" class="action-edit btn-staff-modal"
                            data-id="<?= $e($staff_id) ?>"
                            data-name="<?= $e($full_name) ?>"
                            data-email="<?= $e($email) ?>"
                            data-phone="<?= $e($phone) ?>"
                            data-address="<?= $e($address) ?>"
                            data-department="<?= $e($department) ?>"
                            data-job-title="<?= $e($position_title) ?>"
                            data-hire-date="<?= $e($s['hire_date'] ?? '') ?>"
                            data-role="<?= $e($s['role']) ?>">Edit</button>
                        <?php if ($staff_id === (int)($_SESSION['user_id'] ?? 0)): ?>
                            <span class="um-current-account">Current account</span>
                        <?php elseif ($staff_status === 'active'): ?>
                            <button type="button" class="action-archive btn-staff-lifecycle" data-id="<?= $e($staff_id) ?>" data-action="archive">Archive</button>
                        <?php else: ?>
                            <button type="button" class="action-restore btn-staff-lifecycle" data-id="<?= $e($staff_id) ?>" data-action="restore">Restore</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr class="um-empty-row" id="staffEmptyRow" hidden>
                    <td colspan="8">
                        <div class="um-empty-state">
                            <strong>No staff accounts match this view.</strong>
                            <span>Try another status filter or search term.</span>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- CUSTOMER TABLE -->
        <div id="customerTable" class="um-table" role="tabpanel" aria-labelledby="customerAccountsTab" tabindex="0" hidden>
            <div class="filter-pills" role="group" aria-label="Customer type">
                <button type="button" class="filter-pill active cust-filter" data-filter="All" aria-pressed="true">All Customers</button>
                <button type="button" class="filter-pill cust-filter" data-filter="Registered" aria-pressed="false">Registered Accounts</button>
                <button type="button" class="filter-pill cust-filter" data-filter="Walk-in" aria-pressed="false">Walk-in Guests</button>
            </div>
            <label class="customer-filter-select-label" for="customerFilterSelect">Customer type</label>
            <select class="customer-filter-select" id="customerFilterSelect">
                <option value="All">All Customers</option>
                <option value="Registered">Registered Accounts</option>
                <option value="Walk-in">Walk-in Guests</option>
            </select>

            <table class="um-customer-table">
                <caption class="sr-only">Customer accounts</caption>
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Email Address</th>
                        <th scope="col">Total Bookings</th>
                        <th scope="col">Status</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customer_list as $c):
                        $row_type = ($c['user_id'] !== null) ? 'Registered' : 'Walk-in';
                        $customer_name = trim((string)$c['first_name'] . ' ' . (string)$c['last_name']);
                        $customer_status = (string)($c['status'] ?? '');
                        $customer_status_class = $customer_status === 'active' ? 'status-active' : 'status-archived';
                    ?>
                    <tr class="cust-row" data-type="<?= $e($row_type) ?>" data-search="<?= $e($customer_name . ' ' . (string)$c['email']) ?>">
                        <td data-label="Name"><?= $e($customer_name) ?></td>
                        <td data-label="Email Address"><?= $e($c['email']) ?></td>
                        <td data-label="Total Bookings"><?= $e($c['total_bookings']) ?></td>
                        <td data-label="Status">
                            <?php if ($c['user_id'] !== null): ?>
                                <span class="um-status <?= $e($customer_status_class) ?>"><?= $e(ucfirst($customer_status)) ?></span>
                            <?php else: ?>
                                <span class="um-status status-walk-in">Walk-in</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Actions" class="um-actions">
                            <button type="button" class="action-view btn-history-modal" data-id="<?= $e($c['id']) ?>">History</button>
                            <?php if ($c['user_id'] !== null): ?>
                                <?php if ($customer_status === 'active'): ?>
                                    <button type="button" class="action-suspend btn-suspend" data-id="<?= $e($c['user_id']) ?>" data-action="suspended">Suspend</button>
                                <?php else: ?>
                                    <button type="button" class="action-activate btn-suspend" data-id="<?= $e($c['user_id']) ?>" data-action="active">Activate</button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="um-empty-row" id="customerEmptyRow" hidden>
                        <td colspan="5">
                            <div class="um-empty-state">
                                <strong>No customer accounts match this view.</strong>
                                <span>Try another customer type or search term.</span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Staff Modal -->
<div class="um-modal-overlay" id="staffModal" aria-hidden="true">
    <div class="um-modal-content um-staff-modal-content" role="dialog" aria-modal="true" aria-labelledby="staffModalTitle" aria-describedby="staffModalDescription">
        <h3 class="um-modal-title" id="staffModalTitle">Staff Account</h3>
        <p class="um-modal-description" id="staffModalDescription">Maintain the staff member’s profile and sign-in access.</p>
        <form class="um-form" id="staffForm">
            <input type="hidden" id="staff_user_id" value="">
            <div class="um-form-grid">
                <div class="um-form-group">
                    <label for="staff_name">Full name</label>
                    <input type="text" id="staff_name" name="name" placeholder="Enter full name" autocomplete="name" maxlength="150" required>
                </div>
                <div class="um-form-group">
                    <label for="staff_email">Work email</label>
                    <input type="email" id="staff_email" name="email" placeholder="name@example.com" autocomplete="email" inputmode="email" maxlength="254" required>
                </div>
                <div class="um-form-group">
                    <label for="staff_phone">Work phone <span class="um-label-optional">(optional)</span></label>
                    <input type="tel" id="staff_phone" name="phone" placeholder="+63 912 345 6789" autocomplete="tel" inputmode="tel" maxlength="20" aria-describedby="staff-phone-help">
                    <small class="field-help" id="staff-phone-help">Up to 20 characters: numbers, spaces, + ( ) . or -.</small>
                </div>
                <div class="um-form-group um-form-group-full">
                    <label for="staff_address">Residential address <span class="um-label-optional">(optional)</span></label>
                    <textarea id="staff_address" name="address" rows="3" autocomplete="street-address" maxlength="255" aria-describedby="staff-address-help"></textarea>
                    <small class="field-help" id="staff-address-help">Admin-only staff record.</small>
                </div>
                <div class="um-form-group">
                    <label for="staff_department">Department <span class="um-label-optional">(optional)</span></label>
                    <input type="text" id="staff_department" name="department" placeholder="e.g. Guest Services" autocomplete="organization-title" maxlength="100" list="staffDepartments">
                    <datalist id="staffDepartments">
                        <option value="Guest Services"></option>
                        <option value="Reservations"></option>
                        <option value="Events"></option>
                        <option value="Finance"></option>
                        <option value="Housekeeping"></option>
                        <option value="Maintenance"></option>
                        <option value="Operations"></option>
                    </datalist>
                </div>
                <div class="um-form-group">
                    <label for="staff_job_title">Job title <span class="um-label-optional">(optional)</span></label>
                    <input type="text" id="staff_job_title" name="job_title" placeholder="e.g. Events Coordinator" autocomplete="organization-title" maxlength="100">
                </div>
                <div class="um-form-group">
                    <label for="staff_hire_date">Hire date <span class="um-label-optional">(optional)</span></label>
                    <input type="date" id="staff_hire_date" name="hire_date" autocomplete="off" max="<?= $e($today) ?>" aria-describedby="staff-hire-date-help">
                    <small class="field-help" id="staff-hire-date-help">Use the staff member’s actual start date; future dates are not accepted.</small>
                </div>
                <div class="um-form-group">
                    <label for="staff_role">Access role</label>
                    <select id="staff_role" name="role" autocomplete="off" required>
                        <option value="admin">Admin</option>
                        <option value="staff">Staff</option>
                    </select>
                </div>
                <div class="um-form-group um-password-group">
                    <label for="staff_password">Password</label>
                    <input type="password" id="staff_password" name="password" autocomplete="new-password" aria-describedby="staff-password-help" placeholder="Enter a password">
                    <small class="field-help" id="staff-password-help">Use 8–72 characters with a capital letter, lowercase letter, number, and symbol.</small>
                    <small class="field-help" id="pw_hint" hidden>Leave blank to keep the existing password.</small>
                </div>
            </div>
            <div class="um-form-error" id="staffFormError" role="alert" hidden></div>
            <div class="um-modal-actions">
                <button type="button" class="btn btn-outline close-staff-modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSaveStaff">Save Account</button>
            </div>
        </form>
    </div>
</div>

<!-- Customer History Modal -->
<div class="um-modal-overlay" id="historyModal" aria-hidden="true">
    <div class="um-modal-content um-modal-large" role="dialog" aria-modal="true" aria-labelledby="historyModalTitle">
        <h3 class="um-modal-title" id="historyModalTitle">Booking History</h3>
        <div class="um-history-table-wrapper">
            <table class="um-history-table">
                <thead>
                    <tr><th scope="col">Date</th><th scope="col">Venue</th><th scope="col">Amount Spent</th></tr>
                </thead>
                <tbody id="historyTbody"></tbody>
            </table>
        </div>
        <div class="um-modal-actions">
            <button type="button" class="btn btn-outline close-history-modal">Close</button>
        </div>
    </div>
</div>
