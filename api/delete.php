<?php
/**
 * ====================================================================
 * delete.php — İşi və bütün fayllarını silən endpoint
 * ====================================================================
 *
 * İstifadəçi tarixçədə hər hansı bir işin yanındakı zibil qutusu
 * ikonuna basanda buraya POST göndərilir. Endpoint:
 *   1. İş hələ də işləyirsə, prosesi öldürür
 *   2. jobs/<id>/ qovluğunu silir (config, status, log)
 *   3. downloads/<id>/ qovluğunu silir (yüklənmiş fayllar)
 *
 * URL: POST /api/delete.php
 * Body: id=<job_id>
 */

require __DIR__ . '/common.php';

// --------------------------------------------------------------------
// 1. Metod və parametr yoxlaması
// --------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST tələb olunur'], 405);
}

$jobId = $_POST['id'] ?? '';
if ($jobId === '') {
    json_response(['ok' => false, 'error' => 'id yoxdur'], 400);
}

// --------------------------------------------------------------------
// 2. Qovluq yollarını hesabla (job_dir ID-ni validate edir)
// --------------------------------------------------------------------
$jDir = job_dir($jobId);
$dDir = download_dir($jobId);

// --------------------------------------------------------------------
// 3. İş hələ aktivdirsə, əvvəlcə dayandır
// --------------------------------------------------------------------
// Aktiv proses faylları açıq saxlayır — OS onları kilidləyə və
// silinməsinə imkan verməyə bilər. Buna görə əvvəlcə prosesi öldürürük,
// 1 saniyə gözləyirik (kilidlər açılsın deyə), sonra silirik.
$status = read_status($jobId);
$pid    = (int)($status['pid'] ?? 0);

if ($pid > 0 && pid_running($pid)) {
    kill_process_tree($pid);
    sleep(1);   // Faylların kilidi açılsın deyə qısa gözləmə
}

// --------------------------------------------------------------------
// 4. Qovluqları rekursiv sil
// --------------------------------------------------------------------
rrmdir($jDir);
rrmdir($dDir);

json_response(['ok' => true]);


/**
 * Qovluğu və onun bütün məzmununu rekursiv olaraq silir.
 *
 * Niyə standart funksiya yoxdur? PHP-də built-in rmdir yalnız boş
 * qovluqları silir. Dolu qovluq üçün əvvəlcə bütün faylları silmək
 * lazımdır.
 *
 * Diqqət: symlink-ləri izləmir (DirectoryIterator default davranışı).
 * Bu təhlükəsizlik baxımından vacibdir — kimsə symlink yarada və
 * sistem fayllarına yönəltə bilər.
 *
 * @param string $dir Silinəcək qovluğun yolu
 */
function rrmdir(string $dir): void {
    if (!is_dir($dir)) return;

    foreach (new DirectoryIterator($dir) as $f) {
        // "." və ".." xüsusi girişlərini ötür
        if ($f->isDot()) continue;

        $p = $f->getPathname();

        if ($f->isDir()) {
            // Alt-qovluq — özünə rekursiv çağırış
            rrmdir($p);
        } else {
            // Fayl — birbaşa sil
            @unlink($p);
        }
    }

    // Sonda qovluğun özünü sil (artıq boşdur)
    @rmdir($dir);
}
