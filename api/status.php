<?php
require __DIR__ . '/common.php';

$jobId = $_GET['id'] ?? '';
if ($jobId === '') json_response(['ok' => false, 'error' => 'id yoxdur'], 400);

$dir = job_dir($jobId);
if (!is_dir($dir)) json_response(['ok' => false, 'error' => 'job tapılmadı'], 404);

$status = read_status($jobId);

$logFile = $dir . '/log.txt';
$logTail = '';
if (is_file($logFile)) {
    $size = filesize($logFile);
    $offset = max(0, $size - 8192);
    $fh = fopen($logFile, 'rb');
    if ($fh) {
        if ($offset > 0) fseek($fh, $offset);
        $logTail = stream_get_contents($fh) ?: '';
        fclose($fh);
    }
}

json_response([
    'ok' => true,
    'state' => $status['state'] ?? 'unknown',
    'percent' => (float)($status['percent'] ?? 0),
    'current_item' => (int)($status['current_item'] ?? 0),
    'total_items' => (int)($status['total_items'] ?? 0),
    'speed' => $status['speed'] ?? '',
    'eta' => $status['eta'] ?? '',
    'log' => $logTail,
]);
