<?php
require __DIR__ . '/common.php';

$jobs = [];
if (is_dir(JOBS_DIR)) {
    foreach (new DirectoryIterator(JOBS_DIR) as $entry) {
        if ($entry->isDot() || !$entry->isDir()) continue;
        $id = $entry->getFilename();
        if (!valid_job_id($id)) continue;

        $cfg = read_config($id);
        $st = read_status($id);
        $out = count_output_files(download_dir($id));

        $jobs[] = [
            'job_id' => $id,
            'state' => $st['state'] ?? 'unknown',
            'phase' => $st['phase'] ?? '',
            'percent' => (float)($st['percent'] ?? 0),
            'title' => $st['title'] ?? '',
            'url' => $cfg['url'] ?? '',
            'format' => $cfg['format'] ?? '',
            'quality' => $cfg['quality'] ?? '',
            'started_at' => $st['started_at'] ?? null,
            'finished_at' => $st['finished_at'] ?? null,
            'files_count' => $out['count'],
            'files_size' => $out['size'],
        ];
    }
}

usort($jobs, function($a, $b) {
    return strcmp($b['started_at'] ?? '', $a['started_at'] ?? '');
});

json_response(['ok' => true, 'jobs' => $jobs]);
