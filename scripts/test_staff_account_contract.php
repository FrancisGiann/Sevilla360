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

foreach (['address VARCHAR(255) NULL', 'department VARCHAR(100) NULL', 'job_title VARCHAR(100) NULL', 'hire_date DATE NULL', 'archived_at DATETIME NULL'] as $column) {
    staff_account_contract_assert(stripos($migration, "ADD COLUMN IF NOT EXISTS {$column}") !== false, "migration adds {$column}");
}

staff_account_contract_assert((bool)preg_match('/ALTER\s+TABLE\s+staff/i', $migration), 'migration targets staff');
staff_account_contract_assert(!preg_match('/\bDROP\s+COLUMN\b|\bDELETE\s+FROM\b/i', $migration), 'migration is non-destructive');
staff_account_contract_assert(strpos($backend, "['add', 'archive', 'restore']") !== false, 'backend allowlist contains staff lifecycle actions');
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

foreach (['STF-', 'data-status', 'data-staff-filter="active"', 'data-staff-filter="archived"', 'data-staff-filter="all"', 'Archive', 'Restore', 'Current account', 'Staff Details', 'read-only', 'staff_display_id', 'staff_status', 'aria-controls="staffTable"', 'aria-controls="customerTable"', 'aria-labelledby="staffAccountsTab"', 'aria-labelledby="customerAccountsTab"'] as $marker) {
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

foreach (['archive', 'restore', 'staffFilter', 'aria-pressed', 'replaceChildren', 'textContent', 'Escape', 'lastFocusedElement', 'modalFocusableElements', 'trapModalFocus', 'shiftKey', 'preventDefault', 'focusTarget', 'staff_address', 'address:'] as $marker) {
    staff_account_contract_assert(strpos($script, $marker) !== false, "script contains {$marker}");
}
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
