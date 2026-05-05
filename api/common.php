<?php
declare(strict_types=1);

const ROOT_DIR = __DIR__ . '/..';
const JOBS_DIR = ROOT_DIR . '/jobs';
const DOWNLOADS_DIR = ROOT_DIR . '/downloads';

function json_response(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function valid_job_id(string $id): bool {
    return (bool)preg_match('/^[a-z0-9]{8,32}$/', $id);
}

function job_dir(string $jobId): string {
    if (!valid_job_id($jobId)) {
        json_response(['ok' => false, 'error' => 'Yanlış job id'], 400);
    }
    return JOBS_DIR . '/' . $jobId;
}

function download_dir(string $jobId): string {
    return DOWNLOADS_DIR . '/' . $jobId;
}

function read_status(string $jobId): array {
    $f = job_dir($jobId) . '/status.json';
    if (!is_file($f)) return ['state' => 'unknown'];
    $raw = @file_get_contents($f);
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : ['state' => 'unknown'];
}

function write_status(string $jobId, array $status): void {
    $f = job_dir($jobId) . '/status.json';
    $tmp = $f . '.tmp.' . bin2hex(random_bytes(4));
    $payload = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($payload === false) return;
    if (@file_put_contents($tmp, $payload) === false) return;
    @rename($tmp, $f);
}

function read_config(string $jobId): array {
    $f = job_dir($jobId) . '/config.json';
    if (!is_file($f)) return [];
    $raw = @file_get_contents($f);
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : [];
}

function new_job_id(): string {
    return bin2hex(random_bytes(8));
}

function pid_running(int $pid): bool {
    if ($pid <= 0) return false;
    if (PHP_OS_FAMILY === 'Windows') {
        $out = @shell_exec(sprintf('tasklist /FI "PID eq %d" /NH 2>nul', $pid));
        return is_string($out) && stripos($out, (string)$pid) !== false;
    }
    return posix_kill($pid, 0);
}

function kill_process_tree(int $pid): void {
    if ($pid <= 0) return;
    if (PHP_OS_FAMILY === 'Windows') {
        @shell_exec(sprintf('taskkill /PID %d /T /F 2>&1', $pid));
    } else {
        @posix_kill($pid, 15);
    }
}

function count_output_files(string $dDir): array {
    $count = 0; $size = 0;
    if (!is_dir($dDir)) return ['count' => 0, 'size' => 0];
    foreach (new DirectoryIterator($dDir) as $f) {
        if (!$f->isFile()) continue;
        $ext = strtolower($f->getExtension());
        if (in_array($ext, ['part', 'ytdl', 'tmp'], true)) continue;
        $count++; $size += $f->getSize();
    }
    return ['count' => $count, 'size' => $size];
}
