<?php
declare(strict_types=1);

require __DIR__ . '/api/common.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$jobId = $argv[1] ?? '';
if ($jobId === '' || !valid_job_id($jobId)) {
    fwrite(STDERR, "Yanlış job id\n");
    exit(1);
}

$jDir = JOBS_DIR . '/' . $jobId;
$dDir = DOWNLOADS_DIR . '/' . $jobId;

$cfg = read_config($jobId);
if (!$cfg) {
    write_status($jobId, ['state' => 'error', 'error' => 'config tapılmadı', 'finished_at' => date('c')]);
    exit(1);
}

$url = (string)$cfg['url'];
$format = (string)$cfg['format'];
$quality = (string)$cfg['quality'];

$ytdlp = trim((string)shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where yt-dlp 2>nul' : 'which yt-dlp 2>/dev/null'));
$ytdlp = strtok($ytdlp, "\r\n") ?: '';
if ($ytdlp === '' || !is_file($ytdlp)) {
    write_status($jobId, [
        'state' => 'error',
        'phase' => 'error',
        'percent' => 0,
        'error' => 'yt-dlp tapılmadı. Quraşdırıb PATH-a əlavə edin.',
        'finished_at' => date('c'),
        'updated_at' => date('c'),
        'pid' => 0,
    ]);
    exit(1);
}

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    write_status($jobId, [
        'state' => 'error',
        'phase' => 'error',
        'error' => 'URL etibarsızdır',
        'finished_at' => date('c'),
        'pid' => 0,
    ]);
    exit(1);
}
$urlHost = parse_url($url, PHP_URL_HOST) ?? '';
if (!preg_match('/(^|\.)(youtube\.com|youtu\.be|music\.youtube\.com)$/i', $urlHost)) {
    write_status($jobId, [
        'state' => 'error',
        'phase' => 'error',
        'error' => 'Yalnız YouTube URL-ləri qəbul olunur',
        'finished_at' => date('c'),
        'pid' => 0,
    ]);
    exit(1);
}

$outTpl = $dDir . '/%(playlist_index&{} - |)s%(title)s.%(ext)s';

