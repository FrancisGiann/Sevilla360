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
$history_backend = staff_account_contract_source('actions/admin/get_customer_history.php');
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

foreach (['STF-', 'data-status', 'data-staff-filter="active"', 'data-staff-filter="archived"', 'data-staff-filter="all"', 'Account access', 'Archive', 'Restore', 'Promote to admin', 'promoteStaffModal', 'current administrator password', 'Staff Details', 'read-only', 'staff_display_id', 'staff_status', 'id="staffDetails" hidden', 'data-staff-detail', 'aria-controls="staffTable"', 'aria-controls="customerTable"', 'aria-labelledby="staffAccountsTab"', 'aria-labelledby="customerAccountsTab"'] as $marker) {
    staff_account_contract_assert(strpos($view, $marker) !== false, "view contains {$marker}");
}
foreach (['staff_phone', 'staff_address', 'staff_department', 'staff_job_title', 'staff_hire_date', 'Residential address', 'Admin-only staff record.', 'street-address', 'maxlength="20"', 'maxlength="100"', 'maxlength="255"'] as $marker) {
    staff_account_contract_assert(strpos($view, $marker) !== false, "modal contains {$marker}");
}
staff_account_contract_assert((bool)preg_match('/id=["\']staff_status["\'][^>]*readonly/i', $view), 'modal exposes lifecycle status as read-only');
staff_account_contract_assert((bool)preg_match('/id=["\']staff_display_id["\'][^>]*readonly/i', $view), 'modal exposes staff ID as read-only');
$details_start = strpos($view, '<dl class="um-form-grid um-staff-details" id="staffDetails" hidden>');
$details_end = $details_start === false ? false : strpos($view, '</dl>', $details_start);
$details_markup = $details_start === false || $details_end === false ? '' : substr($view, $details_start, $details_end - $details_start);
staff_account_contract_assert($details_markup !== ''
    && !preg_match('/<(?:input|textarea|select|button)\b/i', $details_markup)
    && strpos($view, '<form class="um-form" id="staffForm">') !== false
    && strpos($script, 'if (staffForm) staffForm.hidden = edit;') !== false
    && strpos($script, 'field.textContent = value?.trim() || "—";') !== false
    && strpos($script, 'staffDetailsActions?.querySelector(".close-staff-modal")') !== false,
    'read-only details use labeled text without controls while Add New Staff retains its editable form and correct focus');
staff_account_contract_assert(!preg_match('/<\?=\s*\$[a-df-zA-DF-Z_]/', $view), 'dynamic view output is escaped');

foreach (['archive', 'restore', 'promote', 'staffFilter', 'aria-pressed', 'replaceChildren', 'textContent', 'Escape', 'lastFocusedElement', 'modalFocusableElements', 'trapModalFocus', 'shiftKey', 'preventDefault', 'focusTarget', 'staff_address', 'address:', 'current_password', 'X-CSRF-Token'] as $marker) {
    staff_account_contract_assert(strpos($script, $marker) !== false, "script contains {$marker}");
}
staff_account_contract_assert(strpos($script, 'action: "promote"') !== false
    && strpos($script, 'user_id: document.getElementById("promoteStaffUserId").value') !== false
    && strpos($script, 'current_password: promotePassword.value') !== false, 'promotion submits the minimal JSON payload with the existing CSRF request helper');
staff_account_contract_assert(str_contains($view, "u.role IN ('admin', 'staff')")
    && str_contains($view, 'Admin &amp; Staff Accounts')
    && str_contains($view, 'LEFT JOIN staff'), 'the directory includes Admin and Staff identities, including missing work profiles');
staff_account_contract_assert(str_contains($view, "u.role IN ('admin', 'staff')")
    && str_contains($view, 'AND u.id <> ?')
    && str_contains($view, "bind_param('i', \$current_user_id)")
    && str_contains($view, 'FILTER_VALIDATE_INT')
    && !str_contains($view, 'data-current=')
    && !str_contains($view, 'Edit my profile')
    && !str_contains($view, 'aria-label="Current account">You'), 'staff directory excludes the validated current session user with a bound SQL value and no self-row markup');
staff_account_contract_assert(str_contains($script, 'isAdminDetails ? "Admin" : "Staff"')
    && str_contains($styles, '#staffModal [hidden] { display: none !important; }'), 'Admin details show the actual role and hidden write controls stay hidden');
staff_account_contract_assert(str_contains($backend, '$directoryTargetId === (int)($_SESSION[\'user_id\'] ?? 0)')
    && str_contains($backend, 'catch (StaffManagementException $error)'), 'all directory self-actions reject safely, including malformed target IDs');
staff_account_contract_assert(strpos($script, 'if (!focusableElements.length) return;') !== false
    && strpos($script, '!element.closest("[hidden]")') !== false, 'modal focus trap ignores hidden form descendants and is a no-op without visible controls');
staff_account_contract_assert(strpos($script, 'innerHTML') === false && strpos($script, 'insertAdjacentHTML') === false, 'script does not inject untrusted HTML');
staff_account_contract_assert(strpos($styles, '.um-form-grid') !== false
    && strpos($styles, 'grid-template-columns: repeat(2') !== false
    && strpos($styles, '.um-form-group-full') !== false
    && strpos($styles, '.um-address') !== false
    && strpos($styles, '@media (max-width: 768px)') !== false, 'modal has responsive two-to-one column layout');
staff_account_contract_assert(str_contains($history_backend, 'WHERE b.customer_id = ?')
    && str_contains($history_backend, '$stmt->bind_param("i", $customer_id)')
    && str_contains($history_backend, 'b.reference_no')
    && str_contains($history_backend, 'b.start_date, b.end_date')
    && str_contains($history_backend, 'b.amount_paid')
    && str_contains($history_backend, 'b.booking_status')
    && str_contains($history_backend, 'b.payment_status'), 'customer booking history returns all statuses and truthful booking amounts from a prepared customer-scoped query');
staff_account_contract_assert(str_contains($view, 'Booking status')
    && str_contains($view, 'Payment status')
    && str_contains($script, 'Number.isSafeInteger(bookingId) && bookingId > 0')
    && str_contains($script, 'admin_dashboard.php?page=bookings&booking_id=')
    && str_contains($script, 'Actual paid:')
    && str_contains($script, 'View details'), 'history rows show status and payment details with a validated link to the existing booking details view');
staff_account_contract_assert(str_contains($styles, '.um-history-modal')
    && str_contains($styles, 'max-width: 680px')
    && str_contains($styles, 'data-label'), 'booking history has responsive compact dialog rows');
staff_account_contract_assert(strpos($styles, 'linear-gradient') === false
    && strpos($styles, 'border-left') === false
    && strpos($styles, '.action-delete') === false, 'staff design avoids prohibited decoration/deletion affordance');
staff_account_contract_assert(strpos($styles, 'prefers-reduced-motion') !== false, 'reduced-motion preference is covered');

echo "Staff account contract checks passed\n";
