<?php
require __DIR__ . '/common.php';

$jobId = $_GET['id'] ?? '';
if ($jobId === '') json_response(['ok' => false, 'error' => 'id yoxdur'], 400);

$dDir = download_dir($jobId);
if (!is_dir($dDir)) json_response(['ok' => false, 'error' => 'qovluq tapılmadı'], 404);

if (isset($_GET['download'])) {
    $name = basename((string)$_GET['download']);
    if ($name === '' || $name === '.' || $name === '..') {
        http_response_code(400);
        exit('Yanlış fayl adı');
    }
    $path = $dDir . DIRECTORY_SEPARATOR . $name;
    $real = realpath($path);
    $base = realpath($dDir);
    if ($real === false || $base === false || !is_file($real)
        || strncmp($real, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0) {
        http_response_code(404);
        exit('Fayl tapılmadı');
    }
    $asciiName = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    if ($asciiName === '' || $asciiName === '.' || $asciiName === '..') $asciiName = 'download.bin';
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($real));
    header(
        'Content-Disposition: attachment; filename="' . $asciiName . '"; '
        . "filename*=UTF-8''" . rawurlencode($name)
    );
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
