<?php

/**
 * Pure-PHP Database Backup & Restore Utility for Sevilla360
 * Safe for environments where shell_exec() or mysqldump is disabled.
 */

class BackupHelper {

    public static function maxBackupBytes(): int {
        $value = (int)($_ENV['BACKUP_MAX_BYTES'] ?? getenv('BACKUP_MAX_BYTES') ?: 52428800);
        return max(1048576, $value);
    }

    /** Backups must never be signed with a predictable or implicit key. */
    public static function getSigningKey(): ?string {
        $appKey = trim((string)($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: ''));
        // Require a genuinely non-placeholder key while preserving support for
        // existing deployments using a 32+ character APP_KEY. The diversity
        // check also rejects obvious repeated-character placeholders.
        if (strlen($appKey) < 32 || count(array_unique(str_split($appKey))) < 16 || preg_match('/^(.)\1+$/', $appKey)) return null;
        return $appKey;
    }

    public static function createBackupFile($conn, $database, $backupDir, $prefix) {
        $timestamp = date('Y-m-d_H-i-s');
        $filename = $prefix . $timestamp . '.sql';
        $filePath = rtrim($backupDir, '/') . '/' . $filename;
        if (!self::exportDatabase($conn, $database, $filePath)) return false;
        $fileSize = filesize($filePath);
        if ($fileSize === false || $fileSize <= 0) return false;
        return ['filename' => $filename, 'path' => $filePath, 'file_size' => $fileSize];
    }

    public static function registerBackup($conn, $filename, $fileSize, $createdBy) {
        $stmt = $conn->prepare("INSERT INTO backups (filename, file_size, created_by) VALUES (?, ?, ?)");
        if (!$stmt) return false;
        $stmt->bind_param("sii", $filename, $fileSize, $createdBy);
        return $stmt->execute();
    }

    public static function cleanupNormalBackups($conn, $backupDir, $keep = 30) {
        $keep = max(1, (int)$keep);
        $result = $conn->query("SELECT id, filename FROM backups WHERE filename LIKE 'sevilla360_backup_%' OR filename LIKE 'sevilla360_auto_%' ORDER BY created_at DESC, id DESC");
        if (!$result) return 0;
        $deleted = 0;
        $index = 0;
        while ($row = $result->fetch_assoc()) {
            $index++;
            if ($index <= $keep) continue;
            $path = rtrim($backupDir, '/') . '/' . basename($row['filename']);
            if (file_exists($path) && !unlink($path)) continue;
            $stmt = $conn->prepare("DELETE FROM backups WHERE id = ?");
            $stmt->bind_param("i", $row['id']);
            if ($stmt->execute()) $deleted++;
        }
        return $deleted;
    }
    
