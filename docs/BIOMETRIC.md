# Sidik Jari: Identifikasi, Bukan Autentikasi

Dokumen ini adalah kontrak untuk **agen pemindai sidik jari** di PC bersama
(Windows/Linux) dan penjelasan batas keamanan lapisan ini. Baca bagian
"Batas keamanan" sebelum menulis kode agen.

## 1. Kenapa lapisan ini ada

Windows Hello tidak bisa dipakai untuk kasus ini, dan itu bukan soal konfigurasi:

- Container Windows Hello melekat pada **akun Windows**, bukan pada orang. Di PC
  bersama semua karyawan memakai akun Windows yang sama, sehingga Hello tidak
  pernah bisa menjawab "ini siapa" — ia hanya bisa mengonfirmasi "orang yang
  memegang akun Windows ini benar".
- Spesifikasi WebAuthn tidak memberi aplikasi pihak ketiga cara melakukan
  identifikasi biometrik (1:N).
- Sensor sidik jari bawaan PC modern tidak dapat dipakai aplikasi sendiri:
  Windows Biometric Framework tidak membuka identifikasi ke aplikasi pihak
  ketiga, dan sensor ber-ESS bahkan tidak didukung WinBio.

Karena itu agen memakai pemindai USB terpisah (match-on-host) dan server hanya
menyimpan hasil pendaftaran.

## 2. Model keamanan (baca ini dulu)

Lapisan ini **hanya mengidentifikasi**; ia tidak membuktikan identitas.

```
sidik jari  ->  "jari ini milik user X"   (diselesaikan agen, di PC)
password/passkey milik X                 ->  membuktikan "saya benar X"
```

Konsekuensinya, dan ini disengaja:

- Tidak ada endpoint di lapisan ini yang membuat sesi login. Setelah identifikasi
  berhasil, aplikasi tetap meminta password/passkey milik akun tersebut.
  (`BiometricEnrollmentTest::test_the_biometric_layer_never_creates_a_session`)
- Server tidak menyimpan kunci privat apa pun untuk lapisan ini, karena tidak ada
  yang perlu ditandatangani oleh agen. `secret` perangkat adalah rahasia bersama
  untuk membuktikan "request ini dari agen terdaftar", bukan untuk membuat sesi.
- Agen yang dirusak hanya bisa memalsukan **klaim username** — setara dengan
  seseorang mengetikkan username milik orang lain di form login. Ia tidak
  mendapatkan password orang itu.
- Karena itu agen TIDAK perlu menjadi benteng yang tahan terhadap karyawan yang
  memegang hak admin di PC itu. Yang tidak boleh dilakukan tetap tidak boleh:
  jangan biarkan sidik jari saja menghasilkan sesi login.

Kalau suatu saat sidik jari harus menjadi bukti identitas untuk kontrol akses,
hentikan dan rancang ulang: dengan PC yang semua karyawannya admin, pembuktian
harus dipegang sesuatu yang tidak dikuasai pengguna PC (mis. security key FIDO2
ber-sensor sidik jari per karyawan). Jangan menaikkan kepercayaan pada lapisan
ini tanpa itu.

## 3. Endpoint

Base: `https://login.heloklin.com/api/biometric`

| Metode | Path           | Fungsi                                              |
|--------|----------------|-----------------------------------------------------|
| GET    | `/heartbeat`   | Pastikan terdaftar; sinkronkan jam klien            |
| GET    | `/enrollments` | Daftar pendaftaran aktif, untuk penyimpanan lokal   |
| POST   | `/enrollments` | Laporkan satu jari yang baru selesai didaftarkan    |

Grup `api`: tanpa sesi, tanpa cookie, tanpa CSRF. Throttle default Laravel.

### 3.1 Tanda tangan setiap request

Agen memakai `secret` perangkat. Rahasia ini ditampilkan SEKALI saat perangkat
dibuat (`php artisan biometric:register-device` atau halaman admin), lalu tidak
dapat ditampilkan lagi. Simpan di sisi agen dengan proteksi OS (DPAPI pada
Windows, keyring/0600 pada Linux).

