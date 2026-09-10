<?php
/** Static contract checks for staff account lifecycle and work-profile fields. */

function staff_account_contract_assert(bool $condition, string $message): void
{
    if (!$condition) exit("Staff account contract test failed: {$message}\n");
}

function staff_account_contract_source(string $path): string
{
    $source = file_get_contents(__DIR__ . '/../' . $path);
    staff_account_contract_assert($source !== false, "source is readable ({$path})");
    return $source;
}

$migration = staff_account_contract_source('migrations/017_staff_work_profile.sql');
$backend = staff_account_contract_source('actions/admin/manage_staff.php');
$view = staff_account_contract_source('includes/admin-page/admin_usermanagement.php');
$script = staff_account_contract_source('assets/js/admin-page/admin_usermanagement.js');
$styles = staff_account_contract_source('assets/css/admin-page/admin_usermanagement.css');
$admin_password_start = strpos($backend, 'function staff_management_current_admin_password');
$admin_audit_start = strpos($backend, 'function staff_management_audit', $admin_password_start === false ? 0 : $admin_password_start);
$admin_password_body = $admin_password_start === false || $admin_audit_start === false
    ? ''
    : substr($backend, $admin_password_start, $admin_audit_start - $admin_password_start);

foreach (['address VARCHAR(255) NULL', 'department VARCHAR(100) NULL', 'job_title VARCHAR(100) NULL', 'hire_date DATE NULL', 'archived_at DATETIME NULL'] as $column) {
    staff_account_contract_assert(stripos($migration, "ADD COLUMN IF NOT EXISTS {$column}") !== false, "migration adds {$column}");
}

staff_account_contract_assert((bool)preg_match('/ALTER\s+TABLE\s+staff/i', $migration), 'migration targets staff');
staff_account_contract_assert(!preg_match('/\bDROP\s+COLUMN\b|\bDELETE\s+FROM\b/i', $migration), 'migration is non-destructive');
staff_account_contract_assert(strpos($backend, "['add', 'archive', 'restore', 'promote']") !== false, 'backend allowlist contains staff lifecycle and promotion actions');
staff_account_contract_assert(strpos($backend, "\$_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'") !== false
    && strpos($backend, 'hash_equals(') !== false
    && strpos($backend, 'Invalid staff action.') !== false, 'promotion endpoint remains POST-only, CSRF-protected, and rejects direct invalid actions');
staff_account_contract_assert(strpos($backend, "action === 'promote'") !== false
    && strpos($backend, 'current_password') !== false
    && strpos($backend, 'password_verify') !== false
    && strpos($backend, 'staff_promotion_confirmation') !== false, 'promotion requires a rate-limited current password confirmation');
staff_account_contract_assert(strpos($backend, 'INNER JOIN staff s ON s.user_id = u.id') !== false
    && strpos($backend, "u.role = 'admin'") !== false
    && strpos($backend, "s.status = 'active'") !== false
    && $admin_password_body !== ''
    && strpos($admin_password_body, 'u.status') === false, 'acting administrator eligibility uses the active staff lifecycle record');
staff_account_contract_assert(strpos($backend, "UPDATE users SET role = 'admin'") !== false
    && strpos($backend, "target['status'] !== 'active'") !== false
    && strpos($backend, 'target user ID') !== false
    && strpos($backend, 'FOR UPDATE') !== false
    && strpos($backend, 'affected_rows !== 1') !== false, 'promotion is active-staff-only, role-only, locked, race-safe, and audits actor and target');
staff_account_contract_assert(strpos($backend, 'Only active staff accounts can be promoted.') !== false
    && strpos($backend, 'no longer eligible for promotion') !== false
    && strpos($backend, 'staff_management_audit') !== false
    && strpos($backend, 'demote') === false, 'archived, duplicate, race, and direct demotion attempts are rejected and successful promotions are audited');
staff_account_contract_assert(strpos($backend, "action === 'edit'") !== false && strpos($backend, 'read-only') !== false, 'direct staff edits are rejected');
staff_account_contract_assert(strpos($backend, "\$action === 'delete'") !== false, 'delete action is explicitly rejected');
staff_account_contract_assert(!preg_match('/\bDELETE\s+FROM\b/i', $backend), 'backend has no DELETE SQL');
staff_account_contract_assert(strpos($backend, "status = 'inactive', archived_at = NOW()") !== false, 'archive sets inactive status and timestamp');
staff_account_contract_assert(strpos($backend, "status = 'active', archived_at = NULL") !== false, 'restore clears archive timestamp');
staff_account_contract_assert(strpos($backend, 'begin_transaction()') !== false
    && strpos($backend, '->commit()') !== false
    && strpos($backend, '->rollback()') !== false, 'lifecycle writes are transactional');
