# Deploy Produksi — login.heloklin.com

Runbook untuk instalasi produksi aplikasi ini di server Heloklin (aaPanel).
Semua nilai di bawah adalah nilai nyata di server; **tidak ada secret** di dokumen ini.

Status per 10 Oktober 2026: situs sudah melayani HTTPS di `login.heloklin.com`.
Versi kode produksi = merge `fix all flow` (mode karyawan passwordless) dengan
commit hardening produksi; lihat §9 untuk cara kembali ke versi sebelumnya.

## 1. Topologi

| Komponen | Nilai nyata |
|---|---|
| Domain | `login.heloklin.com` (Cloudflare, **DNS only** — belum diproksikan) |
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

Record yang dipakai di Cloudflare (zone `heloklin.com`):

```
A  login  139.190.99.175   DNS only (grey cloud) saat ini
```

Blok `set_real_ip_from` + `real_ip_header CF-Connecting-IP` di vhost tetap
terpasang, sehingga bila record nanti diubah menjadi **Proxied** (orange cloud),
rate limit dan log audit langsung memakai IP pengunjung asli tanpa perubahan lain.
Selama DNS-only, IP pengunjung sudah asli (tidak ada proxy di depan origin).

Sertifikat TLS (terbit 10 Oktober 2026, berlaku sampai 8 Januari 2027) diterbitkan
dengan acme.sh memakai HTTP-01 webroot ke docroot aplikasi:

```bash
sudo /root/.acme.sh/acme.sh --issue --webroot \
  /www/wwwroot/login.heloklin.com/fingerprint-test/public \
  -d login.heloklin.com --keylength ec-256 --server letsencrypt

sudo /root/.acme.sh/acme.sh --install-cert -d login.heloklin.com --ecc \
  --fullchain-file /www/server/panel/vhost/cert/login.heloklin.com/fullchain.pem \
  --key-file       /www/server/panel/vhost/cert/login.heloklin.com/privkey.pem \
  --reloadcmd      "/etc/init.d/nginx reload"
```

Perpanjangan otomatis ditangani cron root (`/root/.acme.sh/acme.sh --cron`) dan
hook `--reloadcmd` di atas. Port 80 pada vhost hanya dipakai untuk verifikasi
HTTP-01 dan untuk mengalihkan akses langsung ke origin (bypass Cloudflare) ke
https:

```
http://login.heloklin.com/  ->  301 https://login.heloklin.com/
```

Permintaan yang datang dari edge Cloudflare diteruskan apa adanya supaya tidak
terjadi redirect loop bila mode TLS Cloudflare masih `Flexible`.

HSTS hanya dikirim oleh aplikasi (`App\Http\Middleware\SecurityHeaders`); vhost
sengaja tidak menambahkannya agar header tidak terkirim dua kali.

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
AUTH_ENABLE_PASSWORD_LOGIN=true        # MASA TRANSISI — lihat §4b
BOOTSTRAP_KEY=                         # kosong = /bootstrap gagal-tertutup
PASSKEY_BOOTSTRAP_NO_CONFIRM=false     # hanya berlaku bila APP_ENV=local
```

`SESSION_DOMAIN=null` wajib: aplikasi lain di `*.heloklin.com` memakai nama
cookie yang berbeda, dan cookie host-only mencegah sesi tercampur.
`APP_NAME` menentukan nama cookie sesi (`heloklin-login-session`).

`config/passkeys.php` menurunkan **RP ID** dan **allowed origin** dari `APP_URL`.
Mengganti hostname berarti passkey lama tidak valid lagi.

Ganti `.env` → **selalu** `php artisan optimize` (atau `config:clear`), karena
config yang sudah ter-cache akan terus dipakai.

## 4. Mode karyawan: login tanpa email & password (murni sidik jari)

Karyawan tidak pernah menerima password. Yang penting untuk operator:

**a. Karyawan masuk dengan passkey (discoverable credential).** Server tidak
meminta username; Chrome/Windows Hello yang memilih passkey, server baru tahu
pemiliknya setelah verifikasi WebAuthn. Karena itu tidak ada kolom username di
form login.

**b. Akun karyawan nol-kredensial.** `users.email` dan `users.password` NULL-able
(migrasi `2026_10_10_170000_make_users_email_and_password_nullable`). Karyawan
dibuat oleh admin dengan role `employee`, tanpa email dan tanpa password.

**c. Admin/IT mendaftarkan sidik jari karyawan di `/employees`.** Halaman ini
hanya untuk role `admin`. Alurnya: buat akun karyawan → tombol **Daftarkan sidik
jari** mengunci sesi ke karyawan itu → sidik jari karyawan ditempelkan di
perangkatnya → passkey tersimpan sebagai milik karyawan. Setiap tindakan yang
membuat/mengubah kredensial berada di belakang middleware `passkey.confirm`
(konfirmasi ulang dengan sidik jari admin sendiri).

**d. Bootstrap admin pertama (`/bootstrap`).** Dipakai hanya saat instalasi baru
tanpa satu pun user. Rute ini menutup diri sendiri (404) begitu ada user, dan di
luar environment `local` mensyaratkan `?key=` yang cocok dengan `BOOTSTRAP_KEY`
— kunci kosong berarti gagal-tertutup. Di server ini tabel `users` **tidak
kosong** (2 akun uji), jadi `/bootstrap` sudah 404 dan `BOOTSTRAP_KEY` sengaja
dibiarkan kosong.

**e. Jalur pemulihan.** `AUTH_ENABLE_PASSWORD_LOGIN` mengendalikan form
username/password di `/login`. Saat `false`, halaman login hanya menampilkan
tombol Windows Hello dan `POST /login` menjawab 404.

### 4b. Langkah go-live yang hanya bisa dilakukan dari perangkat nyata

Passkey hanya bisa didaftarkan dari perangkat dengan Windows Hello (atau
authenticator platform lain) pada origin **https** — tidak bisa diselesaikan
dari server. Urutan yang dipakai sekarang:

1. `.env` saat ini: `AUTH_ENABLE_PASSWORD_LOGIN=true` → akun uji masih bisa masuk
   sebagai jalur pemulihan, situs tetap dapat dikelola sebelum ada passkey.
2. Buka `https://login.heloklin.com/login`, masuk sebagai `test-admin`
   (password di `/home/Heloklin/login-heloklin-account-credentials.txt`), lalu
   buka `/passkeys` → **Tambah passkey** → tempelkan sidik jari admin di
   perangkat itu. Konfirmasi Windows Hello akan diminta sekali lagi untuk
   menyimpan.
