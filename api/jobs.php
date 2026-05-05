<?php
/**
 * ====================================================================
 * jobs.php — Bütün işlərin siyahısını qaytaran endpoint (tarixçə)
 * ====================================================================
 *
 * UI-nin "Tarixçə" görünüşü bu endpoint-dən bütün keçmiş və aktiv
 * işlərin siyahısını alır. Hər iş üçün başlıq, format, vəziyyət,
 * fayl sayı və ölçüsü, tarix qaytarılır.
 *
 * Sıralama: ən yeni iş əvvəldə (started_at sahəsinə görə).
 *
 * URL: GET /api/jobs.php
 *
 * Cavab:
 *   {
 *     "ok": true,
 *     "jobs": [
 *       {
 *         "job_id": "...", "state": "done", "title": "...",
 *         "format": "mp3", "files_count": 12, "files_size": 45678901,
 *         "started_at": "2026-05-05T...", "finished_at": "..."
 *       }
 *     ]
 *   }
 */

require __DIR__ . '/common.php';

$jobs = [];

// --------------------------------------------------------------------
// 1. jobs/ qovluğundakı bütün alt-qovluqları skanla
// --------------------------------------------------------------------
// Hər alt-qovluq bir işə uyğundur (ad = job_id).
if (is_dir(JOBS_DIR)) {

    foreach (new DirectoryIterator(JOBS_DIR) as $entry) {
        // "." və ".." xüsusi girişlərini (cari və yuxarı qovluq) ötür
        if ($entry->isDot() || !$entry->isDir()) continue;

        $id = $entry->getFilename();

        // Etibarsız və ya zədələnmiş qovluq adlarını süz
        // (məsələn, kimsə əl ilə "test" adlı qovluq yaradıbsa)
        if (!valid_job_id($id)) continue;

        // ----------------------------------------------------------------
        // Hər iş üçün config, status və fayl statistikası al
        // ----------------------------------------------------------------
        $cfg = read_config($id);
        $st  = read_status($id);
        $out = count_output_files(download_dir($id));

        $jobs[] = [
            'job_id'      => $id,
            'state'       => $st['state']   ?? 'unknown',
            'phase'       => $st['phase']   ?? '',
            'percent'     => (float)($st['percent'] ?? 0),
            'title'       => $st['title']   ?? '',
            'url'         => $cfg['url']     ?? '',
            'format'      => $cfg['format']  ?? '',
            'quality'     => $cfg['quality'] ?? '',
            'started_at'  => $st['started_at']  ?? null,
            'finished_at' => $st['finished_at'] ?? null,
            'files_count' => $out['count'],
            'files_size'  => $out['size'],
        ];
    }
}

// --------------------------------------------------------------------
// 2. Tarixə görə sıralama (ən yeni əvvəldə)
// --------------------------------------------------------------------
// strcmp ISO 8601 tarixləri üçün düzgün işləyir, çünki bu format
// leksikoqrafik müqayisədə tarix sırasını qoruyur:
//   "2026-05-05T07:00" < "2026-05-05T08:00"
// Tərs sıralama üçün $b və $a yerlərini dəyişdik (DESC).
usort($jobs, function($a, $b) {
    return strcmp($b['started_at'] ?? '', $a['started_at'] ?? '');
});

// --------------------------------------------------------------------
// 3. Cavabı qaytar
// --------------------------------------------------------------------
json_response(['ok' => true, 'jobs' => $jobs]);
