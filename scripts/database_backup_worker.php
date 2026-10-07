<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../includes/database_backup.php';
date_default_timezone_set('Asia/Manila');

function database_backup_worker_lock()
{
    $directory = database_backup_directory(true);
    $path = $directory . DIRECTORY_SEPARATOR . 'worker.lock';
    if (is_link($path)) throw new RuntimeException('Database backup worker lock is invalid.');
    $stream = fopen($path, 'c+b');
    if ($stream === false) throw new RuntimeException('Unable to open the database backup worker lock.');
    @chmod($path, 0600);
    if (!flock($stream, LOCK_EX | LOCK_NB)) {
        fclose($stream);
        return false;
    }
    return $stream;
}

function database_backup_worker_write_daily_marker(string $path, string $archiveId): void
{
    $temp = tempnam(database_backup_directory(true), '.daily-');
    if ($temp === false) throw new RuntimeException('Unable to record the scheduled snapshot date.');
    @chmod($temp, 0600);
    try {
        file_put_contents($temp, json_encode(['archive_id' => $archiveId, 'completed_at' => gmdate('Y-m-d\TH:i:s\Z')], JSON_THROW_ON_ERROR), LOCK_EX);
        @chmod($temp, 0600);
        if (!rename($temp, $path)) throw new RuntimeException('Unable to record the scheduled snapshot date.');
    } finally {
        if (is_file($temp)) @unlink($temp);
    }
}

function database_backup_worker_scheduled_snapshot(): bool
{
    if (database_backup_maintenance_state() !== null) return false;
    $hourInput = database_backup_env('BACKUP_DAILY_HOUR', '3');
    $hour = ctype_digit($hourInput) ? min(23, (int)$hourInput) : 3;
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    if ((int)$now->format('G') < $hour) return false;
    $date = $now->format('Ymd');
    $directory = database_backup_directory(true);
    $marker = $directory . DIRECTORY_SEPARATOR . 'scheduled-' . $date . '.done';
    if (is_link($marker)) throw new RuntimeException('Scheduled snapshot marker is invalid.');
    if (is_file($marker)) return false;
    $created = database_backup_run_dump('scheduled');
    try {
        $connection = database_backup_connect_production();
        database_backup_audit($connection, null, 'database.backup_created', 'Created scheduled database backup', ['archive_id' => $created['id'], 'kind' => 'scheduled', 'status' => 'succeeded']);
        $connection->close();
    } catch (Throwable $error) {
        error_log('Scheduled database backup audit could not be written.');
    }
    database_backup_worker_write_daily_marker($marker, $created['id']);
    database_backup_apply_retention();
    fwrite(STDOUT, 'Created scheduled backup ' . $created['id'] . ".\n");
    return true;
}

