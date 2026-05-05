<?php
/**
 * ====================================================================
 * status.php — İşin cari vəziyyətini qaytaran endpoint
 * ====================================================================
 *
 * Frontend bu endpoint-i hər ~350ms-də poll edir ki, real-time progress
 * göstərə bilsin. Cavabda işin vəziyyəti, faiz, sürət, ETA, başlıq,
 * və yt-dlp-nin son log sətirləri olur.
 *
 * Vacib funksionallıq: əgər worker prosesi gözlənilmədən ölübsə
 * (məsələn, Windows yenidən başladılıb), bu endpoint onu aşkar edir
 * və status-u "error" və ya "done" olaraq düzəldir.
 *
 * URL: GET /api/status.php?id=<job_id>
 *
 * Cavab:
 *   {
 *     "ok": true,
 *     "state": "running" | "done" | "error" | "cancelled" | "queued",
 *     "phase": "fetching" | "downloading" | "converting" | ...,
 *     "percent": 42.5,
 *     "current_item": 3, "total_items": 12,
 *     "speed": "1.20MiB/s", "eta": "00:14",
 *     "title": "Mahnı adı",
 *     "files_count": 2, "files_size": 8765432,
 *     "error": null,
 *     "log": "[download] 42.5% ..."
 *   }
 */

require __DIR__ . '/common.php';

// --------------------------------------------------------------------
// 1. Job ID-ni al və yoxla
// --------------------------------------------------------------------
$jobId = $_GET['id'] ?? '';
if ($jobId === '') {
    json_response(['ok' => false, 'error' => 'id yoxdur'], 400);
}

// job_dir() həm qovluq yolunu qaytarır, həm də ID-ni validate edir
// (etibarsızdırsa skripti dayandırır).
$dir = job_dir($jobId);

// Qovluq yoxdursa — silə bilərlər və ya heç vaxt mövcud olmayıb
if (!is_dir($dir)) {
    json_response(['ok' => false, 'error' => 'job tapılmadı'], 404);
}

// --------------------------------------------------------------------
// 2. Status və config oxu
// --------------------------------------------------------------------
$status = read_status($jobId);
$cfg    = read_config($jobId);

// --------------------------------------------------------------------
// 3. Yüklənmiş faylları hesabla
// --------------------------------------------------------------------
$dDir = download_dir($jobId);
$out  = count_output_files($dDir);

// --------------------------------------------------------------------
// 4. "Zombie job" düzəlişi
// --------------------------------------------------------------------
// Əgər status "running"-dir, lakin prosesin PID-i artıq mövcud deyilsə,
// worker prosesi gözlənilmədən bitib (məsələn, sistem yenidən başladılıb,
// task manager-dən bağlanıb, və s.). Bu vəziyyətdə status "running"
// olaraq qalsa, frontend əbədi olaraq poll edəcək.
//
// Həll: əgər fayllar varsa "done" et, yoxdursa "error".
$pid = (int)($status['pid'] ?? 0);
if (($status['state'] ?? '') === 'running' && $pid > 0 && !pid_running($pid)) {
    $status['state']   = ($out['count'] > 0) ? 'done' : 'error';
    $status['phase']   = $status['state'];
    $status['percent'] = ($status['state'] === 'done')
        ? 100
        : (float)($status['percent'] ?? 0);
    $status['finished_at'] = $status['finished_at'] ?? date('c');

    // Xəta varsa istifadəçiyə nəyin baş verdiyini izah et
    if ($status['state'] === 'error' && empty($status['error'])) {
        $status['error'] = 'İşçi proses gözlənilmədən dayandı';
    }

    // Düzəldilmiş status-u diskdə də yenilə
    write_status($jobId, $status);
}

// --------------------------------------------------------------------
// 5. yt-dlp log-unun son hissəsini oxu
// --------------------------------------------------------------------
// UI-də "Log baxışı" hissəsi var — istifadəçi yt-dlp-nin nə etdiyini
// görmək istəyə bilər. Bütün log fayllarının böyük olma ehtimalı var,
// ona görə yalnız son 12KB-ı oxuyuruq.
$logTail = '';
$logFile = $dir . '/yt.log';

if (is_file($logFile)) {
    $size   = filesize($logFile);
    $offset = max(0, $size - 12288);    // Son 12KB

    $fh = fopen($logFile, 'rb');
    if ($fh) {
        if ($offset > 0) {
            fseek($fh, $offset);    // Son 12KB-a keç
        }
        $logTail = stream_get_contents($fh) ?: '';
        fclose($fh);
    }
}

// --------------------------------------------------------------------
// 6. Cavabı qur və göndər
// --------------------------------------------------------------------
// Bütün dəyərlər explicit cast olunur — frontend həmişə eyni tip
// alacağına əmin olsun deyə (məsələn, percent həmişə float).
//
// (string)($status['x'] ?? '') — null safe casting; sahə yoxdursa
// boş sətir, varsa string-ə çevrilir.
json_response([
    'ok'           => true,
    'job_id'       => $jobId,
    'state'        => (string)($status['state'] ?? 'unknown'),
    'phase'        => (string)($status['phase'] ?? ($status['state'] ?? 'unknown')),
    'percent'      => (float)($status['percent'] ?? 0),
    'current_item' => (int)($status['current_item'] ?? 0),
    'total_items'  => (int)($status['total_items'] ?? 0),
    'speed'        => (string)($status['speed'] ?? ''),
    'eta'          => (string)($status['eta'] ?? ''),
    'title'        => (string)($status['title'] ?? ''),
    'started_at'   => $status['started_at']  ?? null,
    'finished_at'  => $status['finished_at'] ?? null,
    'error'        => $status['error']       ?? null,
    'config'       => [
        'url'     => (string)($cfg['url']     ?? ''),
        'format'  => (string)($cfg['format']  ?? ''),
        'quality' => (string)($cfg['quality'] ?? ''),
    ],
    'files_count'  => $out['count'],
    'files_size'   => $out['size'],
    'log'          => $logTail,
]);
