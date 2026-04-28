#!/usr/bin/env php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/shared/cron_lib.php';
$id = $argv[1] ?? '';
cron_run_task_by_id($id);
