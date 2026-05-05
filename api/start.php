<?php
/**
 * ====================================================================
 * start.php — Yeni yükləmə işi başladan endpoint
 * ====================================================================
 *
 * Bu endpoint frontend-dən gələn POST sorğusunu qəbul edir, daxil olan
 * məlumatı (URL, format, keyfiyyət) yoxlayır, yeni iş yaradır və
 * worker.php-i fonda buraxır. İstifadəçi cavab kimi job_id alır və
 * sonradan status.php endpoint-i ilə həmin işi izləyə bilər.
 *
 * Axın:
 *   1. POST metodu yoxlanır
 *   2. URL, format, keyfiyyət parametrləri yoxlanır
 *   3. Domain whitelist (yalnız YouTube)
 *   4. Yeni job_id yaradılır, qovluqlar açılır
 *   5. config.json və ilkin status.json yazılır
 *   6. worker.php fonda işə salınır (start /B Windows-da)
 *   7. Job ID frontend-ə qaytarılır
 *
 * Cavab:
 *   uğur: {"ok": true, "job_id": "abc123..."}
 *   xəta: {"ok": false, "error": "..."}
 */

require __DIR__ . '/common.php';

// --------------------------------------------------------------------
// 1. HTTP metod yoxlaması
// --------------------------------------------------------------------
// Bu endpoint dəyişiklik edir (yeni iş yaradır), ona görə yalnız POST.
// GET ilə çağırılarsa 405 Method Not Allowed qaytarılır.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST tələb olunur'], 405);
}

// --------------------------------------------------------------------
// 2. Daxil olan parametrləri oxu və təmizlə
// --------------------------------------------------------------------
$url     = trim($_POST['url'] ?? '');         // URL — boşluqları sil
$format  = $_POST['format']  ?? 'mp3';        // Format — default mp3
$quality = $_POST['quality'] ?? 'best';       // Keyfiyyət — default ən yaxşı

// --------------------------------------------------------------------
// 3. URL yoxlaması
// --------------------------------------------------------------------
// FILTER_VALIDATE_URL — formatın etibarlı URL olduğunu yoxlayır.
// Boş URL də burada bloklanır.
if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
    json_response(['ok' => false, 'error' => 'Yanlış URL'], 400);
}

// --------------------------------------------------------------------
// 4. Format whitelist
// --------------------------------------------------------------------
// Yalnız mp3 və mp4 dəstəklənir. Hər hansı başqa dəyər (məsələn,
// "php" və ya "<script>") dərhal rədd edilir.
if (!in_array($format, ['mp3', 'mp4'], true)) {
    json_response(['ok' => false, 'error' => 'Yanlış format'], 400);
}

// --------------------------------------------------------------------
// 5. Keyfiyyət whitelist
// --------------------------------------------------------------------
if (!in_array($quality, ['best', 'medium', 'low'], true)) {
    json_response(['ok' => false, 'error' => 'Yanlış keyfiyyət'], 400);
}

// --------------------------------------------------------------------
// 6. Domain whitelist — yalnız YouTube
// --------------------------------------------------------------------
// SSRF (Server-Side Request Forgery) hücumlarına qarşı qoruma.
// İstifadəçi şəbəkə daxili URL (məsələn, http://192.168.1.1/admin)
// göndərib serveri istifadə edə bilməsin deyə.
//
// Regex: domen ya tam "youtube.com" / "youtu.be" / "music.youtube.com"-dur,
// ya da onlarla bitir (subdomain ola bilər).
//
// $ — sonu (anchored), beləliklə "evilyoutube.com" qəbul olunmur.
// (^|\.) — ya başlanğıc, ya da "." (subdomain ayrıcısı).
$host = parse_url($url, PHP_URL_HOST) ?? '';
if (!preg_match('/(^|\.)(youtube\.com|youtu\.be|music\.youtube\.com)$/i', $host)) {
    json_response(['ok' => false, 'error' => 'Yalnız YouTube linkləri qəbul olunur'], 400);
}

// --------------------------------------------------------------------
// 7. Yeni iş üçün ID və qovluqlar yarat
// --------------------------------------------------------------------
$jobId = new_job_id();           // 16 hex simvollu unikal ID
$jDir  = job_dir($jobId);        // jobs/<id>/
$dDir  = download_dir($jobId);   // downloads/<id>/

