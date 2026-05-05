<?php
declare(strict_types=1);

require __DIR__ . '/api/common.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$jobId = $argv[1] ?? '';
if ($jobId === '' || !preg_match('/^[a-z0-9]{8,32}$/', $jobId)) {
    fwrite(STDERR, "Yanlış job id\n");
    exit(1);
}

$jDir = JOBS_DIR . '/' . $jobId;
$dDir = DOWNLOADS_DIR . '/' . $jobId;
$cfgFile = $jDir . '/config.json';

if (!is_file($cfgFile)) {
    fwrite(STDERR, "config.json tapılmadı\n");
    exit(1);
}

$cfg = json_decode((string)file_get_contents($cfgFile), true);
if (!is_array($cfg)) {
    fwrite(STDERR, "config.json oxunmadı\n");
    exit(1);
}

$url = (string)$cfg['url'];
$format = (string)$cfg['format'];
$quality = (string)$cfg['quality'];

$ytdlp = trim((string)shell_exec('where yt-dlp 2>nul'));
$ytdlp = strtok($ytdlp, "\r\n") ?: 'yt-dlp';

$outTpl = $dDir . '/%(playlist_index)03d - %(title)s.%(ext)s';

$args = [
    $ytdlp,
    '--newline',
    '--no-colors',
    '--ignore-errors',
    '--no-overwrites',
    '--add-metadata',
    '--output', $outTpl,
];

if ($format === 'mp3') {
    $audioQ = match ($quality) {
        'low' => '5',
        'medium' => '2',
        default => '0',
    };
    array_push($args,
        '-x',
        '--audio-format', 'mp3',
        '--audio-quality', $audioQ,
        '--embed-thumbnail'
    );
} else {
    $videoFmt = match ($quality) {
        'low'    => 'bestvideo[height<=480]+bestaudio/best[height<=480]/best',
        'medium' => 'bestvideo[height<=720]+bestaudio/best[height<=720]/best',
        default  => 'bestvideo+bestaudio/best',
    };
    array_push($args,
        '-f', $videoFmt,
        '--merge-output-format', 'mp4'
    );
}

$args[] = $url;

$cmdLine = implode(' ', array_map(fn($a) => '"' . str_replace('"', '\\"', $a) . '"', $args));

$status = [
    'state' => 'running',
    'percent' => 0,
    'current_item' => 0,
    'total_items' => 0,
    'speed' => '',
    'eta' => '',
    'log' => '',
];
write_status($jobId, $status);

$descriptors = [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$proc = proc_open($cmdLine, $descriptors, $pipes, $jDir);
if (!is_resource($proc)) {
    $status['state'] = 'error';
    $status['log'] = 'proc_open uğursuz oldu';
    write_status($jobId, $status);
    exit(1);
}

stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$lastWrite = 0;

while (true) {
    $r = [$pipes[1], $pipes[2]];
    $w = null; $e = null;
    $changed = @stream_select($r, $w, $e, 1);
    if ($changed === false) break;

    foreach ($r as $stream) {
        $chunk = fread($stream, 4096);
        if ($chunk === false || $chunk === '') continue;
        parse_chunk($chunk, $status);
    }

    $now = microtime(true);
    if ($now - $lastWrite > 0.5) {
        write_status($jobId, $status);
        $lastWrite = $now;
    }

    $st = proc_get_status($proc);
    if (!$st['running']) {
        foreach ([$pipes[1], $pipes[2]] as $s) {
            $rest = stream_get_contents($s);
            if ($rest) parse_chunk($rest, $status);
        }
        break;
    }
}

fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($proc);

$hasFiles = false;
if (is_dir($dDir)) {
    foreach (new DirectoryIterator($dDir) as $f) {
        if ($f->isFile() && !in_array(strtolower($f->getExtension()), ['part', 'ytdl', 'tmp'], true)) {
            $hasFiles = true;
            break;
        }
    }
}

$status['state'] = ($exit === 0 || $hasFiles) ? 'done' : 'error';
$status['percent'] = 100;
write_status($jobId, $status);

function parse_chunk(string $chunk, array &$status): void {
    static $buffer = '';
    $buffer .= $chunk;
    while (($pos = strpos($buffer, "\n")) !== false) {
        $line = rtrim(substr($buffer, 0, $pos), "\r");
        $buffer = substr($buffer, $pos + 1);
        parse_line($line, $status);
    }
}

function parse_line(string $line, array &$status): void {
    if ($line === '') return;

    $status['log'] = mb_substr(($status['log'] ?? '') . $line . "\n", -8000);

    if (preg_match('/Downloading item (\d+) of (\d+)/i', $line, $m)) {
        $status['current_item'] = (int)$m[1];
        $status['total_items'] = (int)$m[2];
        $status['percent'] = 0;
        return;
    }

    if (preg_match('/\[download\]\s+([\d.]+)%(?:\s+of\s+~?\s*[^\s]+)?(?:\s+at\s+([^\s]+))?(?:\s+ETA\s+([^\s]+))?/i', $line, $m)) {
        $itemPct = (float)$m[1];
        $status['speed'] = isset($m[2]) ? $m[2] : ($status['speed'] ?? '');
        $status['eta'] = isset($m[3]) ? $m[3] : ($status['eta'] ?? '');

        if (($status['total_items'] ?? 0) > 1 && ($status['current_item'] ?? 0) > 0) {
            $perItem = 100.0 / $status['total_items'];
            $base = ($status['current_item'] - 1) * $perItem;
            $status['percent'] = min(100.0, $base + ($itemPct * $perItem / 100.0));
        } else {
            $status['percent'] = $itemPct;
        }
    }
}
