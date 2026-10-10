# Hello Test — PoC Windows Hello / Passkeys + Isolasi Sesi Dua Akun Windows

Proof of Concept **terpisah** untuk menguji integrasi Windows Hello (WebAuthn/passkey) dengan
Laravel, dan untuk memverifikasi bahwa dua akun pengguna Windows di satu laptop (Windows A dan
Windows B, masing-masing memakai Chrome) mempertahankan **sesi Laravel yang terpisah**.

> Proyek ini **tidak terhubung** ke dashboard produksi. Database, kredensial, dan session store
> terpisah sepenuhnya.

---

## 1. Ringkasan Lingkungan (audit nyata)

| Komponen | Versi / Nilai | Catatan |
|---|---|---|
| OS | Debian 13 (trixie), kernel 6.12 | — |
| PHP | 8.4.23 (CLI) | ext `openssl`, `sodium`, `json`, `pdo_mysql` tersedia. Tanpa `gmp`/`bcmath` — tidak dibutuhkan. |
| Laravel | 13.35.0 | — |
| Composer | 2.10.2 | — |
| Node / npm | 20.19.2 / 9.2.0 | Tailwind v4 + Vite |
| Database | MySQL 8.4 di Docker, **port 3307** | Container `hello-test-mysql`, volume `hello-test-mysql-data` |
| TLS lokal | Caddy 2.11.7 + mkcert 1.4.4 | CA mkcert di trust store NSS user (tanpa sudo) |
| WebAuthn (klien) | `navigator.credentials` | Tanpa kripto buatan sendiri |
| WebAuthn (server) | `web-auth/webauthn-lib` 5.3.9 via `laravel/passkeys` 0.2.1 | Lihat §3 |

---

## 2. Keputusan Arsitektur & Isolasi

- **Database terpisah**: container MySQL sendiri (`docker-compose.yml`), port 3307, user & volume
  sendiri. Produksi (MySQL 3306) tidak disentuh sama sekali.
- **Host HTTPS**: `https://hello-test.localhost:8443`. Hostname `*.localhost` diresolusi Chrome
  tanpa perlu mengubah `/etc/hosts`; port 8443 dipakai karena 443 memerlukan sudo di lingkungan ini.
- **TLS**: klien → Caddy (`:8443`) → `php artisan serve` (`127.0.0.1:8099`). Laravel memakai
  `trustProxies('*')` + `URL::forceScheme('https')` sehingga cookie sesi ber-`Secure`.
- **Session**: driver `database` (tabel `sessions`), `SESSION_ENCRYPT=true`, cookie
  `Secure + HttpOnly + SameSite=Lax`. Sesi mengikuti cookie per profil browser — bukan per akun Windows.

---

## 3. Pemilihan Library WebAuthn (Tahap 2)

