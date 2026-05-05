<?php
/**
 * ====================================================================
 * files.php — Yüklənmiş faylları siyahılayan və ya endirmə üçün verən endpoint
 * ====================================================================
 *
 * Bu endpoint iki rejimdə işləyir:
 *
 * 1. Siyahı rejimi (download parametri yoxdursa):
 *    GET /api/files.php?id=<job_id>
 *    Cavab: {"ok": true, "files": [{"name": "...", "size": ...}, ...]}
 *
 * 2. Endirmə rejimi (download parametri varsa):
 *    GET /api/files.php?id=<job_id>&download=<fayl_adı>
 *    Cavab: faylın özü (binary stream, attachment kimi)
 *
 * Təhlükəsizlik: bu endpoint path traversal hücumlarına qarşı bir
 * neçə qoruyucu mexanizmə malikdir:
 *   - basename() ilə yol komponentləri silinir
 *   - "." və ".." adları rədd edilir
 *   - realpath() ilə həqiqi yol əldə olunur
 *   - separator-anchored prefix yoxlaması (sibling qovluqlar mümkün deyil)
 */

require __DIR__ . '/common.php';

// --------------------------------------------------------------------
// 1. Job ID-ni al və yoxla
// --------------------------------------------------------------------
$jobId = $_GET['id'] ?? '';
if ($jobId === '') {
    json_response(['ok' => false, 'error' => 'id yoxdur'], 400);
}

$dDir = download_dir($jobId);
if (!is_dir($dDir)) {
    json_response(['ok' => false, 'error' => 'qovluq tapılmadı'], 404);
}

// ====================================================================
// REJİM 1: Fayl endirmə (download parametri verilibsə)
// ====================================================================
if (isset($_GET['download'])) {

    // ----------------------------------------------------------------
    // 2.1. Fayl adını təmizlə
    // ----------------------------------------------------------------
    // basename() — yol komponentlərini ("/", "\") silir.
    // "../../../etc/passwd" → "passwd"
    // "/var/www/secret"     → "secret"
    $name = basename((string)$_GET['download']);

    // Şübhəli adları rədd et: boş, "." (cari qovluq), ".." (yuxarı qovluq)
    if ($name === '' || $name === '.' || $name === '..') {
        http_response_code(400);
        exit('Yanlış fayl adı');
    }

    // ----------------------------------------------------------------
    // 2.2. Tam yolu hesabla və real yolu çıxar
    // ----------------------------------------------------------------
    $path = $dDir . DIRECTORY_SEPARATOR . $name;
    $real = realpath($path);   // Symlink-ləri açır, "../../" həll edir
    $base = realpath($dDir);

    // ----------------------------------------------------------------
    // 2.3. Path traversal qoruması — separator-anchored prefix yoxlaması
    // ----------------------------------------------------------------
    // Sadə "$real $base ilə başlayır" yoxlaması yetərli deyil!
    // Məsələn: əgər $base = "/a/b/c" və $real = "/a/b/c2/file"
    // olsa, sadə strpos === 0 yanlış pozitiv verər ("c2" "c" ilə başlayır).
    //
    // Düzgün üsul: separator daxil prefix yoxlaması:
    //   $real "/a/b/c/" ilə başlamalıdır (ayırıcı ilə)
    //
    // strncmp — verilmiş uzunluğa qədər iki string-i müqayisə edir.
    if ($real === false
        || $base === false
        || !is_file($real)
        || strncmp($real, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0
    ) {
        http_response_code(404);
        exit('Fayl tapılmadı');
    }

    // ----------------------------------------------------------------
    // 2.4. Content-Disposition başlığı üçün adlar hazırla
    // ----------------------------------------------------------------
    // Content-Disposition başlığı brauzerin faylı saxlama dialoqunu açır.
    //
    // Problem: əvvəlki standart yalnız ASCII simvollar dəstəkləyir.
    // Lakin yt-dlp Azərbaycan, türk, ərəb və s. simvollarla fayllar yarada bilir.
    //
    // Həll: RFC 5987 — iki ad veririk:
    //   1) ASCII fallback (köhnə brauzerlər üçün)
    //   2) UTF-8 percent-encoded (modern brauzerlər üçün)
    //
    // ASCII versiyası: latın hərflər, rəqəmlər, "._-" — qalanı "_" ilə əvəz olunur.
    $asciiName = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    if ($asciiName === '' || $asciiName === '.' || $asciiName === '..') {
        $asciiName = 'download.bin';
    }

    // ----------------------------------------------------------------
    // 2.5. Başlıqları göndər və faylı oxu
    // ----------------------------------------------------------------
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($real));

    // RFC 5987 dual filename:
    //   filename="..."     — ASCII fallback
    //   filename*=UTF-8'... — modern format, percent-encoded UTF-8
    header(
        'Content-Disposition: attachment; filename="' . $asciiName . '"; '
        . "filename*=UTF-8''" . rawurlencode($name)
    );

    // Brauzer faylı yanlış MIME tipi ilə şərh etməsin deyə (MIME sniffing qadağası)
    header('X-Content-Type-Options: nosniff');

    // Fayl məzmununu birbaşa cavaba ötür (yaddaşa yükləmir, böyük fayllar üçün uyğun)
    readfile($real);
    exit;
}

// ====================================================================
// REJİM 2: Fayl siyahısı
// ====================================================================
$files = [];

// downloads/<id>/ qovluğundakı bütün adi faylları topla
$it = new DirectoryIterator($dDir);
foreach ($it as $f) {
    if ($f->isDot() || !$f->isFile()) continue;

    // Aralıq fayllar (yt-dlp-nin yarımçıq işi) — siyahıda göstərmə
    $ext = strtolower($f->getExtension());
    if (in_array($ext, ['part', 'ytdl', 'tmp'], true)) continue;

    $files[] = [
        'name' => $f->getFilename(),
        'size' => $f->getSize(),
    ];
}

// Əlifba sırasıyla sırala — playlist nömrələri (001-, 002-, ...) sıralı çıxsın
usort($files, fn($a, $b) => strcmp($a['name'], $b['name']));

json_response(['ok' => true, 'files' => $files]);
