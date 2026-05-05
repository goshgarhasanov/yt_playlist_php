<?php
/**
 * ====================================================================
 * worker.php — Yükləməni icra edən CLI işçi prosesi
 * ====================================================================
 *
 * Bu fayl HTTP üzərindən deyil, CLI rejimində işə salınır.
 * api/start.php hər yeni iş üçün bu skripti `start /B` ilə fonda
 * buraxır və ona job_id-ni argument kimi ötürür.
 *
 * İşin axını:
 *   1. job_id-ni argument-dən oxu və yoxla
 *   2. config.json-dan URL, format, keyfiyyəti yüklə
 *   3. yt-dlp və URL-i son dəfə yoxla (defense in depth)
 *   4. yt-dlp komand sətrini qur (format/keyfiyyətə görə fərqli)
 *   5. yt-dlp-i proc_open ilə işə sal (təhlükəsizlik üçün array sintaksisi)
 *   6. Stdout/stderr-i parse edib status.json-u yenilə
 *   7. Bitdikdə son statusu yaz (done / error)
 *
 * Təhlükəsizlik qeydi: proc_open-i array sintaksisi və bypass_shell ilə
 * istifadə edirik. Bu, Windows-da cmd.exe-ni tamamilə kənarlaşdırır,
 * ona görə URL-də olan & | < > " kimi simvollar shell tərəfindən
 * interpret olunmur. Beləliklə command injection mümkün deyil.
 */

declare(strict_types=1);

require __DIR__ . '/api/common.php';

// --------------------------------------------------------------------
// 1. Yalnız CLI-dən işə salına bilər
// --------------------------------------------------------------------
// Əgər kimsə bu faylı brauzerdən birbaşa çağırmağa çalışırsa, 403 ver.
// PHP_SAPI dəyəri "cli" CLI rejimini, "apache2handler" və ya "fpm" web
// rejimini bildirir.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// --------------------------------------------------------------------
// 2. Job ID-ni argument-dən al və yoxla
// --------------------------------------------------------------------
// $argv[0] — skriptin öz adı, $argv[1] — birinci argument (job_id)
$jobId = $argv[1] ?? '';

if ($jobId === '' || !valid_job_id($jobId)) {
    fwrite(STDERR, "Yanlış job id\n");
    exit(1);
}

$jDir = JOBS_DIR . '/' . $jobId;
$dDir = DOWNLOADS_DIR . '/' . $jobId;

// --------------------------------------------------------------------
// 3. config.json-u yüklə
// --------------------------------------------------------------------
$cfg = read_config($jobId);
if (!$cfg) {
    write_status($jobId, [
        'state'       => 'error',
        'error'       => 'config tapılmadı',
        'finished_at' => date('c'),
    ]);
    exit(1);
}

$url     = (string)$cfg['url'];
$format  = (string)$cfg['format'];
$quality = (string)$cfg['quality'];

// --------------------------------------------------------------------
// 4. yt-dlp icraçısının yolunu tap
// --------------------------------------------------------------------
// PATH-dan tap. Windows-da `where`, Unix-də `which` istifadə olunur.
// Cavabda bir neçə yol ola bilər (məsələn .exe və .py versiyaları),
// strtok ilə yalnız birinci sətri götürürük.
$ytdlp = trim((string)shell_exec(
    PHP_OS_FAMILY === 'Windows' ? 'where yt-dlp 2>nul' : 'which yt-dlp 2>/dev/null'
));
$ytdlp = strtok($ytdlp, "\r\n") ?: '';

// yt-dlp tapılmadısa, izahedici xəta mesajı qaytar
if ($ytdlp === '' || !is_file($ytdlp)) {
    write_status($jobId, [
        'state'       => 'error',
        'phase'       => 'error',
        'percent'     => 0,
        'error'       => 'yt-dlp tapılmadı. Quraşdırıb PATH-a əlavə edin.',
        'finished_at' => date('c'),
        'updated_at'  => date('c'),
        'pid'         => 0,
    ]);
    exit(1);
}

// --------------------------------------------------------------------
// 5. URL-i bir daha yoxla (defense in depth)
// --------------------------------------------------------------------
// Bu yoxlama artıq start.php-də olub, lakin worker birbaşa CLI-dən də
// çağırıla bilər (məsələn, debug zamanı). Hər halda təkrar yoxlayırıq —
// "trust no input" prinsipi.
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    write_status($jobId, [
        'state' => 'error', 'phase' => 'error',
        'error' => 'URL etibarsızdır',
        'finished_at' => date('c'), 'pid' => 0,
    ]);
    exit(1);
}