**Dipilih: [`laravel/passkeys`](https://github.com/laravel/passkeys-server) v0.2.1** — paket resmi
first-party Laravel.

| Kandidat | Putusan | Alasan |
|---|---|---|
| `laragear/webauthn` | **ditolak** | Composer menandainya **abandoned**; hanya mendukung Laravel 12/13. |
| `laravel/passkeys` | **dipilih** | Resmi, aktif, mendukung Laravel 11/12/13 & PHP ≥ 8.2. |
| `web-auth/webauthn-lib` | dipakai sebagai **fondasi** | Implementasi WebAuthn PHP referensi; validator dijalankan di server. |

**Keterbatasan yang diketahui:**
- Versi paket masih `0.x` (API dapat berubah). Untuk produksi, ikat versi dan uji saat upgrade.
- Hanya mendaftarkan attestation format `"none"` (tanpa verifikasi rantai sertifikat attestation
  perangkat keras). Ini normal untuk passkey konsumen dan sesuai PoC.
- `password.confirm` dipakai sebagai gate kelola passkey. Paket menyediakan endpoint konfirmasi
  berbasis passkey (`/passkeys/confirm`) yang dipakai UI untuk memenuhinya.

---

## 4. Skema Database

| Tabel | Isi | Catatan |
|---|---|---|
| `users` | `username`, `role` (`admin`/`supervisor`/`user`), `last_authenticated_at`, `last_auth_method` | + kolom stok Laravel |
| `passkeys` (paket) | `user_id`, `name`, `credential_id`, `credential` (JSON: public key, counter, AAGUID, transports), `last_used_at` | Tidak ada data biometrik/PIN/privat key |
| `test_logs` | event autentikasi non-sensitif + `credential_fingerprint` & `session_fingerprint` (sha256, 16 char) | **Tanpa** session id mentah, cookie, challenge, atau kredensial |
| `test_results` | katalog kasus uji + status `pass`/`fail`/`not_tested` + sumber `auto`/`manual` | Lihat §7 |

---

## 5. File yang Dibuat / Diubah

Setiap baris di bawah adalah file nyata di `~/Documents/hello-test`.

**Konfigurasi & lingkungan**
- `docker-compose.yml` — MySQL 8.4 terisolasi (port 3307, volume sendiri)
- `.env` — nilai lokal (berisi secret lokal, **tidak** di-commit)
- `.env.example` — **tanpa secret**; semua kredensial kosong
- `bootstrap/app.php` — trustProxies, alias middleware `role`, handler error WebAuthn → 422 generik
- `phpunit.xml` — env testing `force="true"` (isolasi SQLite in-memory)
- `Caddyfile` — reverse proxy TLS lokal untuk `hello-test.localhost:8443`
- `config/passkeys.php` — hasil publish (RP ID & allowed origins otomatis dari `APP_URL`)
- `app/Providers/AppServiceProvider.php` — `URL::forceScheme`, gate `Passkeys::authorizeLoginUsing`

**Backend**
- `app/Enums/Role.php`
- `app/Models/User.php` — implement `PasskeyUser`, trait `PasskeyAuthenticatable`
- `app/Models/TestLog.php`, `app/Models/TestResult.php`
- `app/Services/TestLogger.php` — allow-list event & field; fingerprint sha256
- `app/Services/Diagnostics.php` — snapshot lingkungan untuk halaman diagnostik
- `app/Services/AuthAttempt.php` — penanda request-scoped (password vs passkey)
- `app/Http/Controllers/LandingController.php`, `DashboardController.php`,
  `PasskeyManagementController.php`, `RoleTestController.php`,
  `TestResultsController.php`, `DocumentationController.php`, `LocaleController.php`
- `app/Http/Controllers/Auth/LoginController.php` — fallback password (setup & pemulihan)
- `app/Http/Middleware/EnsureRole.php`, `SetLocale.php`
- `app/Listeners/` — `RecordSuccessfulLogin`, `RecordFailedLogin`, `RecordLogout`,
  `RecordPasskeyRegistered`, `RecordPasskeyVerified`, `RecordPasskeyDeleted`
- `app/Support/TestCatalog.php` — katalog resmi 24 kasus uji
- `app/Console/Commands/SyncTestResultsCommand.php` — `test:sync-results`

**Migrasi**
- `2026_10_10_160000_add_username_role_to_users_table.php`
- `2026_10_10_160100_create_test_logs_table.php`
- `2026_10_10_160200_create_test_results_table.php`
- `2026_10_10_085130_create_passkeys_table.php` (dipublikasikan dari paket)

**Seeder & factory**
- `database/seeders/RolesAndUsersSeeder.php` — **idempoten**, password dari env, menolak jalan
  tanpa password eksplisit di luar local/testing
- `database/seeders/TestResultSeeder.php` — **idempoten**, tidak pernah menyentuh status hasil
- `database/factories/UserFactory.php`, `TestResultFactory.php`, `TestLogFactory.php`

**Frontend**
- `resources/css/app.css` — design system (token warna `ink-*`, komponen `card`/`btn`/`badge`/`table`)
- `resources/js/passkey.js` — klien WebAuthn (deteksi dukungan, registrasi, login, konfirmasi, revoke)
- `resources/js/app.js`
- `resources/views/layouts/app.blade.php`
- `resources/views/landing.blade.php`
- `resources/views/auth/login.blade.php`
- `resources/views/dashboard.blade.php`
- `resources/views/passkeys/index.blade.php`
- `resources/views/role-test.blade.php`
- `resources/views/test-results.blade.php`
- `resources/views/documentation.blade.php`
- `resources/views/partials/docs-nav.blade.php`, `partials/test-procedure.blade.php`

**Routes** — `routes/web.php` (12 route app + 8 route paket)

**Pengujian**
- `tests/Support/SoftwareAuthenticator.php` — **authenticator perangkat lunak nyata** (EC P-256,
  ES256, CBOR sendiri) yang menghasilkan payload WebAuthn asli; server memvalidasinya secara
  kriptografis. Bukan mock.
- `tests/TestCase.php` — helper ceremony
- `tests/Feature/PasskeyFlowTest.php`, `WebAuthnCeremonyTest.php`, `AuthenticationTest.php`,
  `AuthorizationTest.php`, `SessionIsolationTest.php`

---

## 6. Instalasi

```bash
cd ~/Documents/hello-test

# 1. dependency
composer install
npm install

# 2. database uji terisolasi
docker compose up -d          # MySQL 8.4 di 127.0.0.1:3307

# 3. konfigurasi
cp .env.example .env
php artisan key:generate
#   isi DB_PASSWORD, DB_ROOT_PASSWORD, PASSKEYS_USER_HANDLE_SECRET,
#   TEST_ADMIN_PASSWORD, TEST_SUPERVISOR_PASSWORD (>= 12 karakter, kuat)

# 4. skema + akun uji + katalog kasus uji
php artisan migrate --seed

# 5. asset
npm run build

# 6. jalankan (dua proses)
php artisan serve --host=127.0.0.1 --port=8099 &
caddy run --config Caddyfile &
```

### HTTPS lokal (mkcert)

```bash
# CA lokal + sertifikat (tanpa sudo bila CA dimasukkan ke trust store NSS user)
CAROOT="$HOME/.local/share/mkcert" mkcert -install || true
CAROOT="$HOME/.local/share/mkcert" mkcert \
  -key-file .certs/hello-test.key -cert-file .certs/hello-test.crt \
  hello-test.localhost localhost 127.0.0.1 ::1

# percayai CA untuk Chrome/Helium (per user, tanpa sudo)
certutil -d sql:$HOME/.pki/nssdb -A -t "C,," -n "mkcert development CA" \
  -i "$HOME/.local/share/mkcert/rootCA.pem"

# verifikasi
curl -s -o /dev/null -w "%{http_code}\n" --cacert ~/.local/share/mkcert/rootCA.pem \
  https://hello-test.localhost:8443/
```

### Deploy ke subdomain uji (mis. `hello-test.example.com`)

1. Arahkan DNS subdomain ke server; terbitkan sertifikat **nyata** (Let's Encrypt / Caddy otomatis).
2. Set `.env`:
   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://hello-test.example.com
   SESSION_SECURE_COOKIE=true
   SESSION_DOMAIN=null        # atau .example.com bila perlu berbagi cookie
   ```
3. `config/passkeys.php` otomatis memakai host `APP_URL` sebagai **RP ID** dan `APP_URL` sebagai
   **allowed origin** — pastikan keduanya tepat sama dengan yang dilaporkan browser.
4. Nginx/Caddy di depan PHP-FPM; pastikan meneruskan `X-Forwarded-Proto/Host/Port`.
5. `php artisan config:cache route:cache view:cache` lalu `npm run build`.
6. **Ganti** password akun uji (`TEST_*_PASSWORD`) dan `PASSKEYS_USER_HANDLE_SECRET` sebelum expose.

> ⚠️ Passkey **terikat pada RP ID**. Berganti hostname berarti passkey lama tidak valid lagi.

---

## 7. Uji Coba dari Windows A dan Windows B

1. Login ke **Windows A** → buka **Chrome pada profil Windows A**.
2. Buka `https://hello-test.localhost:8443` (di mesin Linux ini) atau subdomain uji Anda.
3. Masuk sebagai `test-admin` (username + password) — ini jalur setup awal.
4. Halaman **Passkey** → daftarkan passkey; konfirmasi Windows Hello.
5. Buka **Admin Test**, verifikasi `username`, `role server`, dan `session fingerprint`.
6. Logout, lalu uji **Masuk dengan Windows Hello** di halaman login.
7. Beralih ke **Windows B** → buka **Chrome pada profil Windows B**.
8. Masuk sebagai `test-supervisor`; daftarkan passkey-nya.
9. Buka **Supervisor Test**.
10. Kembali ke **Windows A** → dashboard masih `test-admin`, fingerprint sesi tidak berubah.
11. Kembali ke **Windows B** → dashboard masih `test-supervisor`.
12. Uji: logout, sesi kedaluwarsa, restart browser, pencabutan passkey.

> Pergantian akun Windows **tidak** otomatis menghapus/mengganti sesi Laravel. Sesi mengikuti cookie
> pada tiap profil browser.

---

## 8. Hasil Pengujian yang Benar-benar Dijalankan

Perintah: `php artisan test:sync-results` (menjalankan `vendor/bin/phpunit`, hasil JUnit XML
disinkronkan ke tabel `test_results`).

```
php artisan test  →  Tests: 47 passed (814 assertions)
test:sync-results →  Sync selesai: 15 PASS, 0 FAIL, 0 tanpa laporan
```

**15 kasus otomatis — PASS (dijalankan nyata):**

| Kasus | Bukti |
|---|---|
| Passkey tidak bisa menembus akun pemiliknya | `PasskeyFlowTest::test_passkey_is_bound_to_its_owner` |
| Challenge replay ditolak | `WebAuthnCeremonyTest::test_verification_options_cannot_be_replayed` |
| Submit tanpa challenge ditolak | `WebAuthnCeremonyTest::test_login_without_session_challenge_is_rejected` |
| Origin tidak valid ditolak | `WebAuthnCeremonyTest::test_invalid_origin_is_rejected` |
| RP ID tidak cocok ditolak | `WebAuthnCeremonyTest::test_rp_id_mismatch_is_rejected` |
| Dua sesi independen | `SessionIsolationTest::test_two_sessions_keep_separate_identities` |
| Logout menginvalidasi sesi | `SessionIsolationTest::test_logout_invalidates_the_session` |
| Akses tanpa autentikasi dialihkan | `AuthorizationTest::test_guests_are_redirected_to_login` |
| Akses lintas role ditolak (403) | `AuthorizationTest::test_cross_role_access_is_forbidden` |
| Role dari client diabaikan | `AuthorizationTest::test_client_supplied_role_is_ignored` |
| Registrasi passkey tanpa otorisasi ditolak | `AuthorizationTest::test_passkey_registration_requires_authentication` |
| CSRF ditegakkan (419) | `AuthorizationTest::test_post_without_csrf_token_is_rejected` |
| Rate limit login (429) | `AuthorizationTest::test_login_is_rate_limited` |
| Fallback password aman + session regenerate | `AuthenticationTest::test_password_login_succeeds_and_regenerates_session` |
| Pesan error tidak bocorkan user | `AuthenticationTest::test_login_failure_message_does_not_leak_user_existence` |

**Verifikasi HTTP nyata (HTTPS, bukan hanya unit test):**

| Uji | Hasil |
|---|---|
| `test-admin` → `/admin-test` | `200` |
| `test-admin` → `/supervisor-test` | `403` |
| `test-supervisor` → `/supervisor-test` | `200` |
| `test-supervisor` → `/admin-test` | `403` |
| `/user/passkeys/options` tanpa konfirmasi | `423` (password.confirm) |
| Cookie sesi | `Secure; HttpOnly; SameSite=Lax` |
| Redirect tamu | `https://hello-test.localhost:8443/login` |

**9 kasus manual — NOT TESTED** (jujur: memerlukan dua profil Windows nyata + authenticator Windows
Hello; belum dijalankan di mesin ini): `passkey_registration_win_a/b`, `passkey_login_win_a/b`,
`passkey_confirm_reauth`, `passkey_revoked_blocks_login`, `session_isolation_two_profiles`,
`session_persists_across_restart`, `account_switch_no_overwrite`. Isi formnya di halaman
**Hasil Pengujian** setelah benar-benar dijalankan. Baris ini **tidak** boleh di-PASS tanpa eksekusi.

---

## 9. Kontrol Keamanan

- Challenge acak 32 byte dibuat server, disimpan di session, diambil **sekali** (`pull`) → anti replay.
- Origin, RP ID (`rpIdHash`), credential ID, signature, dan counter diverifikasi di server oleh
  `web-auth/webauthn-lib`. Klien tidak pernah menentukan identitas/role.
- User verification `required`, resident key `required` (Windows Hello biometrik/PIN).
- Session ID di-regenerate setelah login; sesi di-invalidate saat logout.
- Role ditegakkan dari kolom `users.role` (middleware `EnsureRole`); query/header/body dari klien diabaikan.
- Rate limiting: `throttle:6,1` pada login password; `throttle:6,1` pada endpoint passkey.
- CSRF aktif pada semua POST/PATCH/DELETE.
- Pendaftaran & pencabutan passkey butuh konfirmasi ulang (`password.confirm` → UI memakai passkey).
- Kegagalan ceremony WebAuthn ditampilkan sebagai **422 generik** (tidak membocorkan alasan; alasan
  hanya masuk log tanpa materi kredensial).
- Log diagnostik: hanya fingerprint sha256; tidak ada privat key, biometrik, cookie, session id mentah.

---

## 10. Mode Identitas Windows

**Mode A — Passkey + sesi per profil Windows (DIDUKUNG).**
Identitas yang diketahui server adalah **akun aplikasi** (username + role). Isolasi datang dari
cookie/session store per profil browser. Ini yang diimplementasikan dan diuji.

**Mode B — Identitas Windows terintegrasi (TIDAK DIKLAIM).**
Mengetahui **nama akun Windows aktif** secara otomatis memerlukan Windows Integrated Authentication
(Negotiate/Kerberos atau NTLM) dengan Active Directory, atau Microsoft Entra ID. Ini **bukan** fitur
WebAuthn dan **tidak dapat** disimpulkan dari sebuah HTTP request biasa.

> Mode A **tidak** dapat mengetahui identitas Windows aktif secara kriptografis hanya dari request
> HTTP. Bila infrastruktur tidak mendukung IWA/AD/Entra ID, Mode B **tidak** diimplementasikan —
> tidak ada implementasi palsu. Aplikasi pendamping Windows (jika diperlukan) harus menjadi komponen
> terpisah dengan autentikasi kuat, **bukan** endpoint lokal yang mempercayai nama akun dari query
> string atau header buatan.

---

## 11. Keterbatasan yang Belum Terselesaikan

1. **Pengujian dua profil Windows nyata belum dijalankan** — butuh mesin Windows dengan dua akun.
   Karena itu 9 kasus tetap `NOT TESTED`.
2. **Port 443 tidak tersedia** (butuh sudo); dipakai `:8443` + `hello-test.localhost`.
3. **PHP CLI server** (`php artisan serve`) hanya untuk pengembangan. Produksi → PHP-FPM + web server.
4. **Attestation `"none"`** — tidak ada verifikasi perangkat keras (lihat §3).
5. `APP_DEBUG=true` di `.env` lokal — **wajib** `false` sebelum expose.
6. `laravel/passkeys` masih `0.x`.
7. Halaman **Hasil Pengujian** mengizinkan input manual — ini by design untuk penguji; status `auto`
   tidak dapat diubah dari UI.

---

## 12. Rollback / Penghapusan

Tidak menyentuh produksi sama sekali.

```bash
cd ~/Documents/hello-test

# 1. hentikan aplikasi & TLS
#    (Ctrl+C / kill proses: php artisan serve, caddy run)

# 2. hapus database uji beserta volumenya
docker compose down -v

# 3. hapus seluruh proyek
cd ~ && rm -rf ~/Documents/hello-test

# 4. opsional: hapus CA mkcert lokal (hati-hati bila dipakai proyek lain)
mkcert -uninstall

# 5. opsional: hapus CA dari trust store Chrome/Helium (per user)
certutil -d sql:$HOME/.pki/nssdb -D -n "mkcert development CA"
```

Verifikasi bahwa produksi tidak terpengaruh: `docker ps` hanya menyisakan container produksi
(`mysql-db`, `phpmyadmin`); port 3306 milik produksi tidak pernah dipakai oleh PoC ini.

---

## 13. Catatan Reproduksibilitas (jebakan yang ditemukan)

- **`php artisan test` vs `vendor/bin/phpunit`**: menjalankan suite sebagai *child process* dari
  Artisan mewariskan variabel `.env` induk ke environment, sehingga `<env>` di `phpunit.xml` (tanpa
  `force="true"`) **tidak** menang dan suite berjalan terhadap MySQL nyata dengan CSRF aktif.
  Perbaikannya: `force="true"` di `phpunit.xml` **dan** `Process::setEnv()` eksplisit di
  `SyncTestResultsCommand`. Karena itu `test:sync-results` memanggil `vendor/bin/phpunit` langsung.
- **Tailwind v4**: `@apply` tidak dapat memakai class komponen biasa; kelas dasar (`btn`, `badge`)
  dideklarasikan dengan `@utility`.
- **Caddy `{host}` membuang port** — gunakan `{hostport}` pada `X-Forwarded-Host` agar Laravel
  membangun URL absolut dengan `:8443`.
- **`config()` tidak boleh dipanggil di `bootstrap/app.php`** (container belum siap) — pindahkan ke
  `AppServiceProvider::boot()`.
- **Flake EC P-256 (~1/44 run)**: OpenSSL menghilangkan byte `0x00` paling depan saat mengembalikan
  koordinat EC. COSE key menuntut tepat 32 byte per koordinat, jadi tanpa padding library melempar
  `Invalid length for x/y coordinate` → 500. Diperbaiki dengan `str_pad(..., 32, "\x00", STR_PAD_LEFT)`
  di `SoftwareAuthenticator`, dengan unit test regresi (300 key per run). Sesudah perbaikan: **60 run
  berturut-turut, 0 gagal**.
- **Kredensial rusak dari klien**: CBOR/COSE yang cacat memunculkan `InvalidArgumentException`, bukan
  `WebauthnException`. Handler kedua menangkapnya — **hanya** pada rute `passkeys/*` dan
  `user/passkeys/*` — agar bug `InvalidArgumentException` lain di aplikasi tetap muncul sebagai error
  asli, bukan 422 palsu.
