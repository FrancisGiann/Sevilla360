<?php
declare(strict_types=1);

function customer_suspension_positive_id(mixed $value): ?int
{
    if (is_int($value)) return $value > 0 ? $value : null;
    if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) return null;
    $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $validated === false ? null : (int)$validated;
}

/** Validate the bounded JSON request used by the customer-account status action. */
function customer_suspension_parse_request(string $method, string $rawBody): array
{
    if ($method !== 'POST') return ['success' => false, 'status' => 405, 'message' => 'Use POST to update a customer account.'];
    if (strlen($rawBody) > 4096) return ['success' => false, 'status' => 413, 'message' => 'Request is too large.'];

    try {
        $request = json_decode($rawBody, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return ['success' => false, 'status' => 400, 'message' => 'Invalid JSON request.'];
    }
    if (!is_array($request)) return ['success' => false, 'status' => 400, 'message' => 'Invalid JSON request.'];

    $userId = customer_suspension_positive_id($request['user_id'] ?? null);
    $action = $request['action'] ?? null;
    if ($userId === null || !is_string($action) || !in_array($action, ['active', 'suspended'], true)) {
        return ['success' => false, 'status' => 422, 'message' => 'Choose a valid customer account action.'];
    }

    return ['success' => true, 'status' => 200, 'user_id' => $userId, 'action' => $action];
}

/** Match auth_guard.php's live account revalidation for the signed-in administrator. */
function customer_suspension_admin_is_active(object $conn, int $adminUserId): bool
{
    if ($adminUserId < 1) return false;
    $statement = $conn->prepare("SELECT u.role, u.status AS user_status, s.status AS staff_status
        FROM users u LEFT JOIN staff s ON s.user_id = u.id WHERE u.id = ? LIMIT 1");
    if (!$statement) throw new RuntimeException('Unable to verify administrator account.');
    $statement->bind_param('i', $adminUserId);
    if (!$statement->execute()) throw new RuntimeException('Unable to verify administrator account.');
    $result = $statement->get_result();
    if (!$result) throw new RuntimeException('Unable to read administrator account.');
    $account = $result->fetch_assoc();
    if (method_exists($statement, 'close')) $statement->close();
    if (!$account || ($account['role'] ?? null) !== 'admin') return false;

    $status = in_array($account['role'], ['admin', 'staff'], true)
        ? ($account['staff_status'] ?? '')
        : ($account['user_status'] ?? '');
    return strcasecmp((string)$status, 'active') === 0;
}

/** Change a customer user's status and its audit entry atomically. */
function customer_suspension_change_status(object $conn, int $targetUserId, string $newStatus, int $actorUserId, string $ipAddress): array
{
    if ($targetUserId < 1 || $actorUserId < 1 || !in_array($newStatus, ['active', 'suspended'], true)) {
        throw new InvalidArgumentException('Invalid customer status transition.');
    }

    $transactionStarted = false;
    try {
        if (!$conn->begin_transaction()) throw new RuntimeException('Unable to start customer status transaction.');
        $transactionStarted = true;

        $lookup = $conn->prepare("SELECT u.status
            FROM users u INNER JOIN customers c ON c.user_id = u.id
            WHERE u.id = ? AND u.role = 'customer' LIMIT 1 FOR UPDATE");
        if (!$lookup) throw new RuntimeException('Unable to lock customer account.');
        $lookup->bind_param('i', $targetUserId);
        if (!$lookup->execute()) throw new RuntimeException('Unable to lock customer account.');
        $lookupResult = $lookup->get_result();
        if (!$lookupResult) throw new RuntimeException('Unable to read customer account.');
        $customer = $lookupResult->fetch_assoc();
        if (method_exists($lookup, 'close')) $lookup->close();

        if (!$customer) {
            $conn->rollback();
            $transactionStarted = false;
            return ['success' => false, 'status' => 404, 'message' => 'Customer account not found.'];
        }

        if ((string)$customer['status'] === $newStatus) {
            if (!$conn->commit()) throw new RuntimeException('Unable to finish customer status transaction.');
            $transactionStarted = false;
            return ['success' => true, 'status' => 200, 'duplicate' => true, 'message' => 'Account is already ' . $newStatus . '.'];
        }

        $update = $conn->prepare("UPDATE users SET status = ? WHERE id = ? AND role = 'customer'");
        if (!$update) throw new RuntimeException('Unable to update customer account.');
        $update->bind_param('si', $newStatus, $targetUserId);
        if (!$update->execute() || (int)$update->affected_rows !== 1) throw new RuntimeException('Customer account status was not updated.');
        if (method_exists($update, 'close')) $update->close();

        $actionWord = $newStatus === 'suspended' ? 'Suspended' : 'Re-activated';
        $auditAction = $actionWord . ' customer account (user ID: ' . $targetUserId . ')';
        $audit = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'User Management', ?, ?)");
        if (!$audit) throw new RuntimeException('Unable to record customer status audit.');
        $audit->bind_param('iss', $actorUserId, $auditAction, $ipAddress);
        if (!$audit->execute()) throw new RuntimeException('Unable to record customer status audit.');
        if (method_exists($audit, 'close')) $audit->close();

        if (!$conn->commit()) throw new RuntimeException('Unable to commit customer status update.');
        $transactionStarted = false;
        return ['success' => true, 'status' => 200, 'duplicate' => false, 'message' => 'Account successfully ' . $newStatus . '!'];
    } catch (Throwable $exception) {
        if ($transactionStarted) {
            try { $conn->rollback(); } catch (Throwable) {}
        }
        throw new RuntimeException('Customer account status could not be updated.', 0, $exception);
    }
}