```
canonical = METHOD + "\n" + PATH + "\n" + TIMESTAMP + "\n" + sha256_hex(RAW_BODY)
signature = hex(hmac_sha256(secret, canonical))
```

- `METHOD` huruf besar: `GET` atau `POST`.
- `PATH` selalu dengan garis miring depan dan TANPA base URL:
  `/api/biometric/enrollments`. Jangan sertakan query string.
- `TIMESTAMP` detik Unix. Server menolak selisih lebih dari **300 detik**.
- `RAW_BODY` adalah **byte mentah** yang dikirim. POST: string JSON yang dikirim
  apa adanya. GET tanpa body: string kosong (`sha256("")`). Perhatikan:
  `sha256([])` dan `sha256("")` berbeda — tandatangani persis apa yang dikirim.

Header:

```
X-Device-Id:        <uuid perangkat>
X-Device-Timestamp: <detik unix>
X-Device-Signature: <hex signature>
Content-Type:       application/json   (hanya untuk POST)
```

Kegagalan menjawab `401 {"message":"Perangkat tidak diizinkan."}` untuk semua
sebab (perangkat tidak dikenal/nonaktif, jam melenceng, tanda tangan salah);
alasannya hanya masuk log server. Batas yang diketahui: jendela 300 detik berarti
request yang sama masih bisa diulang di dalam jendela itu.

### 3.2 `GET /heartbeat`

```json
{
  "ok": true,
  "device": { "uuid": "...", "name": "PC Resepsionis", "host_label": "RESEPSI-01", "platform": "windows" },
  "server_time": "2026-10-11T03:00:00+07:00",
  "enrolled_count": 42
}
```

Panggil saat agen start, lalu berkala. Server mencatat `last_seen_at` agar admin
bisa melihat perangkat mana yang masih hidup.

### 3.3 `GET /enrollments`

```json
{
  "enrollments": [
    {
      "id": 7,
      "user_id": 12,
      "username": "budi",
      "display_name": "Budi Santoso",
      "finger": "right-index-finger",
      "template_ref": "tpl-budi-1",
      "template_hash": "9f86d081884c7d659a2feaa0c55ad015...",
      "template": null,
      "quality": 72,
      "source_device_id": 3,
      "is_local": true
    }
  ]
}
```

- `template` = `null` berarti template hanya ada di PC tempat pendaftaran
  dilakukan (`template_ref` menunjuk template lokal itu). Ini mode bawaan untuk
  pemindai match-on-host seperti Chipsailing CS9711.
- Bila `template` terisi (base64), agen mana pun boleh menyimpannya untuk
  pencocokan lokal, sehingga pendaftaran cukup **sekali per karyawan** alih-alih
  sekali per karyawan x jumlah PC bersama.
- `is_local = true` berarti pendaftaran itu memang dilakukan di perangkat ini.

### 3.4 `POST /enrollments`

```json
{
  "username": "budi",
  "finger": "right-index-finger",
  "template_ref": "tpl-budi-1",
  "template": "<base64, opsional, maks 16 KB>",
  "quality": 72,
  "consent": true,
  "replace": false
}
```

- `finger` memakai nama libfprint: `right-index-finger`, `left-thumb`, dst. Huruf
  kecil, dipisah tanda hubung.
- `consent` WAJIB `true`. Data biometrik termasuk data pribadi bersifat spesifik
  (UU PDP 27/2022), jadi tidak ada baris yang dibuat tanpa persetujuan pemiliknya.
- `quality` 0-100 dari matcher. Simpan apa adanya; admin memakainya untuk menilai
  ulang.
- `replace: true` mencabut pendaftaran lama untuk (user, jari) yang sama lalu
  membuat yang baru. Tanpa itu, pendaftaran ulang ditolak `422` — supaya jari
  tidak tertimpa diam-diam.
- Balasan `201` berisi ringkasan pendaftaran. Kesalahan validasi: `422` dengan
  `errors` seperti validasi Laravel biasa.

### 3.5 `POST /identifications`

Laporan hasil pencocokan satu jari. **Murni catatan audit**: endpoint ini tidak
membuat sesi, token, maupun cookie.

```json
{
  "matched": true,
  "template_ref": "libfprint:agusbtw:budi:right-index-finger:1a2b3c4d5e6f7788",
  "quality": 0
}
```

