<?php
require __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST tələb olunur'], 405);
}

$url = trim($_POST['url'] ?? '');
$format = $_POST['format'] ?? 'mp3';
$quality = $_POST['quality'] ?? 'best';

if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
    json_response(['ok' => false, 'error' => 'Yanlış URL'], 400);
}
if (!in_array($format, ['mp3', 'mp4'], true)) {
    json_response(['ok' => false, 'error' => 'Yanlış format'], 400);
}
if (!in_array($quality, ['best', 'medium', 'low'], true)) {
    json_response(['ok' => false, 'error' => 'Yanlış keyfiyyət'], 400);
}

$host = parse_url($url, PHP_URL_HOST) ?? '';
if (!preg_match('/(^|\.)(youtube\.com|youtu\.be|music\.youtube\.com)$/i', $host)) {
    json_response(['ok' => false, 'error' => 'Yalnız YouTube linkləri qəbul olunur'], 400);
}

$jobId = new_job_id();
$jDir = job_dir($jobId);
$dDir = download_dir($jobId);
@mkdir($jDir, 0777, true);
@mkdir($dDir, 0777, true);

file_put_contents($jDir . '/config.json', json_encode([
    'url' => $url,
    'format' => $format,
    'quality' => $quality,
    'created_at' => date('c'),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

write_status($jobId, [
    'state' => 'queued',
    'phase' => 'queued',
    'percent' => 0,
    'current_item' => 0,
    'total_items' => 0,
    'speed' => '',
    'eta' => '',
    'title' => '',
    'pid' => 0,
    'started_at' => date('c'),
    'updated_at' => date('c'),
    'finished_at' => null,
    'error' => null,
]);

$worker = realpath(ROOT_DIR . '/worker.php');
$phpBin = PHP_BINARY;
$workerLog = $jDir . '/worker.log';

if (PHP_OS_FAMILY === 'Windows') {
    $cmd = sprintf(
        'start /B "" "%s" "%s" "%s" > "%s" 2>&1',
        $phpBin,
        $worker,
        $jobId,
        $workerLog
    );
    $proc = popen($cmd, 'r');
    if ($proc === false) {
        json_response(['ok' => false, 'error' => 'İşçi proses başladıla bilmədi'], 500);
    }
    pclose($proc);
} else {
    $cmd = sprintf('%s %s %s > %s 2>&1 &',
        escapeshellarg($phpBin),
        escapeshellarg($worker),
        escapeshellarg($jobId),
        escapeshellarg($workerLog)
    );
    exec($cmd);
}

json_response(['ok' => true, 'job_id' => $jobId]);
