<?php
/**
 * PHPUnit bootstrap
 */

$tmpDir = sys_get_temp_dir() . '/nav-phpunit-' . uniqid();
if (!is_dir($tmpDir)) {
    @mkdir($tmpDir, 0777, true);
    @mkdir($tmpDir . '/logs', 0777, true);
    @mkdir($tmpDir . '/backups', 0777, true);
    @mkdir($tmpDir . '/nginx', 0777, true);
}

// Only define DATA_DIR; other constants will be defined by the loaded libraries.
define('DATA_DIR', $tmpDir);

require_once __DIR__ . '/../../shared/auth.php';

require_once __DIR__ . '/../../admin/shared/functions.php';

register_shutdown_function(function () use ($tmpDir) {
    if (!is_dir($tmpDir)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
        $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
    }
    @rmdir($tmpDir);
});
