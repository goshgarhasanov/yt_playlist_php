# YouTube Playlist Downloader (Core PHP)

Sadə, lakin tam işləyən YouTube playlist / video yükləyici. **Backend** sırf core PHP (heç bir framework yoxdur), **frontend** isə vanilla HTML / CSS / JavaScript-dir. Yükləmə işini [`yt-dlp`](https://github.com/yt-dlp/yt-dlp) və [`ffmpeg`](https://ffmpeg.org/) görür, PHP onları `proc_open` ilə idarə edir və real-time progress göstərir.

---

## Mündəricat

- [Xüsusiyyətlər](#xüsusiyyətlər)
- [Tələblər](#tələblər)
- [Quraşdırma](#quraşdırma)
- [İşə salmaq](#işə-salmaq)
- [İstifadə](#i̇stifadə)
- [Layihə quruluşu](#layihə-quruluşu)
- [Necə işləyir](#necə-i̇şləyir)
- [API endpoint-lər](#api-endpoint-lər)
- [Konfiqurasiya](#konfiqurasiya)
- [Təhlükəsizlik](#təhlükəsizlik)
- [Tez-tez verilən suallar](#tez-tez-verilən-suallar)
- [Lisenziya](#lisenziya)

---

## Xüsusiyyətlər

- ✅ **Playlist** və **tək video** yükləməsi (YouTube, YouTube Music)
- ✅ İki format seçimi:
  - **MP3** — audio çıxarışı, thumbnail (albom şəkli) + metadata
  - **MP4** — video, ən yaxşı video + audio birləşməsi
- ✅ Üç keyfiyyət səviyyəsi (ən yaxşı / orta / aşağı)
- ✅ **Real-time progress**:
  - Cari element / ümumi sayı (məsələn `3 / 12`)
  - Faiz (per-item və ümumi playlist üzrə)
  - Yükləmə sürəti və ETA
  - Açıla bilən log paneli (yt-dlp-in birbaşa çıxışı)
- ✅ Tamamlanan fayllar siyahısı və brauzerdən birbaşa endirmə
- ✅ Hər iş üçün ayrıca qovluq (`jobs/<id>` + `downloads/<id>`)
- ✅ URL validation (yalnız `youtube.com`, `youtu.be`, `music.youtube.com` qəbul olunur)
- ✅ Path traversal qoruması (download endpoint-də)
- ✅ Tək başına PHP CLI server ilə işləyir — Apache / Nginx tələb olunmur
- ✅ Tamamilə yerli işləyir, heç bir xarici servisə bağımlılıq yoxdur

---

## Tələblər

| Komponent | Versiya | Yoxlanılır |
|-----------|---------|------------|
| **PHP** | 8.1 və ya yuxarı | `php -v` |
| **yt-dlp** | son versiya | `yt-dlp --version` |
| **ffmpeg** | hər hansı stabil | `ffmpeg -version` |

Hər üç alət `PATH` mühit dəyişənində olmalıdır.

### Windows-da quraşdırma

```powershell
# PHP (winget ilə)
winget install PHP.PHP

# yt-dlp (Python varsa)
pip install -U yt-dlp

# ffmpeg
winget install Gyan.FFmpeg
```

### macOS / Linux

```bash
# Homebrew
brew install php yt-dlp ffmpeg

# Linux (Debian / Ubuntu)
sudo apt install php-cli ffmpeg
sudo curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o /usr/local/bin/yt-dlp
sudo chmod a+rx /usr/local/bin/yt-dlp
```

---

## Quraşdırma

```bash
git clone https://github.com/<istifadeci>/yt_playlist_php.git
cd yt_playlist_php
```

Heç bir `composer install`, `npm install` yoxdur — birbaşa işə sala bilərsiniz.

---

## İşə salmaq

### Windows

```bat
serve.bat
```

və ya əl ilə:

```bat
php -S localhost:8080
```

### macOS / Linux

```bash
php -S localhost:8080
```

Brauzerdə açın: <http://localhost:8080>

> **Qeyd:** PHP-nin built-in serveri tək thread-dədir, lakin yükləmə işi **fonda** ayrı CLI prosesdə (`worker.php`) işlədiyinə görə istifadəçi interfeysi blok olmur — eyni anda progress poll etmək və yeni iş başlatmaq mümkündür.

---

## İstifadə

1. Brauzerdə <http://localhost:8080> açın.
2. **Playlist / Video URL** sahəsinə YouTube linki yapışdırın:
   - Playlist: `https://www.youtube.com/playlist?list=PLxxxxx`
   - Video: `https://www.youtube.com/watch?v=xxxxx`
   - Qısa link: `https://youtu.be/xxxxx`
3. **Format** seçin: MP3 (audio) və ya MP4 (video).
4. **Keyfiyyət** seçin:
   - **Ən yaxşı** — MP3 üçün VBR ~245kbps, MP4 üçün ən yüksək mövcud video
   - **Orta** — MP3 ~192kbps, MP4 ən çox 720p
   - **Aşağı** — MP3 ~128kbps, MP4 ən çox 480p
5. **Yüklə** düyməsini basın.
6. Progress kartını izləyin. Tamamlandıqda **Yüklənmiş fayllar** kartında hər mahnı / video üçün **Yüklə** linki çıxacaq.

Bütün fayllar həmçinin yerli olaraq `downloads/<job_id>/` qovluğunda saxlanılır.

---

## Layihə quruluşu

```
yt_playlist_php/
├── index.html              # Əsas UI səhifəsi
├── assets/
│   ├── style.css           # Dark theme tərzlər
│   └── app.js              # Frontend məntiqi (fetch + polling)
├── api/
│   ├── common.php          # Köməkçi funksiyalar (json, validation, status I/O)
│   ├── start.php           # Yeni iş yaradır, worker-i fonda buraxır
│   ├── status.php          # İş statusunu JSON kimi qaytarır
│   └── files.php           # Faylları siyahılayır və endirir
├── worker.php              # CLI işçi: yt-dlp-i çağırır, status yeniləyir
├── jobs/                   # İş metadatası (hər iş ayrıca qovluqda)
│   └── <job_id>/
│       ├── config.json     # URL, format, keyfiyyət
│       ├── status.json     # Cari status (state, percent, speed, ETA)
│       └── log.txt         # yt-dlp-in tam çıxışı
├── downloads/              # Yüklənmiş fayllar
│   └── <job_id>/
│       ├── 001 - Mahnı 1.mp3
│       └── 002 - Mahnı 2.mp3
├── serve.bat               # Windows üçün tək kliklə işə salma
└── README.md
```

---

## Necə işləyir

```
┌──────────┐                       ┌─────────────┐
│ Brauzer  │                       │ PHP Server  │
│  (UI)    │                       │ (built-in)  │
└────┬─────┘                       └──────┬──────┘
     │                                    │
     │ 1. POST api/start.php              │
     ├───────────────────────────────────►│
     │                                    │
     │                                    │ 2. job_id yaradır
     │                                    │    config.json yazır
     │                                    │    worker.php-i fonda buraxır
     │                                    │    (start /B)
     │                                    │
     │ 3. {ok: true, job_id: "..."}       │
     │◄───────────────────────────────────┤
     │                                    │
     │                                    │
     │ 4. GET api/status.php?id=...       │      ┌────────────┐
     ├───────────────────────────────────►│      │ worker.php │
     │                                    │      │            │
     │                                    │ ◄────┤ proc_open  │
     │                                    │      │ ↓          │
     │                                    │      │ yt-dlp     │
     │ 5. {state, percent, speed, eta...} │      │ ↓          │
     │◄───────────────────────────────────┤      │ ffmpeg     │
     │                                    │      │ ↓          │
     │  (hər 1.2s-də təkrar...)           │      │ status.json│
     │                                    │      │ yenilənir  │
     │                                    │      └────────────┘
     │                                    │
     │ 6. state === "done"                │
     │ GET api/files.php?id=...           │
     ├───────────────────────────────────►│
     │ {files: [{name, size}, ...]}       │
     │◄───────────────────────────────────┤
     │                                    │
     │ 7. Endirmə linkləri ilə UI         │
     │                                    │
```

### Detallı axın

1. **Frontend** (`app.js`): formdan URL + format + keyfiyyət götürür, `FormData` kimi `api/start.php`-ə POST edir.

2. **`api/start.php`**:
   - URL-i yoxlayır (validation + yalnız YouTube domeni)
   - Təsadüfi `job_id` yaradır (`bin2hex(random_bytes(8))` — 16 hex simvol)
   - `jobs/<id>/config.json` yazır
   - Windows-da `start /B "" php worker.php <id>` ilə işçini fonda buraxır (PHP prosesi tamamlanır, worker davam edir)

3. **`worker.php`** (CLI):
   - `config.json`-ı oxuyur
   - `yt-dlp` üçün arqumentləri qurur:
     - MP3: `-x --audio-format mp3 --audio-quality 0 --embed-thumbnail`
     - MP4: `-f bestvideo+bestaudio/best --merge-output-format mp4`
   - `proc_open` ilə işə salır, stdout/stderr non-blocking modda oxuyur
   - Hər sətri parse edir:
     - `Downloading item N of M` → playlist progress
     - `[download] X.X% of Y at Z ETA T` → faiz / sürət / ETA
   - Hər ~500ms-də `status.json`-u yeniləyir

4. **`api/status.php`**: `status.json`-u oxuyur, log faylının son 8KB-ini əlavə edir, JSON qaytarır.

5. **Frontend polling**: hər 1.2s-də status soruşur, progress bar-ı və meta məlumatları yeniləyir. `state === "done"` olduqda dayandırır.

6. **`api/files.php`**:
   - `?id=<job>` — JSON siyahı (`.part`, `.ytdl`, `.tmp` faylları çıxarılır)
   - `?id=<job>&download=<file>` — faylı endirir (path traversal yoxlaması var)

---

## API endpoint-lər

### `POST /api/start.php`

İş başladır.

**Body** (form-data):
- `url` (zəruri) — YouTube link
- `format` — `mp3` (default) və ya `mp4`
- `quality` — `best` (default), `medium`, `low`

**Cavab**:
```json
{ "ok": true, "job_id": "a1b2c3d4e5f6a7b8" }
```

### `GET /api/status.php?id=<job_id>`

İşin cari statusunu qaytarır.

**Cavab**:
```json
{
  "ok": true,
  "state": "running",
  "percent": 42.5,
  "current_item": 3,
  "total_items": 12,
  "speed": "1.20MiB/s",
  "eta": "00:14",
  "log": "[download] 42.5% of 4.50MiB at 1.20MiB/s ETA 00:14\n..."
}
```

`state` dəyərləri: `queued`, `running`, `done`, `error`, `unknown`.

### `GET /api/files.php?id=<job_id>`

Yüklənmiş faylların siyahısını qaytarır.

**Cavab**:
```json
{
  "ok": true,
  "files": [
    { "name": "001 - Mahnı 1.mp3", "size": 4521234 },
    { "name": "002 - Mahnı 2.mp3", "size": 3987654 }
  ]
}
```

### `GET /api/files.php?id=<job_id>&download=<file_name>`

Faylı `application/octet-stream` kimi endirir.

---

## Konfiqurasiya

Hazırda konfiqurasiya birbaşa kodda sabitdir, lakin asanlıqla dəyişdirə bilərsiniz:

| Parametr | Yer | Default |
|----------|-----|---------|
| Port | `serve.bat` | `8080` |
| Çıxış adlandırma şablonu | `worker.php` | `%(playlist_index)03d - %(title)s.%(ext)s` |
| Polling intervalı | `assets/app.js` | `1200` ms |
| Status yeniləmə intervalı | `worker.php` | `0.5` s |
| Log tail ölçüsü | `api/status.php` | `8192` bayt |

---

## Təhlükəsizlik

- **URL validation**: `filter_var(..., FILTER_VALIDATE_URL)` + domain whitelist (yalnız YouTube)
- **Job ID format**: `[a-z0-9]{8,32}` regex ilə yoxlanılır — heç bir path traversal mümkün deyil
- **Download path**: `realpath()` ilə həqiqi yol, sonra base directory-yə uyğunluq yoxlanılır
- **Shell injection**: bütün arqumentlər array kimi qurulur və `proc_open`-a verilərkən düzgün escape olunur
- **Yalnız yerli istifadə üçün**: `php -S localhost` interfeysi yalnız `localhost`-a bind edir, xaricdən əlçatan deyil

> ⚠️ **Diqqət:** Bu layihə yerli istifadə üçün nəzərdə tutulub. İnternetə aşkar açacaqsanızsa, ən azı authentication, rate limiting və CSRF qoruması əlavə edin.

---

## Tez-tez verilən suallar

### `yt-dlp tapılmadı` xətası alıram

Terminalda `where yt-dlp` (Windows) və ya `which yt-dlp` (macOS/Linux) yoxlayın. Cavab boşdursa, `yt-dlp` `PATH`-da deyil. Yenidən quraşdırın və ya tam yolu `worker.php`-də sabit edin:

```php
$ytdlp = 'C:/path/to/yt-dlp.exe';
```

### MP3 yüklənmir, fayl `.webm` qalır

`ffmpeg` quraşdırılmayıb və ya `PATH`-da deyil. `yt-dlp` audio çıxarışı üçün `ffmpeg`-ə ehtiyac duyur.

### Progress bar `0%` qalır

- Brauzerdə Console-u açın (F12) — şəbəkə xətası varmı?
- `jobs/<id>/log.txt` faylını oxuyun — yt-dlp nə yazır?
- Bəzi videolar (yaş məhdudiyyəti, region kilidi) yüklənə bilməz, amma playlist-in qalanı davam edəcək (`--ignore-errors`).

### Çox böyük playlist üçün vaxt bitdi

PHP CLI server-də timeout yoxdur. Worker prosesi PHP web server-dən asılı deyil, ona görə uzun playlistlər problem deyil. Sadəcə brauzeri açıq saxlayın və ya bağlayıb sonra eyni `job_id` ilə yenidən status soruşun.

### Eyni anda neçə iş işlədə bilərəm?

Texniki olaraq limit yoxdur — hər iş ayrıca CLI prosesdir. Praktik olaraq internet sürəti və CPU (`ffmpeg` çevrilməsi) bottleneck olacaq.

### YouTube xaricindəki saytları dəstəkləyirsiniz?

`yt-dlp` 1000+ saytı dəstəkləyir, lakin bu UI-da `start.php`-də whitelist var. Başqa saytlar üçün `parse_url($url, PHP_URL_HOST)` yoxlamasını silə və ya genişləndirə bilərsiniz.

---

## Lisenziya

MIT — istədiyiniz kimi istifadə edin, dəyişdirin, paylaşın.

`yt-dlp` və `ffmpeg` öz lisenziyaları altındadır (Unlicense və LGPL/GPL müvafiq olaraq).
