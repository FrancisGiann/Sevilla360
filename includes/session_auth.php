<?php
declare(strict_types=1);

/**
 * Issue the next authentication version for an account.
 *
 * The row lock serializes concurrent sign-ins. Each caller receives the
 * version it wrote, so only the last committed session can pass the existing
 * session account guard.
 */
function session_auth_issue_version(mysqli $conn, int $userId, string $expectedRole): int
{
    if ($userId < 1 || !in_array($expectedRole, ['customer', 'staff', 'admin'], true)) {
        throw new InvalidArgumentException('Invalid account for session issuance.');
    }

    $transactionStarted = false;
    try {
        if (!$conn->begin_transaction()) {
            throw new RuntimeException('Unable to start session issuance transaction.');
        }
        $transactionStarted = true;

        $accountStmt = $conn->prepare(
            'SELECT u.role, u.status AS user_status, u.is_verified, s.status AS staff_status
             FROM users u
             LEFT JOIN staff s ON s.user_id = u.id
             WHERE u.id = ?
             LIMIT 1 FOR UPDATE'
        );
        if (!$accountStmt) throw new RuntimeException('Unable to lock account for session issuance.');
        $accountStmt->bind_param('i', $userId);
        if (!$accountStmt->execute()) throw new RuntimeException('Unable to validate account for session issuance.');
        $account = $accountStmt->get_result()->fetch_assoc();
        $accountStmt->close();

        if (!is_array($account)
            || ($account['role'] ?? null) !== $expectedRole
            || (int)($account['is_verified'] ?? 0) !== 1) {
            throw new RuntimeException('Account is not eligible for session issuance.');
        }

        $status = in_array($expectedRole, ['admin', 'staff'], true)
            ? ($account['staff_status'] ?? '')
            : ($account['user_status'] ?? '');
        if (!is_string($status) || strcasecmp($status, 'active') !== 0) {
            throw new RuntimeException('Account is not active for session issuance.');
        }

        $updateStmt = $conn->prepare('UPDATE users SET auth_version = auth_version + 1 WHERE id = ?');
        if (!$updateStmt) throw new RuntimeException('Unable to prepare session version update.');
        $updateStmt->bind_param('i', $userId);
        if (!$updateStmt->execute() || $updateStmt->affected_rows !== 1) {
            throw new RuntimeException('Unable to update session version.');
        }
        $updateStmt->close();

        $versionStmt = $conn->prepare('SELECT auth_version FROM users WHERE id = ? LIMIT 1');
        if (!$versionStmt) throw new RuntimeException('Unable to read session version.');
        $versionStmt->bind_param('i', $userId);
        if (!$versionStmt->execute()) throw new RuntimeException('Unable to read session version.');
        $versionRow = $versionStmt->get_result()->fetch_assoc();
        $versionStmt->close();
        if (!is_array($versionRow) || !isset($versionRow['auth_version'])) {
            throw new RuntimeException('Unable to read session version.');
        }

        $authVersion = filter_var($versionRow['auth_version'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($authVersion === false) throw new RuntimeException('Invalid session version returned.');
        if (!$conn->commit()) throw new RuntimeException('Unable to commit session issuance.');
        $transactionStarted = false;

        return $authVersion;
    } catch (Throwable $error) {
        if ($transactionStarted) {
            try { $conn->rollback(); } catch (Throwable $rollbackError) {}
        }
        throw $error;
    }
}