// Qovluqları yarat. @ — əgər artıq mövcuddursa xəta vermə.
@mkdir($jDir, 0777, true);
@mkdir($dDir, 0777, true);

// --------------------------------------------------------------------
// 8. config.json yaz — bu iş üçün dəyişməz parametrlər
// --------------------------------------------------------------------
file_put_contents($jDir . '/config.json', json_encode([
    'url'        => $url,
    'format'     => $format,
    'quality'    => $quality,
    'created_at' => date('c'),    // ISO 8601 tarix formatı
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// --------------------------------------------------------------------
// 9. İlkin status.json — frontend dərhal poll etməyə başlasın deyə
// --------------------------------------------------------------------
write_status($jobId, [
    'state'         => 'queued',         // İlkin vəziyyət — növbədə
    'phase'         => 'queued',         // UI faza göstəricisi üçün
    'percent'       => 0,                // Faiz hələ 0
    'current_item'  => 0,                // Playlist-də cari element nömrəsi
    'total_items'   => 0,                // Playlist-dəki ümumi element sayı
    'speed'         => '',               // Yükləmə sürəti (yt-dlp-dən gəlir)
    'eta'           => '',               // Təxmini qalan vaxt
    'title'         => '',               // Cari videonun başlığı
    'pid'           => 0,                // İşçi proses ID (worker özü dolduracaq)
    'started_at'    => date('c'),        // İş başladığı vaxt
    'updated_at'    => date('c'),        // Son yenilənmə vaxtı
    'finished_at'   => null,             // Bitmə vaxtı (hələ bitməyib)
    'error'         => null,             // Xəta mesajı (yoxdur)
]);

// --------------------------------------------------------------------
// 10. Worker prosesini fonda işə sal
// --------------------------------------------------------------------
// realpath — relativ yolu mütləq yola çevirir.
// PHP_BINARY — cari PHP CLI-nin tam yolu (php.exe).
$worker    = realpath(ROOT_DIR . '/worker.php');
$phpBin    = PHP_BINARY;
$workerLog = $jDir . '/worker.log';     // Worker-in stdout/stderr buraya yazılır

if (PHP_OS_FAMILY === 'Windows') {
    // Windows-da fonda işə salmaq üçün start /B istifadə edirik.
    //
    // Detallar:
    //   start  - cmd-in daxili komandası, yeni proses başladır
    //   /B     - yeni pəncərə açma (background)
    //   ""     - pəncərə başlığı (boş)
    //   "%s"   - tam yolda olan exe (PHP)
    //   "%s"   - script (worker.php)
    //   "%s"   - argument (job_id)
    //   > "%s" - stdout-u log fayla yönəlt
    //   2>&1   - stderr-i də stdout-a birləşdir
    //
    // Burada user input YOXDUR — phpBin, worker, jobId hamısı bizim
    // tərəfdən yaradılıb (jobId regex-validated). Ona görə cmd.exe
    // istifadəsi təhlükəsizdir.
    $cmd = sprintf(
        'start /B "" "%s" "%s" "%s" > "%s" 2>&1',
        $phpBin,
        $worker,
        $jobId,
        $workerLog
    );

    // popen() prosesi başladır və biz dərhal pclose ilə bağlayırıq.
    // start /B detached process yaradır — bizim PHP skripti
    // bitsə də, worker davam edir.
    $proc = popen($cmd, 'r');
    if ($proc === false) {
        json_response(['ok' => false, 'error' => 'İşçi proses başladıla bilmədi'], 500);
    }
    pclose($proc);

} else {
    // Unix-də sadə yanaşma — komandanın sonuna & qoyub fonda göndər.
    // exec() default olaraq bitməyini gözləyir, lakin & ilə nohup
    // davranışı əldə edirik.
    $cmd = sprintf('%s %s %s > %s 2>&1 &',
        escapeshellarg($phpBin),
        escapeshellarg($worker),
        escapeshellarg($jobId),
        escapeshellarg($workerLog)
    );
    exec($cmd);
}

// --------------------------------------------------------------------
// 11. Frontend-ə job_id qaytar
// --------------------------------------------------------------------
// Frontend bu ID ilə hər ~350ms-də status.php-i poll edəcək.
json_response(['ok' => true, 'job_id' => $jobId]);
