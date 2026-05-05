<?php
require __DIR__ . '/common.php';

$jobId = $_GET['id'] ?? '';
if ($jobId === '') json_response(['ok' => false, 'error' => 'id yoxdur'], 400);

$dir = job_dir($jobId);
if (!is_dir($dir)) json_response(['ok' => false, 'error' => 'job tapılmadı'], 404);

$status = read_status($jobId);
$cfg = read_config($jobId);

$dDir = download_dir($jobId);
$out = count_output_files($dDir);

$pid = (int)($status['pid'] ?? 0);
if (($status['state'] ?? '') === 'running' && $pid > 0 && !pid_running($pid)) {
    $status['state'] = ($out['count'] > 0) ? 'done' : 'error';
    $status['phase'] = $status['state'];
    $status['percent'] = ($status['state'] === 'done') ? 100 : (float)($status['percent'] ?? 0);
    $status['finished_at'] = $status['finished_at'] ?? date('c');
    if ($status['state'] === 'error' && empty($status['error'])) {
        $status['error'] = 'İşçi proses gözlənilmədən dayandı';
    }
    write_status($jobId, $status);
}

$logTail = '';
$logFile = $dir . '/yt.log';
if (is_file($logFile)) {
    $size = filesize($logFile);
    $offset = max(0, $size - 12288);
    $fh = fopen($logFile, 'rb');
    if ($fh) {
        if ($offset > 0) fseek($fh, $offset);
        $logTail = stream_get_contents($fh) ?: '';
        fclose($fh);
    }
}

json_response([
    'ok' => true,
    'job_id' => $jobId,
    'state' => (string)($status['state'] ?? 'unknown'),
    'phase' => (string)($status['phase'] ?? ($status['state'] ?? 'unknown')),
    'percent' => (float)($status['percent'] ?? 0),
    'current_item' => (int)($status['current_item'] ?? 0),
    'total_items' => (int)($status['total_items'] ?? 0),
    'speed' => (string)($status['speed'] ?? ''),
    'eta' => (string)($status['eta'] ?? ''),
    'title' => (string)($status['title'] ?? ''),
    'started_at' => $status['started_at'] ?? null,
    'finished_at' => $status['finished_at'] ?? null,
    'error' => $status['error'] ?? null,
    'config' => [
        'url' => (string)($cfg['url'] ?? ''),
        'format' => (string)($cfg['format'] ?? ''),
        'quality' => (string)($cfg['quality'] ?? ''),
    ],
    'files_count' => $out['count'],
    'files_size' => $out['size'],
    'log' => $logTail,
]);
