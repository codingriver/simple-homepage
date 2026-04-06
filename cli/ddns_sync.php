#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/shared/ddns_lib.php';

$id = $argv[1] ?? '';
if ($id !== '') {
    $result = ddns_run_task_by_id($id);
    fwrite($result['ok'] ? STDOUT : STDERR, ($result['msg'] ?? '') . PHP_EOL);
    exit($result['ok'] ? 0 : 1);
}

$tasks = ddns_due_tasks();
$failed = false;
foreach ($tasks as $task) {
    $result = ddns_run_task($task);
    $stream = $result['ok'] ? STDOUT : STDERR;
    fwrite($stream, '[' . ($task['id'] ?? '') . '] ' . ($result['msg'] ?? '') . PHP_EOL);
    if (!$result['ok']) {
        $failed = true;
    }
}
exit($failed ? 1 : 0);