function database_backup_worker_recover(string $archiveId): void
{
    $capabilities = database_backup_capabilities();
    if (database_backup_app_key() === null || !$capabilities['mysql'] || !$capabilities['process_execution']) throw new RuntimeException('Recovery requires APP_KEY, mysql, and PHP process execution.');
    $archivePath = database_backup_archive_path($archiveId, true);
    $jobId = 'job-' . bin2hex(random_bytes(16)) . '.json';
    $job = [
        'id' => $jobId,
        'action' => 'recovery',
        'actor_id' => 0,
        'archive_id' => $archiveId,
        'status' => 'running',
        'phase' => 'Recovery preflight',
        'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'completed_at' => null,
        'safety_archive_id' => null,
        'message' => 'CLI operator recovery.',
    ];
    database_backup_write_job($job);
    $operationLock = database_backup_lock(true, true);
    $gateWritten = false;
    $recoveryVerified = false;
    try {
        // Emergency recovery must remain available when the staging database
        // itself is the failed component. Authenticate and fully decompress
        // the signed archive before opening the production maintenance window.
        $verified = database_backup_validate_archive($archivePath);
        database_backup_remove_validation_temp($verified);
        database_backup_write_maintenance($jobId, 'CLI recovery in progress');
        $gateWritten = true;
        database_backup_job_update($job, 'running', 'Restoring selected safety archive', 'Restoring and verifying the selected archive.');
        $production = database_backup_connection_config('DB_');
        database_backup_clear_database_objects($production);
        $metadata = database_backup_validate_archive($archivePath);
        try {
            database_backup_import_payload(database_backup_extract_payload($metadata), $production, $production['name']);
        } finally {
            database_backup_remove_validation_temp($metadata);
        }
        database_backup_verify_database($production);
        $recoveryVerified = true;
        database_backup_job_update($job, 'succeeded', 'Recovery verified', 'Production recovery passed verification; clearing the maintenance gate.');
        database_backup_clear_maintenance($jobId);
        $gateWritten = false;
        fwrite(STDOUT, 'Recovery completed and maintenance gate cleared.\n');
    } catch (Throwable $error) {
        if ($gateWritten) {
            try {
                $maintenance = database_backup_maintenance_state();
                if ($maintenance === null) database_backup_write_maintenance($jobId, $recoveryVerified ? 'Recovery verified; maintenance marker cleanup failed' : 'CLI recovery failed; manual recovery required');
                elseif (($maintenance['job_id'] ?? null) === $jobId) database_backup_write_maintenance($jobId, $recoveryVerified ? 'Recovery verified; maintenance marker cleanup failed' : 'CLI recovery failed; manual recovery required');
            } catch (Throwable $markerError) {
                error_log('CLI recovery maintenance state could not be refreshed.');
            }
        }
        try {
            if ($recoveryVerified) {
                database_backup_job_update($job, 'succeeded_maintenance', 'Recovery verified; writes paused', 'Production recovery is verified, but the maintenance marker could not be cleared safely. Inspect private storage before reopening writes.', ['recovery_error_type' => get_class($error)]);
            } elseif ($gateWritten) {
                database_backup_job_update($job, 'recovery_failed', 'Manual recovery required', 'CLI recovery failed; the maintenance gate remains active.', ['recovery_error_type' => get_class($error)]);
            } else {
                database_backup_job_update($job, 'failed', 'Recovery not applied', 'The selected archive could not be validated; production was not changed.', ['recovery_error_type' => get_class($error)]);
            }
        } catch (Throwable $statusError) {
            error_log('CLI recovery job status could not be updated.');
        }
        throw $error;
    } finally {
        database_backup_release_lock($operationLock);
    }
}

$recoverIndex = array_search('--recover', $argv, true);
if ($recoverIndex !== false) {
    $archiveId = $argv[$recoverIndex + 1] ?? '';
    if (!is_string($archiveId) || !preg_match('/\Adb-[a-f0-9]{32}\.s360db\z/D', $archiveId)) {
        fwrite(STDERR, "Usage: php scripts/database_backup_worker.php --recover db-<32-hex>.s360db\n");
        exit(2);
    }
    try {
        $workerLock = database_backup_worker_lock();
        if ($workerLock === false) throw new RuntimeException('Another database backup worker is running.');
        try { database_backup_worker_recover($archiveId); }
        finally { flock($workerLock, LOCK_UN); fclose($workerLock); }
    } catch (Throwable $error) {
        error_log('Database recovery failed: ' . get_class($error));
        fwrite(STDERR, "Database recovery failed. The private job status and server log contain the result.\n");
        exit(1);
    }
    exit(0);
}

$capabilities = database_backup_capabilities();
if (!$capabilities['enabled']) {
    fwrite(STDERR, 'Database backup worker is disabled: ' . implode(' ', $capabilities['messages']) . "\n");
    if ($capabilities['restore_message']) fwrite(STDERR, 'Restore disabled: ' . $capabilities['restore_message'] . "\n");
    exit(1);
}

try {
    $workerLock = database_backup_worker_lock();
    if ($workerLock === false) {
        fwrite(STDOUT, "Another database backup worker is already running.\n");
        exit(0);
    }
    $completed = 0;
    try {
        foreach (database_backup_list_jobs(100) as $job) {
            if (database_backup_maintenance_state() !== null) {
                fwrite(STDOUT, "Database maintenance is active; queued jobs remain untouched until CLI recovery clears the gate.\n");
                break;
            }
            if (($job['status'] ?? '') !== 'queued') continue;
            database_backup_process_job((string)$job['id']);
            $completed++;
            if ($completed >= 5) break;
        }
        if (database_backup_maintenance_state() === null) database_backup_worker_scheduled_snapshot();
        $removed = database_backup_apply_retention();
        fwrite(STDOUT, 'Processed ' . $completed . ' queued backup job(s); removed ' . $removed . " expired archive(s).\n");
    } finally {
        flock($workerLock, LOCK_UN);
        fclose($workerLock);
    }
} catch (Throwable $error) {
    error_log('Database backup worker failed: ' . get_class($error));
    fwrite(STDERR, "Database backup worker failed. Check backup status and the server log.\n");
    exit(1);
}