$args = [
    $ytdlp,
    '--newline',
    '--no-colors',
    '--ignore-errors',
    '--no-overwrites',
    '--add-metadata',
    '--progress',
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

$status = read_status($jobId);
$status['state'] = 'running';
$status['phase'] = 'fetching';
$status['pid'] = getmypid();
$status['updated_at'] = date('c');
write_status($jobId, $status);

$ytLog = fopen($jDir . '/yt.log', 'wb');

$descriptors = [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$procOpts = PHP_OS_FAMILY === 'Windows' ? ['bypass_shell' => true] : [];
$proc = proc_open($args, $descriptors, $pipes, $jDir, null, $procOpts);
if (!is_resource($proc)) {
    $status['state'] = 'error';
    $status['phase'] = 'error';
    $status['error'] = 'proc_open uğursuz';
    $status['finished_at'] = date('c');
    write_status($jobId, $status);
    if ($ytLog) fclose($ytLog);
    exit(1);
}

stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$lastWrite = 0.0;
$buffer1 = '';
$buffer2 = '';

while (true) {
    $st = proc_get_status($proc);

    foreach ([1 => &$buffer1, 2 => &$buffer2] as $idx => &$buf) {
        $chunk = @fread($pipes[$idx], 8192);
        if ($chunk !== false && $chunk !== '') {
            if ($ytLog) { fwrite($ytLog, $chunk); fflush($ytLog); }
            $buf .= $chunk;
            while (true) {
                $nl = strpos($buf, "\n");
                $cr = strpos($buf, "\r");
                $pos = false;
                if ($nl !== false && $cr !== false) $pos = min($nl, $cr);
                elseif ($nl !== false) $pos = $nl;
                elseif ($cr !== false) $pos = $cr;
                if ($pos === false) break;
                $line = rtrim(substr($buf, 0, $pos), "\r\n");
                $buf = substr($buf, $pos + 1);
                if ($line !== '') parse_line($line, $status);
            }
        }
    }
    unset($buf);

    $now = microtime(true);
    if ($now - $lastWrite > 0.15) {
        $status['updated_at'] = date('c');
        write_status($jobId, $status);
        $lastWrite = $now;
    }

    if (!$st['running']) {
        foreach ([$pipes[1], $pipes[2]] as $s) {
            $rest = @stream_get_contents($s);
            if ($rest !== false && $rest !== '') {
                if ($ytLog) { fwrite($ytLog, $rest); fflush($ytLog); }
                foreach (preg_split('/[\r\n]+/', $rest) as $line) {
                    if ($line !== '') parse_line($line, $status);
                }
            }
        }
        break;
    }

    usleep(50000);
}

@fclose($pipes[1]);
@fclose($pipes[2]);
$exit = proc_close($proc);
if ($ytLog) fclose($ytLog);

$out = count_output_files($dDir);
$success = ($exit === 0) || ($out['count'] > 0);

$status['state'] = $success ? 'done' : 'error';
$status['phase'] = $status['state'];
$status['percent'] = $success ? 100 : (float)($status['percent'] ?? 0);
$status['finished_at'] = date('c');
$status['updated_at'] = date('c');
$status['speed'] = '';
$status['eta'] = '';
if (!$success && empty($status['error'])) {
    $status['error'] = 'yt-dlp xəta ilə dayandı (kod: ' . $exit . ')';
}
write_status($jobId, $status);

function parse_line(string $line, array &$status): void {
    if ($line === '') return;

    if (preg_match('/^\[youtube(?::tab)?\]/i', $line)) {
        if (($status['phase'] ?? '') === 'fetching' || ($status['phase'] ?? '') === 'queued') {
            $status['phase'] = 'fetching';
        }
        return;
    }

    if (preg_match('/Downloading item (\d+) of (\d+)/i', $line, $m)) {
        $cur = (int)$m[1];
        $total = (int)$m[2];
        $status['current_item'] = $cur;
        $status['total_items'] = $total;
        $status['percent'] = $total > 0 ? (($cur - 1) / $total) * 100.0 : 0.0;
        $status['phase'] = 'downloading';
        $status['speed'] = '';
        $status['eta'] = '';
        return;
    }

    if (preg_match('/^\[download\]\s+Destination:\s*(.+)$/i', $line, $m)) {
        $path = trim($m[1]);
        $name = basename($path);
        $name = preg_replace('/^\d+\s*-\s*/', '', $name);
        $name = preg_replace('/\.[^.]+$/', '', $name);
        if ($name) $status['title'] = $name;
        $status['phase'] = 'downloading';
        return;
    }

    if (preg_match('/^\[Merger\]/i', $line) || preg_match('/^\[ExtractAudio\]/i', $line) || preg_match('/^\[ffmpeg\]/i', $line)) {
        $status['phase'] = 'converting';
        return;
    }

    if (preg_match('/^\[EmbedThumbnail\]/i', $line) || preg_match('/^\[Metadata\]/i', $line)) {
        $status['phase'] = 'finalizing';
        return;
    }

    if (preg_match('/^\[download\]\s+([\d.]+)%(?:\s+of\s+~?\s*([^\s]+))?(?:\s+at\s+([^\s]+))?(?:\s+ETA\s+([^\s]+))?/i', $line, $m)) {
        $itemPct = (float)$m[1];
        if (!empty($m[3]) && $m[3] !== 'Unknown') $status['speed'] = $m[3];
        if (!empty($m[4]) && $m[4] !== 'Unknown') $status['eta'] = $m[4];

        $total = (int)($status['total_items'] ?? 0);
        $cur = (int)($status['current_item'] ?? 0);
        if ($total > 1 && $cur > 0) {
            $perItem = 100.0 / $total;
            $base = ($cur - 1) * $perItem;
            $status['percent'] = min(100.0, $base + ($itemPct * $perItem / 100.0));
        } else {
            $status['percent'] = $itemPct;
        }
        $status['phase'] = 'downloading';
        return;
    }

    if (preg_match('/^ERROR:/i', $line)) {
        $status['error'] = trim(substr($line, 6));
    }
}
