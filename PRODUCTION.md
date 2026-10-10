# Deploy Produksi — login.heloklin.com

Runbook untuk instalasi produksi aplikasi ini di server Heloklin (aaPanel).
Semua nilai di bawah adalah nilai nyata di server; **tidak ada secret** di dokumen ini.

## 1. Topologi

| Komponen | Nilai nyata |
|---|---|
| Domain | `login.heloklin.com` (Cloudflare, proxied) |
| Origin | IP server Heloklin `139.190.99.175` |
| Web server | nginx (aaPanel), vhost `/www/server/panel/vhost/nginx/login.heloklin.com.conf` |
| Rewrite | `/www/server/panel/vhost/rewrite/login.heloklin.com.conf` (Laravel front controller) |
| PHP | PHP-FPM 8.4 (socket `/tmp/php-cgi-84.sock`), user `www` |
| Database | MariaDB 10.11 (port 3306) — DB `login_heloklin`, user `login_heloklin` |
| Docroot | `/www/wwwroot/login.heloklin.com/fingerprint-test/public` |
| Kode aplikasi | `/www/wwwroot/login.heloklin.com/fingerprint-test` (di luar docroot) |
| Sertifikat | `/www/server/panel/vhost/cert/login.heloklin.com/{fullchain,privkey}.pem` |
| Log aplikasi | `storage/logs/laravel.log`; akses nginx `/www/wwwlogs/login.heloklin.com.log` |

Kredensial (0600, hanya di server, tidak pernah di repo):

- `/home/Heloklin/login-heloklin-db-credentials.txt` — user + password database
- `/home/Heloklin/login-heloklin-account-credentials.txt` — password `test-admin` dan `test-supervisor`
- `.env` aplikasi (mode 640, `Heloklin:www`)

## 2. DNS & TLS

DNS yang dibutuhkan di Cloudflare (zone `heloklin.com`):

```
A  login  139.190.99.175   Proxied (orange cloud)
```

