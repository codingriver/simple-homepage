#!/usr/bin/env php
<?php
declare(strict_types=1);

$jobIdArg = (string)($argv[1] ?? '');
$dataDirArg = trim((string)($argv[2] ?? ''));
if (!defined('DATA_DIR') && $dataDirArg !== '' && is_dir($dataDirArg)) {
    define('DATA_DIR', rtrim($dataDirArg, '/\\'));
}

require_once __DIR__ . '/../admin/shared/functions.php';
require_once __DIR__ . '/../admin/shared/backup_webdav_lib.php';

$jobId = backup_webdav_clean_job_id($jobIdArg);
if ($jobId === '') {
    fwrite(STDERR, "usage: backup_webdav_job.php <job_id> [data_dir]\n");
    exit(1);
}

backup_webdav_job_update($jobId, [
    'status' => 'running',
    'pid' => getmypid(),
    'started_at' => date('Y-m-d H:i:s'),
]);

register_shutdown_function(static function () use ($jobId): void {
    $job = backup_webdav_job_read($jobId);
    if ($job === null || !in_array((string)($job['status'] ?? ''), ['queued', 'running'], true)) {
        return;
    }
    $error = error_get_last();
    $message = 'WebDAV 后台任务意外结束';
    if (is_array($error) && !empty($error['message'])) {
        $message .= '：' . (string)$error['message'];
    }
    backup_webdav_job_finish($jobId, false, $message);
});

$result = backup_webdav_run_job($jobId);
exit(($result['status'] ?? '') === 'success' ? 0 : 1);
