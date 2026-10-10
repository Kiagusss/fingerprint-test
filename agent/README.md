# Agen Pemindai Sidik Jari

Agen ini **berjalan di PC tempat pemindai tercolok**, bukan di server. Server
hanya menerima laporan pendaftaran lewat HTTPS; sensornya tidak pernah ada di
server.

Yang agen ini lakukan: membaca jari, lalu melaporkan pendaftarannya. Yang TIDAK
dilakukannya: membuat sesi login. Sidik jari hanya menjawab "ini siapa";
password/passkey milik karyawan tetap yang membuktikan identitasnya. Rinciannya
di `docs/BIOMETRIC.md`.

## 1. Prasyarat

| Kebutuhan | Windows | Linux |
|---|---|---|
| Python | 3.9+ | 3.9+ |
| Pembacaan sensor | SDK vendor (mis. `CSAlgDll.dll` lewat helper sendiri) | libfprint yang mendukung chip pemindai |
| Untuk pemindai Chipsailing CS9711 (`2541:0236`) | DLL tertutup dari vendor | hanya lewat fork komunitas `libfprint-CS9711`; stok libfprint tidak mendukung chip ini |

Cek dulu apakah sistem Anda sudah mengenali sensornya:

    Linux   : lsusb | grep 2541:0236
    Windows : Device Manager, cari perangkat dengan VID 2541 PID 0236

## 2. Pasang

1. Salin berkas ini ke PC tersebut (mis. ke `~/.local/bin/`):

       agent/heloklin-biometric-agent.py

2. Buat perangkat di aplikasi: buka `https://login.heloklin.com/biometric-devices`
   (perlu akun admin) lalu panel **Daftarkan perangkat baru**. Secret tampil
   SEKALI — salin saat itu.

   Atau dari server, supaya secret langsung masuk berkas dan tidak pernah
   tercetak di layar:

       php artisan biometric:register-device "PC Resepsionis" --host=RESEPSI-01 --platform=linux --secret-out=/tmp/pc-resepsionis.env

3. Tulis konfigurasi di PC itu, lalu kunci izinnya:

       mkdir -p ~/.config/heloklin-biometric
       cat > ~/.config/heloklin-biometric/device.env <<'EOF'
       BIOMETRIC_DEVICE_UUID=<uuid dari langkah 2>
       BIOMETRIC_DEVICE_SECRET=<secret dari langkah 2>
       BIOMETRIC_BASE_URL=https://login.heloklin.com
       EOF
       chmod 600 ~/.config/heloklin-biometric/device.env

   Tulis nilai aslinya tanpa tanda `<` dan `>`. Tanda kurung siku itu hanya
   penanda di dokumen; kalau ikut disalin, bash membacanya sebagai pengalihan
   input dan gagal dengan pesan "No such file or directory".

## 3. Pakai

    python3 heloklin-biometric-agent.py doctor
    python3 heloklin-biometric-agent.py check
    python3 heloklin-biometric-agent.py enroll --username budi --finger right-index-finger

- `doctor` melaporkan OS, apakah pemindai terlihat, apakah jalur pembacaan jari
  tersedia, dan apakah server menerima kredensial perangkat.
- `check` melakukan heartbeat dan menampilkan pendaftaran yang akan diterima agen.
- `enroll` membaca jari lalu melaporkan pendaftarannya. Perintah ini akan
  meminta jari pada perangkat — inilah tahap prompt yang Anda tunggu.

`enroll` menerima pilihan cara membaca jari:

| `--capture` | Artinya |
|---|---|
| `auto` (default) | pakai fprintd bila ada, selain itu pakai `--capture-command` |
| `fprintd` | baca lewat `fprintd-enroll` (Linux, butuh libfprint yang mengenali pemindainya) |
| `command` | jalankan perintah Anda sendiri lewat `--capture-command`; exit 0 = jari berhasil dibaca. Boleh mencetak satu baris berisi template_ref ke stdout |
| `none` | tidak membaca apa pun; hanya untuk menguji jalur API (wajib sertakan `--template-ref`) |

Contoh untuk Windows dengan helper SDK vendor:

    python3 heloklin-biometric-agent.py enroll --username budi --finger right-index-finger ^
        --capture command --capture-command "C:\heloklin\capture.exe --finger right-index-finger"

## 4. Batas yang harus Anda tahu

**Enrolmen sudah bisa. Identifikasi 1:N belum.** Enrolmen tidak membutuhkan
identifikasi: admin sudah tahu karyawan mana yang sedang didaftarkan, dan
karyawan hanya perlu menempelkan jari. Yang membutuhkan 1:N adalah langkah login
"tempel jari, sistem tahu siapa" — itu menuntut fungsi identify/search di SDK
pemindai.

**fprintd hanya cocok untuk satu orang per akun Linux.** fprintd menyimpan
template per (akun Linux, nama jari) dan tidak bisa membedakan karyawan. Di PC
bersama yang memakai satu akun Linux, mendaftarkan karyawan kedua dengan nama
jari yang sama akan menimpa yang pertama. Karena itu `--username` selalu
diberikan oleh operator, bukan disimpulkan dari hasil pembacaan.

Jalur yang benar untuk PC bersama adalah memakai libfprint langsung dengan
penyimpanan template milik kita sendiri (`fp_device_enroll` → `FpPrint` →
penyimpanan sendiri → `fp_device_identify_start`). Itu sekaligus menyelesaikan
1:N. Belum dikerjakan karena menunggu pemindai Anda terbukti jalan dengan
driver komunitas tersebut.

**Skor mutu.** `fprintd` tidak melaporkan skor, jadi `enroll` mengirim
`quality=0` kecuali Anda memberi `--quality`. Angka 0 berarti "tidak ada skor
dari perangkat", bukan "mutu buruk" — kolom mutu di halaman admin akan
menampilkannya apa adanya supaya tidak ada angka karangan.

**Satu jari, satu baris.** Mendaftarkan jari yang sama dua kali ditolak server
kecuali memakai `--replace`, supaya pendaftaran lama tidak tertimpa diam-diam.
