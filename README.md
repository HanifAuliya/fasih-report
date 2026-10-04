# FASIH Report

Manajemen data FASIH: script (userscript Tampermonkey dll.), file report (Excel/JSON) dan progress per kecamatan.
Dibangun dengan Laravel 13 + Livewire 4 + Tailwind 4.

## Fitur

- **Manajemen Data (CRUD)** — tiap data punya kecamatan, script dan file sendiri. Data baru bisa langsung diisi 11 kecamatan default (`config/fasih.php`).
- **Progress per kecamatan** — target/realisasi diedit langsung di tabel, status otomatis (belum/proses/selesai).
- **Script** — syntax highlight, tombol *Copy Kode*, riwayat versi + restore, versi dibaca otomatis dari `// @version`.
- **URL raw publik** — `/raw/{slug-data}/{nama-file}` untuk `@updateURL` / `@downloadURL` Tampermonkey.
- **File report** — upload banyak file, kecamatan dideteksi otomatis dari nama file (`target_OSS_010_HARUYAN.xlsx` → Haruyan), preview Excel & JSON di browser.
- **Data target per kecamatan** — Excel target diimpor & ditampilkan seperti Excel (tab sheet, filter status, cari). Upload laporan JSON/CSV dari script → status tiap baris & progress kecamatan ter-update. Excel terbaru bisa di-download dan langsung dimuat lagi ke script (baris selesai `proses=0`, yang sudah dipindah `status_awal=dipindah`).

## Jalankan di lokal (Laragon)

```bash
composer install
npm install
cp .env.example .env        # sesuaikan DB_*
php artisan key:generate
php artisan migrate --seed  # akun: ADMIN_EMAIL / ADMIN_PASSWORD di .env
npm run dev                 # atau npm run build
```

Buka `http://fasih-report.test` (Laragon → Reload agar virtual host terbentuk) atau `php artisan serve`.

Test: `php artisan test` (pakai database MySQL `fasih_report_test`).

## Deploy otomatis ke shared hosting

Setiap `git push` ke branch `main`, GitHub Actions ([.github/workflows/deploy.yml](.github/workflows/deploy.yml)) akan:
menjalankan test → build (composer + vite) → **upload via FTP** → memanggil `POST /_deploy` (migrate + refresh cache).
Tidak butuh SSH, cocok untuk shared hosting Hostinger.

### Setup sekali di Hostinger (hPanel)

1. **Website → PHP Configuration**: pilih PHP **8.4+**, pastikan ekstensi `pdo_mysql`, `fileinfo`, `zip` aktif.
2. **Databases → MySQL Databases**: buat database + user, catat nama DB, user, password.
3. **Files → FTP Accounts**: catat host FTP, username, password. Cek di File Manager folder tujuan,
   mis. `public_html/` (domain utama) atau folder subdomain.
4. Lewat **File Manager**, buat file `.env` di folder tujuan tadi (salin dari `.env.example`), isi:
   `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://domainmu`, `APP_KEY` (hasil `php artisan key:generate --show` di laptop),
   `DB_*` (host biasanya `localhost`), `ADMIN_EMAIL` / `ADMIN_PASSWORD`, dan `DEPLOY_TOKEN` (string acak panjang).
5. Push ke GitHub. Deploy pertama upload `vendor/` sehingga agak lama; berikutnya hanya file yang berubah.
   Akun admin dibuat otomatis saat deploy pertama (jika tabel user masih kosong).

Seluruh aplikasi berada di folder tujuan; file [.htaccess](.htaccess) di root meneruskan semua request ke `public/`,
jadi `.env`, `vendor`, `storage` tidak bisa diakses dari browser.

### Secrets di GitHub (Settings → Secrets and variables → Actions)

| Secret | Contoh |
|---|---|
| `FTP_SERVER` | `ftp.domainmu.com` / IP dari hPanel |
| `FTP_USERNAME` | `u123456789` atau user FTP |
| `FTP_PASSWORD` | password FTP |
| `FTP_SERVER_DIR` | `public_html/` (akhiri dengan `/`) |
| `APP_URL` | `https://domainmu.com` (tanpa `/` di akhir) |
| `DEPLOY_TOKEN` | sama persis dengan `DEPLOY_TOKEN` di `.env` server |

## Script dari GitHub (selalu update)

Di tab **Script** → *Script baru* → **Ambil dari GitHub**, tempel link file-nya
(mis. `https://github.com/akun/repo/blob/main/fasih-ganti-wilayah-oss.user.js`). Kode di web lalu mengikuti file di repo:

1. **Webhook (langsung saat push)** — di repo GitHub: *Settings → Webhooks → Add webhook*
   - Payload URL: `https://domainmu/webhooks/github`
   - Content type: `application/json`
   - Secret: isi sama dengan `GITHUB_WEBHOOK_SECRET` di `.env` server
   - Event: *Just the push event*
2. **Cadangan tiap 10 menit** — hPanel → *Advanced → Cron Jobs*, tiap menit:
   `/usr/bin/php /home/USER/domains/DOMAIN/public_html/artisan schedule:run`
   (atau jalankan manual: `php artisan scripts:sync-github`).
3. Tombol **Sinkron sekarang** di halaman script, dan otomatis dicek saat script / URL install dibuka.

Repo privat: buat *fine-grained token* (izin **Contents: Read-only** untuk repo itu) lalu isi `GITHUB_TOKEN` di `.env`.

Tampermonkey: pasang lewat tombol **Install** di web. `@updateURL`/`@downloadURL` otomatis diarahkan ke web ini,
jadi semua pengguna ikut update setiap kali kamu push versi baru (`// @version` dinaikkan).

## Struktur penting

```
app/Livewire/Dashboard.php
app/Livewire/Projects/{Index,Show,KecamatanTable,KecamatanData,ScriptManager,FileManager}.php
app/Services/TargetImporter.php                ← Excel target → tabel target_sheets / target_rows
app/Services/StatusReportImporter.php          ← laporan JSON/CSV script → status baris (cocok via assignment_id)
app/Http/Controllers/TargetExportController.php ← download Excel + status terbaru (siap dimuat ulang ke script)
app/Http/Controllers/RawScriptController.php   ← URL raw script
config/fasih.php                               ← daftar kecamatan default, batas upload, token deploy
```
