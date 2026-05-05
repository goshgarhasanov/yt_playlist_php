<?php
/**
 * ====================================================================
 * common.php — Bütün API endpoint-ləri üçün ümumi köməkçi funksiyalar
 * ====================================================================
 *
 * Bu fayl bütün api/ qovluğundakı PHP skriptlər tərəfindən require olunur.
 * Burada saxlanılır:
 *   - Qovluq yolları üçün sabitlər (ROOT_DIR, JOBS_DIR, DOWNLOADS_DIR)
 *   - JSON cavab köməkçisi (json_response)
 *   - Job ID validation və qovluq yolu funksiyaları
 *   - Status.json oxuma/yazma funksiyaları (atomic yazma!)
 *   - Konfiqurasiya oxuma
 *   - PID idarəetmə funksiyaları (proses canlıdırmı, prosesi öldür)
 *   - Yüklənmiş faylları sayma və ölçüsünü hesablama
 */

declare(strict_types=1);

// ROOT_DIR — layihənin kök qovluğu (api/ qovluğunun bir səviyyə yuxarısı)
const ROOT_DIR      = __DIR__ . '/..';

// JOBS_DIR — hər iş üçün metadata saxlanılan qovluq (config.json, status.json, log)
const JOBS_DIR      = ROOT_DIR . '/jobs';

// DOWNLOADS_DIR — yüklənmiş faylların saxlandığı kök qovluq
const DOWNLOADS_DIR = ROOT_DIR . '/downloads';


/**
 * JSON formatında HTTP cavab göndərir və skripti dayandırır.
 *
 * Niyə dayandırırıq? Çünki cavab göndərildikdən sonra heç bir başqa
 * çıxış (echo, print) baş verməməlidir. exit; sonrakı kodun işləməsinin
 * qarşısını alır.
 *
 * @param array $data  Cavabın məzmunu (associative array)
 * @param int   $code  HTTP status kodu (200 default, 400 bad request, və s.)
 */
function json_response(array $data, int $code = 200): void {
    // HTTP status kodunu təyin et
    http_response_code($code);

    // Content-Type başlığı: cavab JSON-dur, charset UTF-8
    header('Content-Type: application/json; charset=utf-8');

    // Brauzer cache etməsin — status real-time dəyişir
    header('Cache-Control: no-store');

    // JSON_UNESCAPED_UNICODE — Azərbaycan hərfləri (ə, ş, ç) düzgün yazılsın
    echo json_encode($data, JSON_UNESCAPED_UNICODE);

    // Dərhal dayandır — sonrakı kod işləməsin
    exit;
}


/**
 * Job ID-nin etibarlı format olub-olmadığını yoxlayır.
 *
 * Job ID — yalnız kiçik hərflər və rəqəmlərdən ibarət, 8-32 simvol.
 * Bu qayda path traversal hücumlarına qarşı əsas qoruyucumuzdur:
 * "../../../etc/passwd" kimi sətirlər regex-ə uyğun gəlmir.
 *
 * @param string $id Yoxlanılacaq ID
 * @return bool true = etibarlı, false = etibarsız
 */
function valid_job_id(string $id): bool {
    return (bool)preg_match('/^[a-z0-9]{8,32}$/', $id);
}


/**
 * Job ID-yə əsasən jobs/<id>/ qovluq yolunu qaytarır.
 *
 * Eyni zamanda ID-ni validate edir — etibarsızdırsa 400 cavabı ilə
 * skripti dayandırır. Bu defense-in-depth yanaşmasıdır:
 * hətta əsas validation-dən keçsə də, burada təkrar yoxlanır.
 *
 * @param string $jobId
 * @return string Tam qovluq yolu (məs: /path/to/jobs/abc123def456)
 */
function job_dir(string $jobId): string {
    if (!valid_job_id($jobId)) {
        // Şübhəli ID — dərhal 400 cavabı ilə skripti dayandır
        json_response(['ok' => false, 'error' => 'Yanlış job id'], 400);
    }
    return JOBS_DIR . '/' . $jobId;
}


