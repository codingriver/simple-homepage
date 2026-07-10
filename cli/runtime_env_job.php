#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/shared/runtime_env_lib.php';

$jobId = (string)($argv[1] ?? '');
$type = (string)($argv[2] ?? '');
$args = array_slice($argv, 3);

if ($jobId === '' || $type === '') {
    fwrite(STDERR, "usage: runtime_env_job.php <job_id> <apk|version> [args...]\n");
    exit(1);
}

$jobId = runtime_env_clean_job_id($jobId);
runtime_env_job_update($jobId, [
    'status' => 'running',
    'pid' => getmypid(),
    'started_at' => date('Y-m-d H:i:s'),
]);

register_shutdown_function(static function () use ($jobId): void {
    $job = runtime_env_job_read($jobId);
    if ($job === null || !in_array((string)($job['status'] ?? ''), ['queued', 'running'], true)) {
        return;
    }
    $error = error_get_last();
    $message = '后台安装进程意外结束，安装未完成';
    $extra = ['percent' => (int)($job['percent'] ?? 0)];
    if (is_array($error) && !empty($error['message'])) {
        $extra['stderr'] = (string)$error['message'];
    }
    runtime_env_job_finish($jobId, false, $message, $extra);
});

$result = runtime_env_run_install_job($jobId, $type, $args);
exit(!empty($result['ok']) ? 0 : 1);
