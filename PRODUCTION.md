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
- `/home/Heloklin/login-heloklin-account-credentials.txt` — password `admin` dan `test-supervisor`
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
APP_TIMEZONE=Asia/Jakarta              # jam yang ditulis & ditampilkan aplikasi
BOOTSTRAP_KEY=                         # kosong = /bootstrap gagal-tertutup
PASSKEY_BOOTSTRAP_NO_CONFIRM=false     # hanya berlaku bila APP_ENV=local
```

`SESSION_DOMAIN=null` wajib: aplikasi lain di `*.heloklin.com` memakai nama
cookie yang berbeda, dan cookie host-only mencegah sesi tercampur.
`APP_NAME` menentukan nama cookie sesi (`heloklin-login-session`).

### Zona waktu

`APP_TIMEZONE=Asia/Jakarta` (dibaca `config/app.php`) menentukan jam yang dipakai
aplikasi untuk menulis dan menampilkan waktu. Aplikasi ini pernah berjalan UTC,
sehingga kejadian pukul 00:28 WIB tersimpan sebagai 17:28 dan riwayat di
`/dashboard` terbaca 7 jam lebih awal.

Mengubahnya menuntut DUA langkah, karena nilai yang sudah tersimpan adalah jam
dinding UTC dan tidak ikut berubah sendiri:

```bash
bash /home/Heloklin/scripts/backup_login_db.sh   # snapshot dulu
php scripts/shift_timezone.php                   # laporan saja
php scripts/shift_timezone.php --apply           # geser +7 jam
```

Skrip itu menggeser setiap kolom `datetime`/`timestamp` yang terisi sebesar +7
jam; di server ini sudah dijalankan sekali pada 11 Okt 2026 (322 nilai, 21 kolom,
10 tabel). Sesudahnya jalankan `php artisan optimize` sebagai `www` dan muat
ulang PHP-FPM supaya zona baru dipakai proses web:

```bash
sudo -u www php artisan optimize
sudo /etc/init.d/php-fpm-84 reload      # unitnya tidak dikelola systemd di sini
```

Catatan: `php.ini` di server ini memakai zona default **UTC+8**, satu jam di
depan WIB. Laravel mengesampingkannya lewat `APP_TIMEZONE`, jadi aplikasi tidak
terpengaruh — tetapi skrip PHP di luar Laravel yang memakai `date()` harus
menyetel zonanya sendiri (seperti `backup_login_db.sh` yang sudah melakukannya).

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
2. Buka `https://login.heloklin.com/login`, masuk sebagai `admin`
   (password di `/home/Heloklin/login-heloklin-account-credentials.txt`), lalu
   buka `/passkeys` → **Tambah Windows Hello** → verifikasi Windows Hello admin
   di perangkat itu. Konfirmasi Windows Hello akan diminta sekali lagi untuk
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

### 4c. Prasyarat di perangkat klien (Windows Hello)

Aplikasi hanya bisa meminta "authenticator platform di perangkat ini". Apakah
permintaan itu berakhir di fingerprint Windows Hello atau turun ke pilihan lain
ditentukan oleh **OS**, bukan oleh situs. Bila OS tidak dapat memenuhi
permintaan, dialog **Windows Security** menampilkan "Handphone atau tablet" dan
"Security key" — persis gejala fingerprint tidak pernah muncul.

1. **Windows Hello harus aktif**, minimal PIN: Settings → Accounts → Sign-in
   options → Windows Hello PIN (dan Fingerprint bila perangkat punya sensor).
   Tanpa Windows Hello, Windows tidak punya tempat menyimpan passkey
   device-bound, sehingga hanya ponsel/security key yang ditawarkan.
2. **Windows 11 24H2+**: Settings → Privacy & security → **Passkey access** →
   izinkan browser yang dipakai (Chrome/Edge). Bila consent pernah ditolak,
   pendaftaran passkey gagal untuk aplikasi tersebut.
3. **Penyedia passkey Windows** — penyebab tersering gejala "hanya ditawari
   ponsel dan security key": Settings → Accounts → Passkeys → **Advanced
   options** → pastikan **"Simpan passkey ke perangkat Windows ini"** aktif.
   Toggle itu hanya muncul bila ada penyedia passkey pihak ketiga terpasang
   (1Password, Bitwarden, Microsoft/Google Password Manager). Bila mati,
   Windows Security berhenti menawarkan Windows Hello untuk passkey, dan yang
   tersisa hanya "ponsel/tablet" serta "security key" — walaupun login Windows
   dengan Windows Hello sendiri tetap berjalan normal. Setara lewat registry:
   `HKCU\Software\Microsoft\Passkeys` → `LocalToggleStatus` = 1 (0 = mati).
   Windows juga mengingat penyedia yang terakhir dipakai, jadi pernah memilih
   penyedia lain dapat membuatnya jadi default.
4. Pastikan Chrome/Edge cukup baru (field `hints` butuh versi 129+).
5. **Uji pemisah** (memastikan masalahnya di OS, bukan di aplikasi): buka
   `https://webauthn.io` di browser yang sama dan coba daftarkan credential.
   Bila opsi "Simpan passkey ke perangkat Windows ini" juga tidak muncul di
   sana, penyebabnya ada di Windows — kerjakan langkah 1-3 di atas.

Cek cepat dari sisi aplikasi: buka `https://login.heloklin.com/` dan lihat baris
**Platform authenticator (Windows Hello)**. Bila tertulis `TIDAK TERDETEKSI`,
OS di perangkat itu tidak dapat menyediakan Windows Hello — perbaiki langkah 1-2.

