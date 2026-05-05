<?php
require __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST tələb olunur'], 405);
}

$jobId = $_POST['id'] ?? '';
if ($jobId === '') json_response(['ok' => false, 'error' => 'id yoxdur'], 400);

$jDir = job_dir($jobId);
$dDir = download_dir($jobId);

$status = read_status($jobId);
$pid = (int)($status['pid'] ?? 0);
if ($pid > 0 && pid_running($pid)) {
    kill_process_tree($pid);
    sleep(1);
}

rrmdir($jDir);
rrmdir($dDir);

json_response(['ok' => true]);

function rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (new DirectoryIterator($dir) as $f) {
        if ($f->isDot()) continue;
        $p = $f->getPathname();
        if ($f->isDir()) rrmdir($p);
        else @unlink($p);
    }
    @rmdir($dir);
}