$urlHost = parse_url($url, PHP_URL_HOST) ?? '';
if (!preg_match('/(^|\.)(youtube\.com|youtu\.be|music\.youtube\.com)$/i', $urlHost)) {
    write_status($jobId, [
        'state' => 'error', 'phase' => 'error',
        'error' => 'Yalnız YouTube URL-ləri qəbul olunur',
        'finished_at' => date('c'), 'pid' => 0,
    ]);
    exit(1);
}

// --------------------------------------------------------------------
// 6. yt-dlp output şablonu
// --------------------------------------------------------------------
// %(playlist_index)03d — playlist nömrəsi 3 rəqəmlə (001, 002, ...)
//                        Tək video üçün boş, qoşulduqda " - " göstərmir.
// %(title)s            — videonun başlığı
// %(ext)s              — fayl uzantısı (mp3, mp4 və s.)
//
// & {} | əməliyyat operatoru: əgər playlist_index varsa "<nömrə> - "
// formatında, yoxdursa boş. Bu sayədə tək video üçün "001 - title.mp3"
// əvəzinə sadəcə "title.mp3" alınır.
$outTpl = $dDir . '/%(playlist_index&{} - |)s%(title)s.%(ext)s';

// --------------------------------------------------------------------
// 7. yt-dlp arqumentlərini qur
// --------------------------------------------------------------------
$args = [
    $ytdlp,
    '--newline',          // Hər progress yenilənməsi yeni sətrə (parse üçün asan)
    '--no-colors',        // ANSI rəng kodlarını söndür (parse-i asanlaşdırır)
    '--ignore-errors',    // Bir video xəta versə, qalan playlist-i davam etdir
    '--no-overwrites',    // Artıq mövcud faylların üzərinə yazma
    '--add-metadata',     // ID3 tag-ları əlavə et (mahnı adı, ifaçı və s.)
    '--progress',         // Progress göstər
    '--output', $outTpl,  // Çıxış adı şablonu
];

// ----------------------------------------------------------------
// 7.1. Format-spesifik arqumentlər
// ----------------------------------------------------------------
if ($format === 'mp3') {
    // MP3 keyfiyyət dəyəri yt-dlp üçün:
    //   0 = ən yaxşı (VBR ~245kbps)
    //   2 = orta (VBR ~190kbps)
    //   5 = aşağı (VBR ~130kbps)
    $audioQ = match ($quality) {
        'low'    => '5',
        'medium' => '2',
        default  => '0',
    };

    array_push($args,
        '-x',                            // audio çıxar (extract)
        '--audio-format', 'mp3',         // MP3 formatına çevir
        '--audio-quality', $audioQ,      // Yuxarıda seçilən keyfiyyət
        '--embed-thumbnail'              // Thumbnail-i albom şəkli kimi MP3-ə yerləşdir
    );

} else {
    // MP4 üçün format selector:
    //   bestvideo+bestaudio/best — ən yaxşı video və audio-nu ayrıca al,
    //                              sonra ffmpeg ilə birləşdir.
    //   [height<=NNN] — videonun maksimum yüksəkliyi.
    $videoFmt = match ($quality) {
        'low'    => 'bestvideo[height<=480]+bestaudio/best[height<=480]/best',
        'medium' => 'bestvideo[height<=720]+bestaudio/best[height<=720]/best',
        default  => 'bestvideo+bestaudio/best',
    };

    array_push($args,
        '-f', $videoFmt,
        '--merge-output-format', 'mp4'   // Audio və videonu MP4 konteynerinə birləşdir
    );
}

// URL — sonuncu argument olmalıdır (yt-dlp konvensiyası)
$args[] = $url;

// --------------------------------------------------------------------
// 8. İlkin status — "running" + öz PID-imiz
// --------------------------------------------------------------------
$status = read_status($jobId);
$status['state']      = 'running';
$status['phase']      = 'fetching';
$status['pid']        = getmypid();   // Cancel endpoint bu PID-i öldürəcək
$status['updated_at'] = date('c');
write_status($jobId, $status);

// --------------------------------------------------------------------
// 9. yt-dlp-i fonda işə sal
// --------------------------------------------------------------------
// yt.log faylı — yt-dlp-nin tam çıxışını saxlayır (debug üçün).
// "wb" — binary write rejimi (Windows-da fərq vacibdir).
$ytLog = fopen($jDir . '/yt.log', 'wb');