    /**
     * Exports the database to a .sql file
     */
    public static function exportDatabase($conn, $database, $filePath) {
        $conn->query("SET NAMES 'utf8'");
        
        $sqlScript = "";
        $sqlScript .= "-- Sevilla360 Database Backup\n";
        $sqlScript .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
        $sqlScript .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

        // Get all tables
        $tables = [];
        $result = $conn->query("SHOW TABLES");
        if (!$result) return false;
        
        while ($row = $result->fetch_row()) {
            $tables[] = $row[0];
        }

        foreach ($tables as $table) {
            // Add DROP TABLE
            $sqlScript .= "DROP TABLE IF EXISTS `$table`;\n";
            
            // Get CREATE TABLE
            $createTableRes = $conn->query("SHOW CREATE TABLE `$table`");
            if (!$createTableRes) return false;
            $createTableRow = $createTableRes->fetch_row();
            if (!$createTableRow || !isset($createTableRow[1])) return false;
            $sqlScript .= $createTableRow[1] . ";\n\n";

            // Get data
            $dataRes = $conn->query("SELECT * FROM `$table`");
            if (!$dataRes) return false;
            $numFields = $dataRes->field_count;
            
            if ($dataRes->num_rows > 0) {
                $sqlScript .= "INSERT INTO `$table` VALUES\n";
                $rowCount = 0;
                while ($row = $dataRes->fetch_row()) {
                    $rowCount++;
                    $sqlScript .= "(";
                    for ($i = 0; $i < $numFields; $i++) {
                        if (isset($row[$i])) {
                            $escaped = $conn->real_escape_string($row[$i]);
                            $sqlScript .= "'" . $escaped . "'";
                        } else {
                            $sqlScript .= "NULL";
                        }
                        if ($i < ($numFields - 1)) {
                            $sqlScript .= ",";
                        }
                    }
                    if ($rowCount == $dataRes->num_rows) {
                        $sqlScript .= ");\n\n";
                    } else {
                        $sqlScript .= "),\n";
                    }
                }
            }
        }
        
        $sqlScript .= "SET FOREIGN_KEY_CHECKS = 1;\n";

        // Ensure the directory exists
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) return false;
        }

        // Add a .htaccess to prevent web access if it doesn't exist
        $htaccessPath = $dir . '/.htaccess';
        if (!file_exists($htaccessPath)) {
            file_put_contents($htaccessPath, "Order Deny,Allow\nDeny from all");
        }

        // Generate HMAC Signature. Missing/weak APP_KEY fails closed.
        $appKey = self::getSigningKey();
        if ($appKey === null) return false;
        $signature = hash_hmac('sha256', $sqlScript, $appKey);
        
        $finalOutput = "-- Signature: " . $signature . "\n" . $sqlScript;

        // Write to a private temporary file and atomically publish it. A
        // failed export can never leave a truncated backup at the final path.
        $tmpPath = $filePath . '.tmp.' . bin2hex(random_bytes(8));
        $written = file_put_contents($tmpPath, $finalOutput, LOCK_EX);
        if ($written === false || $written !== strlen($finalOutput)) {
            @unlink($tmpPath);
            return false;
        }
        if (!@rename($tmpPath, $filePath)) {
            @unlink($tmpPath);
            return false;
        }
        return true;
    }

    public static function validateSignedBackup(string $filePath): string {
        if (!is_file($filePath) || filesize($filePath) <= 0 || filesize($filePath) > self::maxBackupBytes()) throw new RuntimeException('Backup is missing, empty, or exceeds the configured size limit.');
        $sqlContent = file_get_contents($filePath);
        if ($sqlContent === false) throw new RuntimeException('Unable to read backup.');
        $lines = explode("\n", $sqlContent, 2);
        if (count($lines) < 2 || strpos($lines[0], '-- Signature: ') !== 0) throw new RuntimeException('Backup signature is missing.');
        $provided = trim(substr($lines[0], 14)); $rest = $lines[1];
        $key = self::getSigningKey();
        if ($key === null || !hash_equals(hash_hmac('sha256', $rest, $key), $provided)) throw new RuntimeException('Backup signature is invalid.');
        // An authenticated dump is still preflighted; refuse statements that
        // can escape the target schema or alter server-level state.
        if (preg_match('/(?:DROP|CREATE)\s+DATABASE|CREATE\s+USER|GRANT\s+|REVOKE\s+|LOAD\s+DATA|INTO\s+(?:OUT|DUMP)FILE|SET\s+GLOBAL|INSTALL\s+PLUGIN|UNINSTALL\s+PLUGIN|(?:^|;)\s*USE\s+/im', $rest)) throw new RuntimeException('Backup contains statements outside the application schema.');
        return $rest;
    }

    private static function importSqlContent($conn, string $sqlContent): bool {
        $previous = 1;
        $fk = $conn->query('SELECT @@FOREIGN_KEY_CHECKS AS enabled');
        if ($fk) { $row = $fk->fetch_assoc(); $previous = ((int)($row['enabled'] ?? 1) === 0) ? 0 : 1; }
        $success = false;
        try {
            if (!self::safeBackupQuery($conn, 'SET FOREIGN_KEY_CHECKS = 0')) return false;
            $success = $conn->multi_query($sqlContent);
            if ($result = $conn->store_result()) $result->free();
            if ($conn->errno) $success = false;
            while ($conn->more_results()) {
                if (!$conn->next_result()) $success = false;
                if ($result = $conn->store_result()) $result->free();
                if ($conn->errno) $success = false;
            }
            return $success;
        } finally {
            // DDL is not atomic, but session cleanup is always attempted.
            self::safeBackupQuery($conn, 'SET FOREIGN_KEY_CHECKS = ' . $previous);
        }
    }

    /**
     * Split the authenticated exporter format without treating semicolons in
     * escaped SQL strings as statement boundaries.
     */
    private static function splitBackupStatements(string $sql): array {
        $sql = preg_replace('/^\s*--[^\r\n]*(?:\r\n|\n|$)/m', '', $sql);
        if ($sql === null) throw new RuntimeException('Backup preflight could not parse comments.');
        $statements = [];
        $buffer = '';
        $quote = null;
        $escaped = false;
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            if ($quote !== null) {
                $buffer .= $char;
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
            } elseif ($char === ';') {
                $statement = trim($buffer);
                if ($statement !== '') $statements[] = $statement;
                $buffer = '';
            } else {
                $buffer .= $char;
            }
        }
        if ($quote !== null) throw new RuntimeException('Backup preflight found an unterminated quoted value.');
        $statement = trim($buffer);
        if ($statement !== '') $statements[] = $statement;
        if (!$statements) throw new RuntimeException('Backup preflight found no SQL statements.');
        return $statements;
    }

    private static function validateBackupIdentifier(string $identifier): void {
        if (!preg_match('/\A[A-Za-z0-9_$-]+\z/D', $identifier)) {
            throw new RuntimeException('Backup preflight rejected an unsupported identifier.');
        }
    }

    private static function quoteBackupIdentifier(string $identifier): string {
        self::validateBackupIdentifier($identifier);
        return '`' . $identifier . '`';
    }

    /** mysqli may be configured to throw on query errors; cleanup must continue. */
    private static function safeBackupQuery($conn, string $sql): bool {
        try { return $conn->query($sql) !== false; }
        catch (Throwable $e) { return false; }
    }

    /**
     * Preflight authenticated exporter output without CREATE DATABASE. Every
     * object is rewritten into uniquely prefixed temporary tables in the
     * current schema, and all possible prefixed objects are removed in finally.
     */
    private static function preflightPrefixedTables($conn, string $sql): bool {
        $operations = [];
        foreach (self::splitBackupStatements($sql) as $statement) {
            if (preg_match('/\ASET\s+FOREIGN_KEY_CHECKS\s*=\s*[01]\z/isD', $statement)) {
                $operations[] = ['type' => 'set', 'statement' => $statement];
            } elseif (preg_match('/\ADROP\s+TABLE\s+IF\s+EXISTS\s+`([^`]+)`\s*\z/isD', $statement, $match)) {
                self::validateBackupIdentifier($match[1]);
                $operations[] = ['type' => 'drop', 'name' => $match[1]];
            } elseif (preg_match('/\ACREATE\s+TABLE\s+`([^`]+)`\s+(.+)\z/isD', $statement, $match)) {
                self::validateBackupIdentifier($match[1]);
                if (trim($match[2]) === '') throw new RuntimeException('Backup preflight rejected an empty CREATE TABLE statement.');
                $operations[] = ['type' => 'create', 'name' => $match[1], 'body' => $match[2]];
            } elseif (preg_match('/\AINSERT\s+INTO\s+`([^`]+)`\s+VALUES\s+(.+)\z/isD', $statement, $match)) {
                self::validateBackupIdentifier($match[1]);
                if (trim($match[2]) === '') throw new RuntimeException('Backup preflight rejected an empty INSERT statement.');
                $operations[] = ['type' => 'insert', 'name' => $match[1], 'values' => $match[2]];
            } else {
                throw new RuntimeException('Backup preflight rejected an unsupported SQL statement.');
            }
        }

        $tables = [];
        $created = [];
        $dropped = [];
        foreach ($operations as $operation) {
            if ($operation['type'] === 'create') {
                if (isset($created[$operation['name']])) throw new RuntimeException('Backup preflight found a duplicate CREATE TABLE statement.');
                $created[$operation['name']] = true;
                $tables[] = $operation['name'];
            } elseif ($operation['type'] === 'drop') {
                $dropped[$operation['name']] = true;
            } elseif ($operation['type'] === 'insert' && !isset($created[$operation['name']])) {
                // The exporter emits CREATE before INSERT. Rejecting any other
                // order keeps this fallback narrowly scoped and predictable.
                throw new RuntimeException('Backup preflight found an INSERT before its CREATE TABLE.');
            }
        }
        if (!$tables || count($tables) !== count($dropped)) throw new RuntimeException('Backup preflight found an incomplete table dump.');
        foreach ($tables as $table) {
            if (!isset($dropped[$table])) throw new RuntimeException('Backup preflight found a table without its DROP statement.');
        }
        foreach ($dropped as $table => $_) {
            if (!isset($created[$table])) throw new RuntimeException('Backup preflight found a DROP for an unknown table.');
        }

        $prefix = '__svpf_' . bin2hex(random_bytes(7)) . '_';
        $temporaryTables = [];
        foreach ($tables as $table) {
            $temporary = $prefix . $table;
            if (strlen($temporary) > 64) throw new RuntimeException('Backup preflight table name is too long.');
            $temporaryTables[$table] = $temporary;
        }

        $existing = $conn->query('SHOW TABLES');
        if (!$existing) throw new RuntimeException('Backup preflight could not inspect temporary table names.');
        while ($row = $existing->fetch_row()) {
            $existingName = (string)($row[0] ?? '');
            if ($existingName !== '' && str_starts_with($existingName, $prefix)) {
                throw new RuntimeException('Backup preflight found a temporary table name collision.');
            }
        }

        $rewritten = [];
        $constraintNames = [];
        foreach ($operations as $operation) {
            if ($operation['type'] === 'set') {
                $rewritten[] = $operation['statement'];
                continue;
            }
            $name = $operation['name'];
            if (!isset($temporaryTables[$name])) throw new RuntimeException('Backup preflight found an unknown table target.');
            $temporaryName = $temporaryTables[$name];
            if ($operation['type'] === 'drop') {
                $rewritten[] = 'DROP TABLE IF EXISTS ' . self::quoteBackupIdentifier($temporaryName);
            } elseif ($operation['type'] === 'insert') {
                $rewritten[] = 'INSERT INTO ' . self::quoteBackupIdentifier($temporaryName) . " VALUES\n" . $operation['values'];
            } else {
                $body = preg_replace_callback('/\bREFERENCES\s+`([^`]+)`/i', function ($match) use ($temporaryTables) {
                    $referenced = $match[1];
                    self::validateBackupIdentifier($referenced);
                    if (!isset($temporaryTables[$referenced])) throw new RuntimeException('Backup preflight found a foreign key reference outside the dump.');
                    return 'REFERENCES ' . self::quoteBackupIdentifier($temporaryTables[$referenced]);
                }, $operation['body']);
                if ($body === null) throw new RuntimeException('Backup preflight could not rewrite foreign key references.');
                $body = preg_replace_callback('/\bCONSTRAINT\s+`([^`]+)`/i', function ($match) use ($prefix, &$constraintNames) {
                    self::validateBackupIdentifier($match[1]);
                    $constraint = $prefix . 'fk_' . $match[1];
                    if (strlen($constraint) > 64 || isset($constraintNames[$constraint])) throw new RuntimeException('Backup preflight found an invalid or duplicate foreign key name.');
                    $constraintNames[$constraint] = true;
                    return 'CONSTRAINT ' . self::quoteBackupIdentifier($constraint);
                }, $body);
                if ($body === null) throw new RuntimeException('Backup preflight could not rewrite foreign key names.');
                $rewritten[] = 'CREATE TABLE ' . self::quoteBackupIdentifier($temporaryName) . ' ' . trim($body);
            }
        }
        $rewrittenSql = implode(";\n", $rewritten) . ";\n";

        $previousFk = 1;
        $fk = $conn->query('SELECT @@FOREIGN_KEY_CHECKS AS enabled');
        if (!$fk) throw new RuntimeException('Backup preflight could not inspect FOREIGN_KEY_CHECKS.');
        $fkRow = $fk->fetch_assoc();
        $previousFk = ((int)($fkRow['enabled'] ?? 1) === 0) ? 0 : 1;
        try {
            if (!self::safeBackupQuery($conn, 'SET FOREIGN_KEY_CHECKS = 0')) throw new RuntimeException('Backup preflight could not disable foreign key checks.');
            if (!self::importSqlContent($conn, $rewrittenSql)) throw new RuntimeException('Restore preflight rejected the backup contents.');
            return true;
        } finally {
            $cleanupErrors = [];
            if (!self::safeBackupQuery($conn, 'SET FOREIGN_KEY_CHECKS = 0')) $cleanupErrors[] = 'disable foreign key checks';
            $cleanupNames = $temporaryTables;
            $leftovers = $conn->query('SHOW TABLES');
            if ($leftovers) {
                while ($row = $leftovers->fetch_row()) {
                    $leftover = (string)($row[0] ?? '');
                    if ($leftover !== '' && str_starts_with($leftover, $prefix)) $cleanupNames[$leftover] = $leftover;
                }
            } else {
                $cleanupErrors[] = 'enumerate temporary tables';
            }
            foreach (array_reverse(array_values($cleanupNames)) as $temporary) {
                if (!self::safeBackupQuery($conn, 'DROP TABLE IF EXISTS ' . self::quoteBackupIdentifier($temporary))) $cleanupErrors[] = 'drop temporary table';
            }
            if (!self::safeBackupQuery($conn, 'SET FOREIGN_KEY_CHECKS = ' . $previousFk)) $cleanupErrors[] = 'restore foreign key checks';
            if ($cleanupErrors) throw new RuntimeException('Restore preflight cleanup failed; production was not modified.');
        }
    }

    /** Import into an isolated temporary schema, failing closed on privilege errors. */
    public static function preflightImport($conn, string $filePath, string $database): bool {
        $sql = self::validateSignedBackup($filePath);
        $base = preg_replace('/[^A-Za-z0-9_]/', '_', $database);
        $schema = substr($base, 0, 42) . '_preflight_' . bin2hex(random_bytes(5));
        $current = $conn->query('SELECT DATABASE() AS db');
        if (!$current) throw new RuntimeException('Restore preflight could not identify the current database.');
        $currentRow = $current->fetch_assoc();
        $currentName = (string)($currentRow['db'] ?? '');
        if ($currentName === '') throw new RuntimeException('Restore preflight requires a selected application database.');

        // Least-privilege application users commonly cannot CREATE DATABASE.
        // In that case, use the strictly parsed/prefixed-table fallback in
        // the already-selected schema instead of touching production data.
        if (!self::safeBackupQuery($conn, "CREATE DATABASE `{$schema}`")) return self::preflightPrefixedTables($conn, $sql);

        $created = true; $ok = false;
        try {
            if (!$conn->select_db($schema)) throw new RuntimeException('Restore preflight could not select its isolated schema.');
            $ok = self::importSqlContent($conn, $sql);
            if (!$ok) throw new RuntimeException('Restore preflight rejected the backup contents.');
            return true;
        } finally {
            try { $restored = $conn->select_db($currentName); }
            catch (Throwable $e) { $restored = false; }
            if (!$restored) $ok = false;
            if ($created && !self::safeBackupQuery($conn, "DROP DATABASE IF EXISTS `{$schema}`")) $ok = false;
            if (!$ok && $created) throw new RuntimeException('Restore preflight failed; production was not modified.');
        }
    }

    /**
     * Imports a database from a .sql file
     */
    public static function importDatabase($conn, $filePath) {
        try { return self::importSqlContent($conn, self::validateSignedBackup($filePath)); }
        catch (Throwable $e) { return false; }
    }
}
?>
