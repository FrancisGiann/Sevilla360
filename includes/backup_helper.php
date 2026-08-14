<?php

/**
 * Pure-PHP Database Backup & Restore Utility for Sevilla360
 * Safe for environments where shell_exec() or mysqldump is disabled.
 */

class BackupHelper {
    
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
            $createTableRow = $createTableRes->fetch_row();
            $sqlScript .= $createTableRow[1] . ";\n\n";
            
            // Get data
            $dataRes = $conn->query("SELECT * FROM `$table`");
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
            mkdir($dir, 0755, true);
        }

        // Add a .htaccess to prevent web access if it doesn't exist
        $htaccessPath = $dir . '/.htaccess';
        if (!file_exists($htaccessPath)) {
            file_put_contents($htaccessPath, "Order Deny,Allow\nDeny from all");
        }

        // Generate HMAC Signature
        $appKey = $_ENV['APP_KEY'] ?? 'fallback_key_if_missing';
        $signature = hash_hmac('sha256', $sqlScript, $appKey);
        
        $finalOutput = "-- Signature: " . $signature . "\n" . $sqlScript;

        // Write to file
        return file_put_contents($filePath, $finalOutput) !== false;
    }

    /**
     * Imports a database from a .sql file
     */
    public static function importDatabase($conn, $filePath) {
        if (!file_exists($filePath)) {
            return false;
        }

        $sqlContent = file_get_contents($filePath);
        if (empty($sqlContent)) {
            return false;
        }

        // Verify Signature
        $lines = explode("\n", $sqlContent, 2);
        if (count($lines) < 2) return false;
        
        $firstLine = $lines[0];
        $restOfContent = $lines[1];
        
        if (strpos($firstLine, '-- Signature: ') !== 0) {
            return false; // No signature found
        }
        
        $providedSignature = trim(substr($firstLine, 14));
        $appKey = $_ENV['APP_KEY'] ?? 'fallback_key_if_missing';
        $expectedSignature = hash_hmac('sha256', $restOfContent, $appKey);
        
        if (!hash_equals($expectedSignature, $providedSignature)) {
            return false; // Signature mismatch (tampered file)
        }

        $conn->query("SET FOREIGN_KEY_CHECKS = 0;");
        
        // Execute multi_query on the original content without the signature line
        $success = $conn->multi_query($restOfContent);
        
        // Drain ALL results to prevent "Commands out of sync" errors
        // This must complete fully even if individual statements fail
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
            // Check for errors on this statement
            if ($conn->errno) {
                $success = false;
            }
        } while ($conn->more_results() && $conn->next_result());

        $conn->query("SET FOREIGN_KEY_CHECKS = 1;");
        return $success;
    }
}
?>
