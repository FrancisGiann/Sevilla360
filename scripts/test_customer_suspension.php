<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/customer_suspension.php';

final class FakeCustomerSuspensionResult
{
    public function __construct(private ?array $row) {}
    public function fetch_assoc(): ?array { $row = $this->row; $this->row = null; return $row; }
}

final class FakeCustomerSuspensionStatement
{
    private array $parameters = [];
    public int $affected_rows = 0;

    public function __construct(private FakeCustomerSuspensionDatabase $database, private string $sql) {}

    public function bind_param(string $types, &...$parameters): bool
    {
        if (strlen($types) !== count($parameters)) return false;
        foreach ($parameters as $index => &$parameter) $this->parameters[$index] =& $parameter;
        unset($parameter);
        return true;
    }

    public function execute(): bool
    {
        $values = [];
        foreach ($this->parameters as &$value) $values[] = $value;
        unset($value);
        if (str_starts_with($this->sql, 'SELECT u.role')) {
            $id = (int)($values[0] ?? 0);
            $user = $this->database->users[$id] ?? null;
            $this->database->selectedAccount = $user
                ? ['role' => $user['role'], 'user_status' => $user['status'], 'staff_status' => $user['staff_status'] ?? null]
                : null;
            return true;
        }
        if (str_starts_with($this->sql, 'SELECT u.status')) {
            $this->database->lastLookupSql = $this->sql;
            $id = (int)($values[0] ?? 0);
            $user = $this->database->users[$id] ?? null;
            $this->database->selectedCustomer = $user && $user['role'] === 'customer' && $user['has_customer_record']
                ? ['email' => $user['email'], 'first_name' => $user['first_name'], 'last_name' => $user['last_name'], 'status' => $user['status']]
                : null;
            return true;
        }
        if (str_starts_with($this->sql, 'UPDATE users SET status')) {
            $this->database->lastUpdateSql = $this->sql;
            [$status, $id] = $values;
            if (isset($this->database->users[$id])
                && $this->database->users[$id]['role'] === 'customer'
                && $this->database->users[$id]['has_customer_record']
                && $this->database->users[$id]['status'] !== $status) {
                $this->database->users[$id]['status'] = $status;
                $this->affected_rows = 1;
            }
            return true;
        }
        if (str_starts_with($this->sql, 'INSERT INTO audit_logs')) {
            if ($this->database->failAudit) return false;
            $this->database->auditRows[] = ['user_id' => $values[0], 'action' => $values[1], 'ip_address' => $values[2]];
            return true;
        }
        throw new RuntimeException('Unexpected SQL in customer suspension test.');
    }

    public function get_result(): FakeCustomerSuspensionResult
    {
        $row = str_starts_with($this->sql, 'SELECT u.role')
            ? $this->database->selectedAccount
            : $this->database->selectedCustomer;
        return new FakeCustomerSuspensionResult($row);
    }

    public function close(): void {}
}

final class FakeCustomerSuspensionDatabase
{
    public array $users = [];
    public array $auditRows = [];
    public ?array $selectedCustomer = null;
    public ?array $selectedAccount = null;
    public string $lastLookupSql = '';
    public string $lastUpdateSql = '';
    public bool $failAudit = false;
    public int $beginCount = 0;
    public int $commitCount = 0;
    public int $rollbackCount = 0;
    private ?array $snapshot = null;