3. Daftarkan karyawan di `/employees` dengan cara yang sama.
4. Setelah passkey admin ada, tutup jalur password:

   ```bash
   cd /www/wwwroot/login.heloklin.com/fingerprint-test
   sed -i 's/^AUTH_ENABLE_PASSWORD_LOGIN=true/AUTH_ENABLE_PASSWORD_LOGIN=false/' .env
   php artisan optimize
   ```

   Cek `https://login.heloklin.com/login` tidak lagi memuat input password.
   Cara kembali (darurat, bila semua passkey hilang): setel kembali `true`,
   `php artisan optimize`, masuk dengan akun uji, daftarkan passkey baru.

Bila suatu saat ingin instalasi benar-benar bersih (tanpa akun uji sama sekali),
kosongkan tabel `users` lalu setel `BOOTSTRAP_KEY=<acak>` dan buka
`https://login.heloklin.com/bootstrap?key=<acak>` dari perangkat karyawan+admin.

## 5. Urutan deploy

```bash
cd /www/wwwroot/login.heloklin.com/fingerprint-test
deploy/deploy.sh
```

Skrip tersebut menjalankan: `git pull --ff-only` → `composer install --no-dev
--optimize-autoloader` → `npm ci && npm run build` → `migrate --force` →
perbaikan permission (`Heloklin:www`, 775 pada `storage` + `bootstrap/cache`,
640 pada `.env`) → `php artisan optimize` **sebagai user web `www`** lalu
`chown -R www:www storage/framework/views` → probe `/up` dan `/login` dari origin.

Langkah cache dijalankan sebagai `www` karena alasan berikut: setiap view yang
lebih baru dari versi terkompilasinya membuat `BladeCompiler` memanggil
`touch()` pada file terkompilasi, dan `touch()` dengan mtime eksplisit hanya
boleh dilakukan pemilik file. Bila cache view dimiliki `Heloklin`, seluruh
halaman yang memakai view stale akan 500 dengan pesan
`touch(): Utime failed: Operation not permitted`. Jalankan juga `php artisan
view:cache` sebagai `sudo -u www` bila meng-compile cache manual di server.

Seeding akun/katalog kasus uji (idempoten, hanya bila memang diperlukan):

```bash
php artisan db:seed --force
```

`RolesAndUsersSeeder` menolak membuat akun dengan password yang di-generate di
luar `local/testing`, sehingga `TEST_ADMIN_PASSWORD` dan
`TEST_SUPERVISOR_PASSWORD` wajib terisi di `.env` produksi.

## 6. Verifikasi setelah deploy

```bash
# dari server (loopback) atau lewat internet — hasilnya harus "all checks passed"
deploy/verify.sh
deploy/verify.sh https://login.heloklin.com
```

Hasil yang diharapkan: `/up` 200, `/login` 200, tamu yang membuka `/dashboard`
dialihkan ke `/login`, `/bootstrap` 404 (instalasi sudah punya user),
`/supervisor-test` sebagai `test-admin` menghasilkan 403, `GET /user/passkeys/options`
dengan `Accept: application/json` menghasilkan 423 (`passkey.confirm`), dan
`http://` dialihkan 301 ke `https://`. Bila `AUTH_ENABLE_PASSWORD_LOGIN=true`,
skrip juga menguji login password asli (CSRF + cookie jar) dan halaman
`/dashboard`, `/passkeys`, `/employees`, `/test-results`, `/documentation`.

