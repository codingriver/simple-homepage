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

$result = runtime_env_run_install_job($jobId, $type, $args);
exit(!empty($result['ok']) ? 0 : 1);