    public function prepare(string $sql): FakeCustomerSuspensionStatement
    {
        return new FakeCustomerSuspensionStatement($this, $sql);
    }
    public function begin_transaction(): bool
    {
        $this->beginCount++;
        $this->snapshot = [$this->users, count($this->auditRows)];
        return true;
    }
    public function commit(): bool { $this->commitCount++; $this->snapshot = null; return true; }
    public function rollback(): bool
    {
        $this->rollbackCount++;
        if ($this->snapshot !== null) {
            [$this->users, $auditCount] = $this->snapshot;
            $this->auditRows = array_slice($this->auditRows, 0, $auditCount);
        }
        $this->snapshot = null;
        return true;
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$valid = customer_suspension_parse_request('POST', '{"user_id":"7","action":"suspended"}');
$assert($valid === ['success' => true, 'status' => 200, 'user_id' => 7, 'action' => 'suspended'], 'valid customer status request should parse to a positive integer and allowlisted action');
$assert(customer_suspension_parse_request('POST', '{"user_id":7,"action":"active"}')['success'], 'positive JSON integer IDs should be accepted');
$assert(customer_suspension_parse_request('GET', '{}')['status'] === 405, 'non-POST requests must be rejected');
$assert(customer_suspension_parse_request('POST', str_repeat('x', 4097))['status'] === 413, 'oversized JSON requests must be rejected');
$assert(customer_suspension_parse_request('POST', '{')['status'] === 400, 'malformed JSON must be rejected');
foreach ([
    '{"user_id":0,"action":"active"}',
    '{"user_id":-2,"action":"active"}',
    '{"user_id":1.5,"action":"active"}',
    '{"user_id":1.0,"action":"active"}',
    '{"user_id":true,"action":"active"}',
    '{"user_id":[],"action":"active"}',
    '{"user_id":"07","action":"active"}',
    '{"user_id":"7oops","action":"active"}',
    '{"user_id":7,"action":"suspend"}',
    '{"user_id":7,"action":[]}',
    '[]',
] as $invalidRequest) {
    $assert(customer_suspension_parse_request('POST', $invalidRequest)['status'] === 422 || customer_suspension_parse_request('POST', $invalidRequest)['status'] === 400, 'invalid ID, action, or JSON shape must be rejected');
}

$database = new FakeCustomerSuspensionDatabase();
$database->users = [
    7 => ['role' => 'customer', 'has_customer_record' => true, 'email' => 'customer@example.test', 'first_name' => 'Ana', 'last_name' => 'Reyes', 'status' => 'active'],
    8 => ['role' => 'staff', 'has_customer_record' => true, 'email' => 'staff@example.test', 'first_name' => 'Staff', 'last_name' => 'User', 'status' => 'active'],
    9 => ['role' => 'admin', 'has_customer_record' => false, 'email' => 'admin@example.test', 'first_name' => 'Admin', 'last_name' => 'User', 'status' => 'active', 'staff_status' => 'active'],
    10 => ['role' => 'customer', 'has_customer_record' => false, 'email' => 'orphan@example.test', 'first_name' => 'No', 'last_name' => 'Customer Row', 'status' => 'active'],
];
$assert(customer_suspension_admin_is_active($database, 9), 'an active administrator is revalidated against live role and staff status');
$database->users[9]['staff_status'] = 'archived';
$assert(!customer_suspension_admin_is_active($database, 9), 'stale administrator sessions must be rejected when live staff status is inactive');
$database->users[9]['staff_status'] = 'active';
$suspended = customer_suspension_change_status($database, 7, 'suspended', 9, '192.0.2.10');
$assert($suspended['success'] && !$suspended['duplicate'] && $database->users[7]['status'] === 'suspended', 'customer status should update once');
$assert(count($database->auditRows) === 1 && str_starts_with($database->auditRows[0]['action'], 'Suspended customer account (user ID: 7)'), 'a status change must write one concise target-identifying audit row in the same operation');
$assert(str_contains($database->lastLookupSql, 'INNER JOIN customers c') && str_contains($database->lastLookupSql, "u.role = 'customer'")
    && str_contains($database->lastUpdateSql, "role = 'customer'"), 'both locked selection and update must restrict targets to customer accounts');
$duplicate = customer_suspension_change_status($database, 7, 'suspended', 9, '192.0.2.10');
$assert($duplicate['success'] && $duplicate['duplicate'] && count($database->auditRows) === 1, 'repeating the same target status must be idempotent and avoid duplicate audits');
$reactivated = customer_suspension_change_status($database, 7, 'active', 9, '192.0.2.10');
$assert($reactivated['success'] && !$reactivated['duplicate'] && $database->users[7]['status'] === 'active' && count($database->auditRows) === 2, 'reactivating a suspended customer should also be audited');

foreach ([8, 9, 10, 99] as $ineligibleUserId) {
    $before = $database->users;
    $auditCount = count($database->auditRows);
    $result = customer_suspension_change_status($database, $ineligibleUserId, 'suspended', 9, '192.0.2.10');
    $assert(!$result['success'] && $result['status'] === 404, 'staff, admin, orphan, and unknown IDs must be indistinguishable as non-customer targets');
    $assert($database->users === $before && count($database->auditRows) === $auditCount, 'non-customer targets must not change users.status or write audit rows');
}

$rollbackDatabase = new FakeCustomerSuspensionDatabase();
$rollbackDatabase->users = $database->users;
$rollbackDatabase->failAudit = true;
try {
    customer_suspension_change_status($rollbackDatabase, 7, 'suspended', 9, '192.0.2.10');
    throw new RuntimeException('audit failure should fail the status transition');
} catch (RuntimeException $exception) {
    $assert($exception->getMessage() === 'Customer account status could not be updated.', 'database errors should be reduced to a generic operation failure');
}
$assert($rollbackDatabase->users[7]['status'] === 'active' && $rollbackDatabase->auditRows === [] && $rollbackDatabase->rollbackCount === 1, 'audit failure must roll back the account status update');

$endpoint = file_get_contents(dirname(__DIR__) . '/actions/admin/suspend_user.php');
$assert(is_string($endpoint) && str_contains($endpoint, "\$_SESSION['role'] ?? '') !== 'admin'")
    && str_contains($endpoint, "\$_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'")
    && str_contains($endpoint, 'hash_equals($sessionCsrf, $clientCsrf)')
    && str_contains($endpoint, "0, 4097")
    && str_contains($endpoint, 'customer_suspension_admin_is_active($conn, $actorUserId)'), 'endpoint must enforce admin, live account state, POST, CSRF, and bounded-body guards before changing customer status');

echo "Customer suspension checks passed.\n";