Batasan yang tidak bisa dipaksakan aplikasi:

- WebAuthn tidak menyediakan cara memilih **fingerprint** secara spesifik.
  Windows Hello yang menentukan apakah dialog meminta sidik jari, wajah, atau
  PIN; aplikasi hanya bisa meminta authenticator platform di perangkat ini.
- `hints: ['client-device']` (lihat `config/passkeys.php`) bersifat SARAN, dan
  Chrome mengabaikannya bila `authenticatorAttachment` diisi. Di Windows dialog
  passkey dimiliki OS, jadi pemilih "ponsel/security key" masih dapat muncul
  pada sebagian kombinasi versi Windows dan browser.
- Kebijakan yang dipasang server: `authenticatorAttachment: platform` (hanya
  authenticator bawaan perangkat diterima) + `hints: client-device`. Lihat
  `app/Passkeys/`.

### 4d. Masuk dengan sidik jari di PC bersama (satu langkah)

Sejak 11 Okt 2026 sidik jari dipakai sebagai **autentikasi**, bukan lagi sekadar
identifikasi. Alurnya, dan bagian mana yang dijalankan siapa:

```
agen di PC  : cocokkan jari (1:N)  ->  POST /api/biometric/login-tickets
server      : terbitkan tiket sekali pakai, umur 60 detik (hash-nya saja disimpan)
agen        : bukakan /fingerprint/login/<token> di browser PC itu
browser     : tukar tiket jadi sesi  ->  /dashboard
```

Yang perlu diketahui operator:

- Halaman penukaran tiket sengaja berada DI LUAR grup `guest`: di PC bersama,
  orang berikutnya yang menempelkan jari memang harus menggantikan sesi yang
  sedang terbuka.
- Pemakaian ulang, tiket kedaluwarsa, atau token yang salah dijawab dengan
  pengalihan ke `/login` dan satu baris `fingerprint_login_failed` di
  `/dashboard`; keberhasilan tercatat sebagai `fingerprint_login_success`.
- Password dan passkey TIDAK dihapus sebagai jalur masuk. Keduanya tetap ada
  untuk admin atau saat sensornya bermasalah.
- Risiko yang menyertai keputusan ini — terutama bahwa kepercayaan berpindah ke
  PC/agen itu — tertulis di `docs/BIOMETRIC.md` §2. Baca sebelum menambah PC.

Verifikasi sesudah deploy atau sesudah menyentuh jalur ini:

```bash
php artisan biometric:register-device "SMOKE TEST (uji asap)" --host=SMOKE \
    --platform=linux --secret-out=/tmp/smoke.env
python3 scripts/smoke_fingerprint_login.py --device-config=/tmp/smoke.env --username=admin
php artisan tinker --execute="App\Models\BiometricEnrollment::where('template_ref','like','tpl-smoke-%')->delete();"
php artisan tinker --execute="App\Models\BiometricDevice::where('name','SMOKE TEST (uji asap)')->delete();"
rm -f /tmp/smoke.env
```

Skrip itu membuat perangkat dan pendaftaran SEMENTARA, membuktikan rantai
`agen -> tiket -> sesi -> /dashboard` di server yang hidup, lalu meninggalkan
sisa yang dihapus oleh dua perintah sesudahnya. Ia tidak pernah memakai
`replace`, jadi jari asli pemilik akun tidak tersentuh.

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

Peringatan yang sama berlaku DI LUAR deploy, dan ini pernah membuat situs 500
di semua halaman ber-login:

- **Jangan menjalankan suite tes sebagai `Heloklin`.** `vendor/bin/phpunit`
  merender seluruh halaman, jadi ia menulis cache view. Dijalankan sebagai
  `Heloklin`, sisa cache-nya milik `Heloklin`, dan situs 500 begitu ada file view
  yang lebih baru — misalnya sesudah `git pull` atau `git rebase`, yang menulis
  ulang semua file view dengan mtime baru. Pakai
  `sudo -u www vendor/bin/phpunit`.
- Gejalanya di `storage/logs/laravel.log`:
  `production.ERROR: touch(): Utime failed: Operation not permitted` di
  `BladeCompiler.php`, berulang untuk setiap halaman yang dibuka.
- Pemulihannya:

  ```bash
  cd /www/wwwroot/login.heloklin.com/fingerprint-test
  sudo -u www php artisan view:clear
  sudo -u www php artisan view:cache
  sudo chown -R www:www storage/framework/views
  ```

  Cek hasilnya dengan `ls -l storage/framework/views/` — semua harus milik `www`.

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
`/supervisor-test` sebagai `admin` menghasilkan 403, `GET /user/passkeys/options`
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
- Kebijakan authenticator diatur aplikasi, bukan paket: `App\Passkeys\WindowsHelloRegistrationOptions` dan `WindowsHelloVerificationOptions` menggantikan action `laravel/passkeys` di container (lihat `AppServiceProvider`). Setel `PASSKEYS_AUTHENTICATOR_ATTACHMENT` / `PASSKEYS_HINTS` di `.env` bila kebijakan perlu dilonggarkan — mis. admin harus mendaftar dari security key USB. Prasyarat sisi klien ada di §4c.
- `hints` hanya dihormati bila browser cukup baru, dan di Windows dialog passkey dimiliki OS: pilihan "ponsel/security key" tidak dapat dihilangkan sepenuhnya dari sisi aplikasi.
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
