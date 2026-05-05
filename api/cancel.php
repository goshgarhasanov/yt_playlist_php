<?php
require __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST tələb olunur'], 405);
}

$jobId = $_POST['id'] ?? $_GET['id'] ?? '';
if ($jobId === '') json_response(['ok' => false, 'error' => 'id yoxdur'], 400);

$dir = job_dir($jobId);
if (!is_dir($dir)) json_response(['ok' => false, 'error' => 'job tapılmadı'], 404);

$status = read_status($jobId);
$pid = (int)($status['pid'] ?? 0);

if ($pid > 0 && pid_running($pid)) {
    kill_process_tree($pid);
}

$status['state'] = 'cancelled';
$status['phase'] = 'cancelled';
$status['finished_at'] = date('c');
$status['error'] = 'İstifadəçi tərəfindən dayandırıldı';
write_status($jobId, $status);

json_response(['ok' => true]);