/**
 * Job ID-yə əsasən downloads/<id>/ qovluq yolunu qaytarır.
 * job_dir() ilə eyni məntiq, sadəcə fərqli kök qovluq.
 *
 * @param string $jobId
 * @return string
 */
function download_dir(string $jobId): string {
    return DOWNLOADS_DIR . '/' . $jobId;
}


/**
 * status.json faylını oxuyur və massiv kimi qaytarır.
 *
 * Əgər fayl yoxdursa və ya korlanıbsa, ['state' => 'unknown']
 * default cavabını qaytarır — bu sayədə UI heç vaxt qırılmır.
 *
 * @param string $jobId
 * @return array Status massivi
 */
function read_status(string $jobId): array {
    $f = job_dir($jobId) . '/status.json';

    // Fayl yoxdursa — boş status qaytar
    if (!is_file($f)) return ['state' => 'unknown'];

    // @ — xətaları bas (icazə yoxdursa və s.)
    $raw = @file_get_contents($f);
    $data = json_decode($raw ?: '', true);

    // JSON parse uğursuzsa və ya massiv deyilsə — boş status
    return is_array($data) ? $data : ['state' => 'unknown'];
}


/**
 * status.json faylını ATOMIC şəkildə yazır.
 *
 * Niyə "atomic"? Worker prosesi status-u tez-tez yeniləyir, eyni vaxtda
 * status.php endpoint-i onu oxuyur. Əgər birbaşa file_put_contents
 * istifadə etsək, oxuma yarıda yazılmış (korlanmış) JSON görə bilər.
 *
 * Həll: əvvəlcə müvəqqəti fayla yaz, sonra rename ilə əvəz et.
 * rename() əməliyyatı OS səviyyəsində atomic-dir — ya tam baş verir,
 * ya da heç baş vermir. Heç vaxt yarımçıq vəziyyət olmur.
 *
 * @param string $jobId
 * @param array  $status Yazılacaq status massivi
 */
function write_status(string $jobId, array $status): void {
    $f = job_dir($jobId) . '/status.json';

    // Müvəqqəti fayl adı — təsadüfi suffix əlavə et ki,
    // paralel yazmalar bir-birini pozmasın.
    $tmp = $f . '.tmp.' . bin2hex(random_bytes(4));

    // JSON_PRETTY_PRINT — fayl insan oxuya bilən formatda (debug üçün)
    $payload = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    // JSON encoding uğursuz oldusa (məsələn invalid UTF-8) — sakitcə imtina et
    if ($payload === false) return;

    // Müvəqqəti fayla yazmaq uğursuzsa — imtina et
    if (@file_put_contents($tmp, $payload) === false) return;

    // Atomic rename — köhnə statusun yerinə yenisini qoy
    @rename($tmp, $f);
}


/**
 * config.json faylını oxuyur (URL, format, keyfiyyət saxlanılır).
 *
 * Status fərqli olaraq, config bir dəfə start.php-də yazılır və
 * sonra dəyişmir. Ona görə atomic yazmaya ehtiyac yoxdur.
 *
 * @param string $jobId
 * @return array Konfiqurasiya massivi (boş ola bilər)
 */
function read_config(string $jobId): array {
    $f = job_dir($jobId) . '/config.json';
    if (!is_file($f)) return [];
    $raw = @file_get_contents($f);
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : [];
}


/**
 * Yeni unikal job ID yaradır.
 *
 * 8 təsadüfi bayt → 16 hex simvol. Toqquşma ehtimalı astronomik
 * dərəcədə aşağıdır (2^64 fərqli kombinasiya).
 *
 * random_bytes() — kriptoqrafik baxımdan təhlükəsizdir,
 * rand()/mt_rand() əvəzində bunu istifadə edirik.
 *
 * @return string 16 simvollu hex string
 */
function new_job_id(): string {
    return bin2hex(random_bytes(8));
}


