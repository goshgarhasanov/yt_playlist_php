<?php
declare(strict_types=1);

const ROOT_DIR = __DIR__ . '/..';
const JOBS_DIR = ROOT_DIR . '/jobs';
const DOWNLOADS_DIR = ROOT_DIR . '/downloads';

function json_response(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function job_dir(string $jobId): string {
    if (!preg_match('/^[a-z0-9]{8,32}$/', $jobId)) {
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
    @file_put_contents($f, json_encode($status, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function new_job_id(): string {
    return bin2hex(random_bytes(8));
}