## 7. Papan "Hasil Pengujian" dan sinkronisasi otomatis

`php artisan test:sync-results` menjalankan PHPUnit dan menuliskan PASS/FAIL
baris `auto` dari JUnit XML yang benar-benar dihasilkan suite (baris `manual`
tidak pernah disentuh, dan baris yang tidak dilaporkan dikembalikan ke
NOT TESTED). Perintah ini membutuhkan dependency dev (`vendor/bin/phpunit`),
sedangkan deploy produksi memakai `--no-dev`. Untuk menyegarkan baris `auto` di
produksi:

```bash
composer install                       # pasang dependency dev (sementara)
php artisan test:sync-results
composer install --no-dev --optimize-autoloader
```

Baris `manual` (9 kasus) hanya boleh diisi setelah benar-benar dijalankan dari
perangkat Windows nyata; jangan pernah menandainya PASS tanpa eksekusi.

## 8. Kontrol keamanan yang aktif

- Docroot hanya `public/`; `.env`, `vendor/`, `storage/` dan `.git` tidak dapat diakses lewat web.
- Cloudflare real IP (`CF-Connecting-IP`, hanya dari rentang IP Cloudflare) sehingga throttle dan log audit memakai IP pengunjung asli.
- `throttle:6,1` pada login password dan seluruh endpoint passkey; CSRF aktif di semua POST/PATCH/DELETE.
- Role ditegakkan dari kolom `users.role` (middleware `role`), bukan dari input klien.
- Pendaftaran sidik jari: target selalu diambil dari database (`role=employee`), sesi dikunci ke satu karyawan, dan setiap tindakan kredensial di belakang `passkey.confirm` (bukan `password.confirm`, karena akun passwordless tidak punya password untuk dikonfirmasi).
- `/bootstrap` gagal-tertutup: hanya aktif tanpa user, dan mensyaratkan `BOOTSTRAP_KEY` di luar `local`.
- Jalur password adalah opsi pemulihan yang dimatikan (`AUTH_ENABLE_PASSWORD_LOGIN=false`) — selama masih `true`, form login password terbuka (di-throttle) dan harus dianggap sebagai masa transisi.
- Header: `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`, `Permissions-Policy` (publickey-credentials dibatasi ke self), HSTS.
- `robots.txt` + `X-Robots-Tag` menolak indeksasi; detail versi software dan konfigurasi sesi di halaman beranda hanya tampil untuk pengguna yang sudah masuk.

## 9. Catatan & batasan yang diketahui

- **Jangan ubah situs ini dari UI aaPanel** (tab Domain/SSL/Rewrite): panel akan menulis ulang vhost dan menimpa docroot `/public`, blok real-ip Cloudflare, dan pengalihan http→https. Terbitkan/perbarui sertifikat dengan cara acme.sh di §2.
- `/www/wwwroot/login.heloklin.com` (di luar docroot) masih memuat salinan repo aplikasi hasil pemulihan git sebelumnya; yang benar-benar disajikan adalah `fingerprint-test/public`. Jangan men-deploy dari direktori itu.
- **OPcache tidak aktif** di PHP 8.4 pada server ini (`opcache.so` tersedia tetapi belum ada `zend_extension`). Mengaktifkannya adalah perubahan PHP global yang memengaruhi semua situs, jadi belum dilakukan.
- **CSP belum dipasang** — view memakai `<script type="module">` inline sehingga perlu nonce.
- `laravel/passkeys` masih `0.x`; ikat versi dan uji saat upgrade.
- Attestation hanya `"none"` (tanpa verifikasi rantai sertifikat perangkat keras) — normal untuk passkey konsumen.
- Passkey yang tersinkron ke akun Microsoft karyawan (bukan device-bound) melemahkan model keamanan; konfigurasikan Windows Hello sebagai device-bound.
- Redis tidak dipakai; session/cache memakai database.
- `docker-compose.yml` dan `Caddyfile` hanya untuk PoC lokal, tidak dipakai dan tidak boleh dijalankan di server ini (docker tidak terpasang).

## 10. Rollback

```bash
cd /www/wwwroot/login.heloklin.com/fingerprint-test
git log --oneline -5
git checkout <commit-sebelumnya>
composer install --no-dev --optimize-autoloader
php artisan migrate:rollback --step=1   # hanya bila migrasi terakhir yang bermasalah
php artisan optimize
```

Titik kembali yang sudah disiapkan untuk merge mode karyawan:

- tag `prod-before-merge-20261010` — versi produksi sebelum merge (hardening, login password+passkey).
- cabang `prod-merge` — hasil merge yang sedang dipakai.
- dump DB sebelum migrasi: `/home/Heloklin/backup/login_heloklin_pre_merge_<tanggal>.sql`.

Menonaktifkan situs tanpa menyentuh aplikasi: `sudo rm /www/server/panel/vhost/nginx/login.heloklin.com.conf && sudo /etc/init.d/nginx reload`
(pulihkan dengan salinan vhost; salinan asli disimpan di riwayat chat deploy).