/**
 * Verilən PID-ə uyğun proses hələ də işləyirmi?
 *
 * Cancel funksionallığı üçün lazımdır — UI istifadəçiyə "Stop"
 * göstərib-göstərməməsi worker prosesinin canlı olub-olmamasından asılıdır.
 *
 * Windows və Unix-də fərqli yol istifadə edirik:
 *   - Windows: tasklist komandası
 *   - Unix: posix_kill(pid, 0) — siqnal göndərmir, sadəcə yoxlayır
 *
 * @param int $pid Yoxlanılacaq proses ID
 * @return bool true = işləyir, false = ya öldü ya da heç vaxt olmayıb
 */
function pid_running(int $pid): bool {
    // 0 və ya mənfi PID etibarsızdır
    if ($pid <= 0) return false;

    if (PHP_OS_FAMILY === 'Windows') {
        // tasklist /FI "PID eq 1234" /NH — verilmiş PID-li prosesi göstərir
        $out = @shell_exec(sprintf('tasklist /FI "PID eq %d" /NH 2>nul', $pid));
        // Cavabda PID görünürsə — proses canlıdır
        return is_string($out) && stripos($out, (string)$pid) !== false;
    }

    // Unix-də signal 0 — sadəcə proses var/yoxdur yoxlaması, heç nə etmir
    return posix_kill($pid, 0);
}


/**
 * Verilmiş PID və onun bütün uşaq proseslərini öldürür.
 *
 * worker.php yt-dlp-i çağırır, yt-dlp ffmpeg-i çağırır.
 * Sadəcə worker-i öldürsək, ffmpeg və yt-dlp arxa fonda qala bilər.
 * Buna görə bütün proses ağacını (/T) məcburi (/F) olaraq öldürürük.
 *
 * @param int $pid Öldürüləcək kök proses ID
 */
function kill_process_tree(int $pid): void {
    if ($pid <= 0) return;

    if (PHP_OS_FAMILY === 'Windows') {
        // /T — uşaq proseslərini də əhatə edir (tree)
        // /F — məcburi dayandırma (force)
        @shell_exec(sprintf('taskkill /PID %d /T /F 2>&1', $pid));
    } else {
        // Unix — SIGTERM (15) göndəririk. SIGKILL (9) daha sərt yoldur,
        // lakin əvvəlcə yumşaq dayandırma siqnalı veririk ki, proses
        // özünü düzgün bağlaya bilsin (ki, resurs sızıntısı baş verməsin).
        @posix_kill($pid, 15);
    }
}


/**
 * Yüklənmiş qovluqdakı tamamlanmış faylların sayını və ümumi ölçüsünü qaytarır.
 *
 * Vacib detal: aralıq fayllar (.part, .ytdl, .tmp) sayılmır.
 * Bunlar yt-dlp-nin yarımçıq endirdiyi və ya keçici fayllarıdır.
 * Yalnız tam yüklənmiş və hazır olan fayllar nəzərə alınır.
 *
 * @param string $dDir Endirmə qovluğunun tam yolu
 * @return array ['count' => int, 'size' => int (baytla)]
 */
function count_output_files(string $dDir): array {
    $count = 0;
    $size = 0;

    // Qovluq yoxdursa — sıfır
    if (!is_dir($dDir)) return ['count' => 0, 'size' => 0];

    // DirectoryIterator yaddaşa qənaət edir — scandir kimi bütün
    // siyahını birdən yaddaşa yükləmir, bir-bir gəzir.
    foreach (new DirectoryIterator($dDir) as $f) {
        // Yalnız adi fayllar — qovluqlar və link-lər nəzərə alınmır
        if (!$f->isFile()) continue;

        // Aralıq faylları nəzərə alma
        $ext = strtolower($f->getExtension());
        if (in_array($ext, ['part', 'ytdl', 'tmp'], true)) continue;

        $count++;
        $size += $f->getSize();
    }

    return ['count' => $count, 'size' => $size];
}