TLS diterbitkan dengan acme.sh (Let's Encrypt, ECDSA P-256) memakai HTTP-01
webroot ke docroot aplikasi:

```bash
sudo /root/.acme.sh/acme.sh --issue --webroot \
  /www/wwwroot/login.heloklin.com/fingerprint-test/public \
  -d login.heloklin.com --keylength ec-256 --server letsencrypt

sudo /root/.acme.sh/acme.sh --install-cert -d login.heloklin.com --ecc \
  --fullchain-file /www/server/panel/vhost/cert/login.heloklin.com/fullchain.pem \
  --key-file       /www/server/panel/vhost/cert/login.heloklin.com/privkey.pem \
  --reloadcmd      "/etc/init.d/nginx reload"
```

Perpanjangan otomatis sudah ditangani cron root
(`/root/.acme.sh/acme.sh --cron`). Port 80 pada vhost hanya dipakai untuk
verifikasi HTTP-01 dan untuk mengalihkan akses langsung ke origin (bypass
Cloudflare) ke https; permintaan yang datang dari edge Cloudflare diteruskan
apa adanya supaya tidak terjadi redirect loop bila mode TLS Cloudflare masih
`Flexible`.

## 3. Konfigurasi aplikasi (`.env`)

Nilai kunci yang dipakai produksi (lihat `.env.production.example`):

```
APP_ENV=production            APP_DEBUG=false
APP_URL=https://login.heloklin.com
SESSION_DRIVER=database       SESSION_DOMAIN=null      SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true          SESSION_HTTP_ONLY=true   SESSION_SAME_SITE=lax
CACHE_STORE=database          CACHE_PREFIX=login_heloklin
QUEUE_CONNECTION=sync         LOG_LEVEL=warning
PASSKEYS_USER_HANDLE_SECRET=<hex 64>   # merotasi nilai ini membatalkan semua passkey
```

`SESSION_DOMAIN=null` wajib: aplikasi lain di `*.heloklin.com` memakai nama
cookie yang berbeda, dan cookie host-only mencegah sesi tercampur.
`APP_NAME` menentukan nama cookie sesi (`heloklin-login-session`).

`config/passkeys.php` menurunkan **RP ID** dan **allowed origin** dari `APP_URL`.
Mengganti hostname berarti passkey lama tidak valid lagi.

Ganti `.env` → **selalu** `php artisan optimize` (atau `config:clear`), karena
config yang sudah ter-cache akan terus dipakai.

## 4. Urutan deploy

```bash
cd /www/wwwroot/login.heloklin.com/fingerprint-test
deploy/deploy.sh
```

Skrip tersebut menjalankan: `git pull --ff-only` → `composer install --no-dev
--optimize-autoloader` → `npm ci && npm run build` → `migrate --force` →
perbaikan permission (`Heloklin:www`, 775 pada `storage` + `bootstrap/cache`,
640 pada `.env`) → `php artisan optimize` → probe `/up` dan `/login` dari origin.

Seeding akun/katalog kasus uji (idempoten, hanya bila memang diperlukan):

```bash
php artisan db:seed --force
```

`RolesAndUsersSeeder` menolak membuat akun dengan password yang di-generate di
luar `local/testing`, sehingga `TEST_ADMIN_PASSWORD` dan
`TEST_SUPERVISOR_PASSWORD` wajib terisi di `.env` produksi.

## 5. Verifikasi setelah deploy

```bash
# dari server (origin, TLS dibuat sendiri oleh nginx)
curl -s -o /dev/null -w '%{http_code}\n' --resolve login.heloklin.com:443:127.0.0.1 https://login.heloklin.com/up
curl -s -o /dev/null -w '%{http_code}\n' --resolve login.heloklin.com:443:127.0.0.1 https://login.heloklin.com/login

# dari internet (lewat Cloudflare)
curl -sI https://login.heloklin.com/ | head -3
```

Hasil yang diharapkan: `/up` 200, `/login` 200, tamu yang membuka `/dashboard`
dialihkan ke `/login`, `/supervisor-test` sebagai `test-admin` menghasilkan 403,
dan `GET /user/passkeys/options` dengan `Accept: application/json` menghasilkan
423 (`password.confirm`).

## 6. Papan "Hasil Pengujian" dan sinkronisasi otomatis

`php artisan test:sync-results` menjalankan PHPUnit dan menuliskan PASS/FAIL
baris `auto` dari JUnit XML yang benar-benar dihasilkan suite. Perintah ini
membutuhkan dependency dev (`vendor/bin/phpunit`), sedangkan deploy produksi
memakai `--no-dev`. Untuk menyegarkan baris `auto` di produksi:

```bash
composer install                       # pasang dependency dev (sementara)
php artisan test:sync-results
composer install --no-dev --optimize-autoloader
```

Baris `manual` (9 kasus) hanya boleh diisi setelah benar-benar dijalankan dari
dua profil Windows nyata; jangan pernah menandainya PASS tanpa eksekusi.

## 7. Kontrol keamanan yang aktif

- Docroot hanya `public/`; `.env`, `vendor/`, `storage/` dan `.git` tidak dapat diakses lewat web.
- Cloudflare real IP (`CF-Connecting-IP`, hanya dari rentang IP Cloudflare) sehingga throttle dan log audit memakai IP pengunjung asli.
- `throttle:6,1` pada login password dan seluruh endpoint passkey; CSRF aktif di semua POST/PATCH/DELETE.
- Role ditegakkan dari kolom `users.role` (middleware `role`), bukan dari input klien.
- Header: `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`, `Permissions-Policy` (publickey-credentials dibatasi ke self), HSTS.
- `robots.txt` + `X-Robots-Tag` menolak indeksasi; detail versi software dan konfigurasi sesi di halaman beranda hanya tampil untuk pengguna yang sudah masuk.
- Registrasi/pencabutan passkey di belakang `password.confirm` (UI memakai verifikasi passkey).

## 8. Catatan & batasan yang diketahui

- **OPcache tidak aktif** di PHP 8.4 pada server ini (`opcache.so` tersedia tetapi belum ada `zend_extension`). Mengaktifkannya adalah perubahan PHP global yang memengaruhi semua situs, jadi belum dilakukan.
- **CSP belum dipasang** — view memakai `<script type="module">` inline sehingga perlu nonce.
- `laravel/passkeys` masih `0.x`; ikat versi dan uji saat upgrade.
- Attestation hanya `"none"` (tanpa verifikasi rantai sertifikat perangkat keras) — normal untuk passkey konsumen.
- Redis tidak dipakai; session/cache memakai database.
- `docker-compose.yml` dan `Caddyfile` hanya untuk PoC lokal, tidak dipakai dan tidak boleh dijalankan di server ini (docker tidak terpasang).

## 9. Rollback

```bash
cd /www/wwwroot/login.heloklin.com/fingerprint-test
git log --oneline -5
git checkout <commit-sebelumnya>
composer install --no-dev --optimize-autoloader
php artisan migrate:rollback --step=1   # hanya bila migrasi terakhir yang bermasalah
php artisan optimize
```

Menonaktifkan situs tanpa menyentuh aplikasi: `sudo rm /www/server/panel/vhost/nginx/login.heloklin.com.conf && sudo /etc/init.d/nginx reload`
(pulihkan dengan `git`/backup vhost; salinan asli disimpan di riwayat chat deploy).