staff_account_contract_assert(strpos($backend, 'hash_equals(') !== false, 'backend validates CSRF');
staff_account_contract_assert(strpos($backend, 'INNER JOIN staff') !== false
    && strpos($backend, "u.role = 'staff'") !== false, 'target must be an actual staff row and cannot be an administrator');
staff_account_contract_assert(strpos($backend, 'staff_management_active_admin_count') === false
    && strpos($backend, 'current account') !== false, 'administrator archive guard is retired and self-archive guard remains');
staff_account_contract_assert(strpos($backend, 'array_key_exists(\'role\', $data)') !== false
    && strpos($backend, 'Only staff accounts can be created') !== false, 'direct administrator-role creation is rejected');
staff_account_contract_assert(strpos($backend, 'password_policy_validate') !== false, 'password policy remains enforced');
foreach (['address', 'department', 'job_title', 'hire_date', 'phone'] as $field) {
    staff_account_contract_assert(strpos($backend, $field) !== false, "backend handles {$field}");
}

foreach (['STF-', 'data-status', 'data-staff-filter="active"', 'data-staff-filter="archived"', 'data-staff-filter="all"', 'Account access', 'Archive', 'Restore', 'Promote to admin', 'promoteStaffModal', 'current administrator password', 'Current account', 'Staff Details', 'read-only', 'staff_display_id', 'staff_status', 'aria-controls="staffTable"', 'aria-controls="customerTable"', 'aria-labelledby="staffAccountsTab"', 'aria-labelledby="customerAccountsTab"'] as $marker) {
    staff_account_contract_assert(strpos($view, $marker) !== false, "view contains {$marker}");
}
foreach (['staff_phone', 'staff_address', 'staff_department', 'staff_job_title', 'staff_hire_date', 'Residential address', 'Admin-only staff record.', 'street-address', 'maxlength="20"', 'maxlength="100"', 'maxlength="255"'] as $marker) {
    staff_account_contract_assert(strpos($view, $marker) !== false, "modal contains {$marker}");
}
staff_account_contract_assert((bool)preg_match('/id=["\']staff_status["\'][^>]*readonly/i', $view), 'modal exposes lifecycle status as read-only');
staff_account_contract_assert((bool)preg_match('/id=["\']staff_display_id["\'][^>]*readonly/i', $view), 'modal exposes staff ID as read-only');
staff_account_contract_assert(strpos($script, 'field.readOnly = edit') !== false
    && strpos($script, 'edit ? "Close" : "Cancel"') !== false, 'details fields stay readable and modal action label follows mode');
staff_account_contract_assert(!preg_match('/<\?=\s*\$[a-df-zA-DF-Z_]/', $view), 'dynamic view output is escaped');

foreach (['archive', 'restore', 'promote', 'staffFilter', 'aria-pressed', 'replaceChildren', 'textContent', 'Escape', 'lastFocusedElement', 'modalFocusableElements', 'trapModalFocus', 'shiftKey', 'preventDefault', 'focusTarget', 'staff_address', 'address:', 'current_password', 'X-CSRF-Token'] as $marker) {
    staff_account_contract_assert(strpos($script, $marker) !== false, "script contains {$marker}");
}
staff_account_contract_assert(strpos($script, 'action: "promote"') !== false
    && strpos($script, 'user_id: document.getElementById("promoteStaffUserId").value') !== false
    && strpos($script, 'current_password: promotePassword.value') !== false, 'promotion submits the minimal JSON payload with the existing CSRF request helper');
staff_account_contract_assert(strpos($script, 'if (!focusableElements.length) return;') !== false, 'modal focus trap is a no-op without focusable controls');
staff_account_contract_assert(strpos($script, 'innerHTML') === false && strpos($script, 'insertAdjacentHTML') === false, 'script does not inject untrusted HTML');
staff_account_contract_assert(strpos($styles, '.um-form-grid') !== false
    && strpos($styles, 'grid-template-columns: repeat(2') !== false
    && strpos($styles, '.um-form-group-full') !== false
    && strpos($styles, '.um-address') !== false
    && strpos($styles, '@media (max-width: 768px)') !== false, 'modal has responsive two-to-one column layout');
staff_account_contract_assert(strpos($styles, 'linear-gradient') === false
    && strpos($styles, 'border-left') === false
    && strpos($styles, '.action-delete') === false, 'staff design avoids prohibited decoration/deletion affordance');
staff_account_contract_assert(strpos($styles, 'prefers-reduced-motion') !== false, 'reduced-motion preference is covered');

echo "Staff account contract checks passed\n";