- `matched: true` mewajibkan `template_ref` yang menunjuk pendaftaran **aktif**.
  Klaim agen tidak dipercaya begitu saja: server mencari sendiri pendaftaran itu,
  dan menjawab `422` bila tidak ada (termasuk bila sudah dicabut).
- `matched: false` juga dicatat. Percobaan jari yang tidak dikenal adalah
  peristiwa yang perlu terlihat, bukan yang perlu disembunyikan.
- Balasan `201` berisi `identification.username`, `finger`, dan `logged_at` —
  nama akun ditentukan server dari catatannya sendiri, bukan dari klaim agen.
- Setiap panggilan menulis satu baris `test_logs` dengan event
  `biometric_identified`, sehingga muncul di `/dashboard` tanpa perlu tabel baru.

## 4. Alur di PC bersama

1. **Enrolmen** (sekali per karyawan): admin memilih karyawan di agen, karyawan
   menempelkan jari beberapa kali sampai matcher menerima, agen menandatangani
   `POST /enrollments`. Server mencatat `template_ref`, `quality`, `consent_at`.
   Halaman `/biometric-devices` menampilkannya.
2. **Identifikasi**: karyawan menempelkan jari, agen mencocokkan 1:N secara lokal
   dan mendapat `username`, lalu halaman login memakai username itu untuk langkah
   berikutnya — password/passkey milik karyawan itu tetap diminta. Agen
   melaporkan hasilnya lewat `POST /identifications`, dan baris auditnya muncul
   di `/dashboard`. Pada agen ini langkah itu dijalankan perintah `identify`
   dengan penyimpanan template milik agen sendiri (lihat `agent/README.md` §3).
3. **Pencabutan**: admin mencabut di `/biometric-devices`; agen berhenti menerima
   pendaftaran itu pada sinkronisasi berikutnya.

## 5. Yang perlu dikonfirmasi sebelum agen ditulis

Perangkat yang sudah ada (Chipsailing CS9711, USB `2541:0236`) adalah
**match-on-host**: sensornya hanya mengalirkan gambar, seluruh pencocokan di
software. Yang harus dipastikan lebih dulu:

1. ~~**SDK mendukung 1:N**~~ — **sudah terjawab: ya.** Perangkat nyata melaporkan
   `supports_identify: True` dari libfprint (`probe` di `agent/README.md` §3),
   jadi 1:N bisa lewat libfprint langsung dengan penyimpanan template agen
   sendiri. Jalur fprintd tetap hanya 1:1/1:per-akun. Kalau nanti perangkat
   diganti, periksa ulang dengan `probe` sebelum menjanjikan alur ini.
2. **Dukungan per OS**. Windows: SDK vendor (DLL tertutup). Linux: fork komunitas
   `libfprint-CS9711` (matcher `sigfm`) — perlu aturan udev supaya agen non-root
   boleh membuka pemindainya (`agent/README.md` §4). **macOS tidak didukung.**
3. **Deteksi liveness.** Tanpa itu, cetakan atau gelatin dapat menipu pemindai.
4. **Mutu template.** Perangkat ini butuh 15 sentuhan per jari untuk enrol dan
   matcher-nya dioptimalkan untuk sensor kecil, jadi FAR/FRR untuk 1:N tidak
   bagus. Tolak kecocokan dengan skor rendah dan minta ulangi.

## 6. Data biometrik

- Template disimpan terenkripsi (`encrypted` cast memakai `APP_KEY`) dan tidak
  pernah masuk `test_logs` — yang dicatat hanya fingerprint non-reversibel dari
  `template_ref`.
- `biometric_enrollments.template` opsional. Untuk instalasi ini, mode
  per-perangkat lebih aman secara hukum (template tidak pernah meninggalkan PC);
  mode template terpusat menghemat enrolmen tetapi menaruh data biometrik di
  server dan harus benar-benar disetujui karyawan.
- Pencabutan tidak menghapus baris; ia mengisi `revoked_at` supaya riwayat tetap
  dapat diaudit. Hapus baris hanya bila memang ingin memusnahkan datanya.
