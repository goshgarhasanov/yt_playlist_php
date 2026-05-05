<?php
/**
 * ====================================================================
 * cancel.php — Aktiv yükləməni dayandıran endpoint
 * ====================================================================
 *
 * İstifadəçi UI-də "Dayandır" düyməsini basanda buraya POST sorğusu
 * göndərilir. Endpoint worker prosesini və onun bütün uşaq proseslərini
 * (yt-dlp, ffmpeg) öldürür və status.json-u "cancelled" olaraq yeniləyir.
 *
 * URL: POST /api/cancel.php
 * Body: id=<job_id>
 *
 * Cavab:
 *   {"ok": true}
 */

require __DIR__ . '/common.php';

// --------------------------------------------------------------------
// 1. Yalnız POST qəbul edilir
// --------------------------------------------------------------------
// Bu endpoint dəyişiklik edir (proses öldürür, status yeniləyir),
// ona görə GET ilə çağırılması icazəlsizdir.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST tələb olunur'], 405);
}

// --------------------------------------------------------------------
// 2. Job ID-ni al (POST və ya GET-dən)
// --------------------------------------------------------------------
// Hər iki mənbədən qəbul edirik — frontend POST göndərsin, lakin
// curl ilə test üçün GET-dən də oxumağa imkan veririk.
$jobId = $_POST['id'] ?? $_GET['id'] ?? '';
if ($jobId === '') {
    json_response(['ok' => false, 'error' => 'id yoxdur'], 400);
}

// job_dir() ID-ni validate edir, etibarsızdırsa skripti dayandırır
$dir = job_dir($jobId);
if (!is_dir($dir)) {
    json_response(['ok' => false, 'error' => 'job tapılmadı'], 404);
}

// --------------------------------------------------------------------
// 3. Worker prosesinin PID-ini oxu
// --------------------------------------------------------------------
$status = read_status($jobId);
$pid    = (int)($status['pid'] ?? 0);

// --------------------------------------------------------------------
// 4. Prosesi (və uşaq proseslərini) öldür
// --------------------------------------------------------------------
// PID 0 və ya artıq ölmüş ola bilər — pid_running ilə yoxlayırıq.
// Worker yt-dlp-i çağırır, yt-dlp ffmpeg-i çağırır. kill_process_tree
// hamısını birdən bağlayır (Windows-da /T flag, Unix-də process group).
if ($pid > 0 && pid_running($pid)) {
    kill_process_tree($pid);
}

// --------------------------------------------------------------------
// 5. Status-u "cancelled" olaraq yenilə
// --------------------------------------------------------------------
// Frontend status-u oxuyanda "cancelled" görəcək və progress bar-ı
// qırmızı edib "İstifadəçi tərəfindən dayandırıldı" mesajını göstərəcək.
$status['state']       = 'cancelled';
$status['phase']       = 'cancelled';
$status['finished_at'] = date('c');
$status['error']       = 'İstifadəçi tərəfindən dayandırıldı';

write_status($jobId, $status);

json_response(['ok' => true]);