// Pipe-lar:
//   1 = stdout — yt-dlp-nin standart çıxışı
//   2 = stderr — xəta mesajları
$descriptors = [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

// proc_open opsiyaları:
// bypass_shell = true — Windows-da cmd.exe-ni keç. Birbaşa CreateProcess
// çağırılır, command injection vektoru yox olur.
$procOpts = PHP_OS_FAMILY === 'Windows' ? ['bypass_shell' => true] : [];

// proc_open-ə array kimi $args ötürürük (string deyil!) — bu sintaksis
// PHP 7.4+-da mövcuddur və hər argument-i təhlükəsiz şəkildə escape edir.
$proc = proc_open($args, $descriptors, $pipes, $jDir, null, $procOpts);

if (!is_resource($proc)) {
    $status['state']       = 'error';
    $status['phase']       = 'error';
    $status['error']       = 'proc_open uğursuz';
    $status['finished_at'] = date('c');
    write_status($jobId, $status);
    if ($ytLog) fclose($ytLog);
    exit(1);
}

// --------------------------------------------------------------------
// 10. Pipe-ları non-blocking rejimə keç
// --------------------------------------------------------------------
// Default rejimdə fread() məlumat gələnə qədər gözləyir (block edir).
// Biz isə paralel olaraq stdout və stderr-i oxumalıyıq, ona görə
// non-blocking rejim lazımdır.
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

// --------------------------------------------------------------------
// 11. Əsas oxuma dövrü
// --------------------------------------------------------------------
// Hər iterasiyada:
//   - Stdout və stderr-dən gələn yeni məlumatı oxu
//   - Sətirləri yt.log-a əlavə et
//   - Tam sətirləri parse_line ilə təhlil et və status-u yenilə
//   - Hər 150ms-dən bir status.json-u diskə yaz (frontend onu poll edir)
//   - Proses bitsə dövrdən çıx
$lastWrite = 0.0;
$buffer1   = '';   // stdout üçün buffer (yarımçıq sətirləri saxlayır)
$buffer2   = '';   // stderr üçün buffer

while (true) {
    $st = proc_get_status($proc);

    // ----------------------------------------------------------------
    // 11.1. Hər iki pipe-dan oxu
    // ----------------------------------------------------------------
    foreach ([1 => &$buffer1, 2 => &$buffer2] as $idx => &$buf) {
        // 8KB-a qədər oxu (kifayət qədər çox bir iterasiya üçün)
        $chunk = @fread($pipes[$idx], 8192);
        if ($chunk !== false && $chunk !== '') {

            // Tam log fayla yaz — heç nə itməsin
            if ($ytLog) {
                fwrite($ytLog, $chunk);
                fflush($ytLog);
            }

            // Buffer-ə əlavə et və tam sətirləri parse et
            $buf .= $chunk;

            // Sətirlərə böl. yt-dlp həm \n, həm də \r (carriage return)
            // istifadə edə bilər (xüsusən progress yenilənmələri üçün).
            // Hər iki ayırıcını da nəzərə alırıq.
            while (true) {
                $nl = strpos($buf, "\n");
                $cr = strpos($buf, "\r");
                $pos = false;

                if ($nl !== false && $cr !== false) {
                    $pos = min($nl, $cr);
                } elseif ($nl !== false) {
                    $pos = $nl;
                } elseif ($cr !== false) {
                    $pos = $cr;
                }

                if ($pos === false) break;   // Tam sətir hələ tamamlanmayıb

                $line = rtrim(substr($buf, 0, $pos), "\r\n");
                $buf  = substr($buf, $pos + 1);

                if ($line !== '') {
                    parse_line($line, $status);
                }
            }
        }
    }
    unset($buf);   // Reference-i sıfırla (vacib!)

    // ----------------------------------------------------------------
    // 11.2. Status-u diskə yaz (frontend bunu oxuyur)
    // ----------------------------------------------------------------
    // Hər 150ms-dən tez yazsaq disk I/O bahalıdır,
    // hər 1s yazsaq UI ləng görünür. 150ms balansı yaxşıdır.
    $now = microtime(true);
    if ($now - $lastWrite > 0.15) {
        $status['updated_at'] = date('c');
        write_status($jobId, $status);
        $lastWrite = $now;
    }

    // ----------------------------------------------------------------
    // 11.3. Proses bitibsə qalan məlumatı oxu və çıx
    // ----------------------------------------------------------------
    if (!$st['running']) {
        // Pipe-da hələ də oxunmamış məlumat ola bilər — onu da götür
        foreach ([$pipes[1], $pipes[2]] as $s) {
            $rest = @stream_get_contents($s);
            if ($rest !== false && $rest !== '') {
                if ($ytLog) {
                    fwrite($ytLog, $rest);
                    fflush($ytLog);
                }
                foreach (preg_split('/[\r\n]+/', $rest) as $line) {
                    if ($line !== '') parse_line($line, $status);
                }
            }
        }
        break;
    }

    // CPU-nu yandırmamaq üçün qısa fasilə (50ms)
    usleep(50000);
}

// --------------------------------------------------------------------
// 12. Resursları azad et
// --------------------------------------------------------------------
@fclose($pipes[1]);
@fclose($pipes[2]);
$exit = proc_close($proc);   // Prosesin exit code-unu al
if ($ytLog) fclose($ytLog);

// --------------------------------------------------------------------
// 13. Yekun status-u yaz
// --------------------------------------------------------------------
// Niyə fayl sayını da yoxlayırıq? yt-dlp bəzi videolarda xəta versə də
// (məsələn, tək biri yaş məhdudiyyətinə görə yüklənməsə), qalan videolar
// uğurla yüklənir və exit code 1 qayıdır. Lakin əslində iş "uğurlu"-dur
// çünki nə isə yükləndi. Buna görə fayl sayını da meyar kimi götürürük.
$out = count_output_files($dDir);
$success = ($exit === 0) || ($out['count'] > 0);

$status['state']       = $success ? 'done' : 'error';
$status['phase']       = $status['state'];
$status['percent']     = $success ? 100 : (float)($status['percent'] ?? 0);
$status['finished_at'] = date('c');
$status['updated_at']  = date('c');
$status['speed']       = '';   // Bitdiyi üçün sürət/ETA göstərməyə ehtiyac yoxdur
$status['eta']         = '';

if (!$success && empty($status['error'])) {
    $status['error'] = 'yt-dlp xəta ilə dayandı (kod: ' . $exit . ')';
}

write_status($jobId, $status);


/**
 * yt-dlp-nin çıxışından bir sətri parse edib status massivini yeniləyir.
 *
 * yt-dlp çıxışı bir neçə fərqli formatda gəlir:
 *   - [youtube] xxx: Downloading webpage   → məlumat çəkir
 *   - [download] Downloading item N of M    → playlist-də cari element
 *   - [download] Destination: /path/file    → yeni fayl başladı (başlıq buradan çıxarılır)
 *   - [download]   42.5% of 4.50MiB at ...  → progress yenilənməsi
 *   - [Merger]                              → ffmpeg birləşdirir
 *   - [ExtractAudio]                        → audio çıxarır (mp3-ə çevirir)
 *   - [EmbedThumbnail]                      → thumbnail əlavə edir
 *   - ERROR: ...                            → xəta
 *
 * Hər format üçün ayrıca qaydalar var. Sətir tapılmazsa, sakitcə ötürülür.
 *
 * @param string $line   Bir sətirlik yt-dlp çıxışı
 * @param array  $status Status massivi (reference ilə dəyişdirilir)
 */
function parse_line(string $line, array &$status): void {
    if ($line === '') return;

    // ----------------------------------------------------------------
    // [youtube] və ya [youtube:tab] — yt-dlp video/playlist məlumatı çəkir
    // ----------------------------------------------------------------
    if (preg_match('/^\[youtube(?::tab)?\]/i', $line)) {
        // Faza yalnız "fetching" və ya "queued"-dan dəyişir.
        // Yükləmə başladıqdan sonra yeni [youtube] sətrləri görsek belə
        // (playlist-də növbəti video üçün), faza "downloading" qalır.
        if (($status['phase'] ?? '') === 'fetching' || ($status['phase'] ?? '') === 'queued') {
            $status['phase'] = 'fetching';
        }
        return;
    }

    // ----------------------------------------------------------------
    // [download] Downloading item N of M — playlist-də cari element
    // ----------------------------------------------------------------
    if (preg_match('/Downloading item (\d+) of (\d+)/i', $line, $m)) {
        $cur   = (int)$m[1];
        $total = (int)$m[2];

        $status['current_item'] = $cur;
        $status['total_items']  = $total;

        // Vacib: Yeni element başlayanda percent-i 0-a sıfırlamırıq!
        // Cumulative base qoyuruq — yəni əgər 7-ci elementə keçiriksə
        // və 10 element var, percent 60% olur (6 element tamamlandı).
        // Sonra item-in öz progress-i bunun üzərinə əlavə olunacaq.
        $status['percent'] = $total > 0
            ? (($cur - 1) / $total) * 100.0
            : 0.0;

        $status['phase'] = 'downloading';

        // Sürət və ETA əvvəlki elementdən qalmasın
        $status['speed'] = '';
        $status['eta']   = '';
        return;
    }

    // ----------------------------------------------------------------
    // [download] Destination: <path> — yeni fayl yüklənir, başlığı al
    // ----------------------------------------------------------------
    if (preg_match('/^\[download\]\s+Destination:\s*(.+)$/i', $line, $m)) {
        $path = trim($m[1]);
        $name = basename($path);

        // "001 - " ön əkini çıxar (playlist nömrəsi)
        $name = preg_replace('/^\d+\s*-\s*/', '', $name);

        // Uzantını sil (.mp3, .webm və s.)
        $name = preg_replace('/\.[^.]+$/', '', $name);

        if ($name) $status['title'] = $name;

        $status['phase'] = 'downloading';
        return;
    }

    // ----------------------------------------------------------------
    // [Merger] / [ExtractAudio] / [ffmpeg] — ffmpeg-ə keçid (çevrilmə)
    // ----------------------------------------------------------------
    if (preg_match('/^\[Merger\]/i', $line)
        || preg_match('/^\[ExtractAudio\]/i', $line)
        || preg_match('/^\[ffmpeg\]/i', $line)) {
        $status['phase'] = 'converting';
        return;
    }

    // ----------------------------------------------------------------
    // [EmbedThumbnail] / [Metadata] — son addımlar (tag yazma və s.)
    // ----------------------------------------------------------------
    if (preg_match('/^\[EmbedThumbnail\]/i', $line)
        || preg_match('/^\[Metadata\]/i', $line)) {
        $status['phase'] = 'finalizing';
        return;
    }

    // ----------------------------------------------------------------
    // [download] X.X% of Y at Z ETA T — progress yenilənməsi
    // ----------------------------------------------------------------
    // Nümunə: [download]  42.5% of 4.50MiB at 1.20MiB/s ETA 00:14
    //
    // Regex qrupları:
    //   $m[1] — faiz (məsələn "42.5")
    //   $m[2] — fayl ölçüsü (məsələn "4.50MiB") — istifadə etmirik
    //   $m[3] — sürət (məsələn "1.20MiB/s")
    //   $m[4] — ETA (məsələn "00:14")
    if (preg_match(
        '/^\[download\]\s+([\d.]+)%(?:\s+of\s+~?\s*([^\s]+))?(?:\s+at\s+([^\s]+))?(?:\s+ETA\s+([^\s]+))?/i',
        $line,
        $m
    )) {
        $itemPct = (float)$m[1];

        // "Unknown" gələrsə əvvəlki dəyəri saxla — qiymətli məlumatın
        // üzərinə "Unknown" yazıb itirməyək
        if (!empty($m[3]) && $m[3] !== 'Unknown') $status['speed'] = $m[3];
        if (!empty($m[4]) && $m[4] !== 'Unknown') $status['eta']   = $m[4];

        // Cumulative percent hesablaması
        // Playlist üçün: hər element 100/total faiz təşkil edir.
        // Tək video üçün: itemPct birbaşa istifadə olunur.
        $total = (int)($status['total_items'] ?? 0);
        $cur   = (int)($status['current_item'] ?? 0);

        if ($total > 1 && $cur > 0) {
            $perItem = 100.0 / $total;            // Bir elementin payı
            $base    = ($cur - 1) * $perItem;     // Tamamlanmış elementlərin cəmi
            $status['percent'] = min(100.0, $base + ($itemPct * $perItem / 100.0));
        } else {
            $status['percent'] = $itemPct;
        }

        $status['phase'] = 'downloading';
        return;
    }

    // ----------------------------------------------------------------
    // ERROR: ... — yt-dlp xəta mesajı
    // ----------------------------------------------------------------
    if (preg_match('/^ERROR:/i', $line)) {
        $status['error'] = trim(substr($line, 6));
    }
}
