<?php
require __DIR__ . '/common.php';

$jobId = $_GET['id'] ?? '';
if ($jobId === '') json_response(['ok' => false, 'error' => 'id yoxdur'], 400);

$dDir = download_dir($jobId);
if (!is_dir($dDir)) json_response(['ok' => false, 'error' => 'qovluq tapılmadı'], 404);

if (isset($_GET['download'])) {
    $name = basename($_GET['download']);
    $path = $dDir . '/' . $name;
    $real = realpath($path);
    $base = realpath($dDir);
    if ($real === false || $base === false || strpos($real, $base) !== 0 || !is_file($real)) {
        http_response_code(404);
        exit('Fayl tapılmadı');
    }
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($real));
    header('Content-Disposition: attachment; filename="' . rawurlencode($name) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($real);
    exit;
}

$files = [];
$it = new DirectoryIterator($dDir);
foreach ($it as $f) {
    if ($f->isDot() || !$f->isFile()) continue;
    $ext = strtolower($f->getExtension());
    if (in_array($ext, ['part', 'ytdl', 'tmp'], true)) continue;
    $files[] = ['name' => $f->getFilename(), 'size' => $f->getSize()];
}
usort($files, fn($a, $b) => strcmp($a['name'], $b['name']));

json_response(['ok' => true, 'files' => $files]);
