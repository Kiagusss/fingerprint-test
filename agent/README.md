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
    python3 heloklin-biometric-agent.py probe
    python3 heloklin-biometric-agent.py check
    python3 heloklin-biometric-agent.py enroll --username budi --finger right-index-finger --capture libfprint
    python3 heloklin-biometric-agent.py identify
    python3 heloklin-biometric-agent.py login

- `doctor` melaporkan OS, apakah pemindai terlihat, apakah jalur pembacaan jari
  tersedia, dan apakah server menerima kredensial perangkat.
- `probe` melaporkan kesiapan libfprint: kemampuan perangkat (`supports_identify`
  yang menentukan hidup-matinya 1:N) dan tanda tangan fungsi yang dipakai agen.
  Tidak menyentuh jari sama sekali.
- `check` melakukan heartbeat dan menampilkan pendaftaran yang akan diterima agen.
- `enroll` membaca jari lalu melaporkan pendaftarannya. Perintah ini akan
  meminta jari pada perangkat — inilah tahap prompt yang Anda tunggu.
- `identify` mencocokkan satu jari dengan SEMUA template di penyimpanan lokal
  (1:N), lalu melaporkan hasilnya ke server. Ia hanya MENCATAT; ia tidak membuka
  sesi.
- `login` melakukan pencocokan yang sama, lalu meminta tiket masuk sekali pakai
  (60 detik) dan membukakan alamatnya di browser PC itu. Inilah perintah yang
  membuat jari benar-benar dipakai untuk MASUK. Pakai `--no-open` bila Anda hanya
  ingin mencetak alamatnya (mis. saat menguji lewat SSH).

`enroll` menerima pilihan cara membaca jari:

| `--capture` | Artinya |
|---|---|
| `auto` (default) | pakai fprintd bila ada, selain itu pakai `--capture-command` |
| `fprintd` | baca lewat `fprintd-enroll` (Linux). Pendaftarannya TIDAK bisa dipakai untuk identifikasi |
| `libfprint` | libfprint langsung, template disimpan di penyimpanan agen sendiri. Inilah jalur yang bisa identifikasi 1:N |
| `command` | jalankan perintah Anda sendiri lewat `--capture-command`; exit 0 = jari berhasil dibaca. Boleh mencetak satu baris berisi template_ref ke stdout |
| `none` | tidak membaca apa pun; hanya untuk menguji jalur API (wajib sertakan `--template-ref`) |

Template dari `--capture libfprint` disimpan di
`~/.config/heloklin-biometric/prints/` (direktori 0700, setiap berkas 0600,
ditambah `index.json` yang memetakan template ke orang dan jari).

Contoh untuk Windows dengan helper SDK vendor:

    python3 heloklin-biometric-agent.py enroll --username budi --finger right-index-finger ^
        --capture command --capture-command "C:\heloklin\capture.exe --finger right-index-finger"

## 4. Akses USB di Linux — wajib untuk jalur libfprint

`fprintd` berjalan sebagai root, agen tidak. Tanpa aturan udev, membuka pemindai
langsung gagal dengan:

    g-usb-device-error-quark: USB error on device 2541:0236 : Access denied (insufficient permissions) [-3]

Beri akses ke pengguna sesi lokal dengan aturan udev (sekali saja, perlu sudo):

    printf '%s\n' 'SUBSYSTEM=="usb", ATTRS{idVendor}=="2541", ATTRS{idProduct}=="0236", TAG+="uaccess", MODE="0660"' | sudo tee /etc/udev/rules.d/60-cs9711-fingerprint.rules
    sudo udevadm control --reload-rules
    sudo udevadm trigger --subsystem-match=usb

Lalu buktikan dengan `probe`: baris `uji buka perangkat` harus berhasil, bukan
`Access denied`. Jalankan dari sesi desktop laptop itu, bukan lewat SSH, karena
`TAG+="uaccess"` hanya berlaku untuk pengguna sesi lokal yang aktif.

Kalau tetap ditolak dan Anda bekerja lewat SSH, pakai grup sebagai gantinya:

    sudo groupadd -f plugdev
    sudo usermod -aG plugdev "$USER"        # login ulang setelah ini
    printf '%s\n' 'SUBSYSTEM=="usb", ATTRS{idVendor}=="2541", ATTRS{idProduct}=="0236", MODE="0660", GROUP="plugdev"' | sudo tee /etc/udev/rules.d/60-cs9711-fingerprint.rules
    sudo udevadm control --reload-rules
    sudo udevadm trigger --subsystem-match=usb

### Kalau gejalanya berubah jadi "Segmentation fault"

Setelah akses USB diberikan, keluhannya bisa berubah dari `Access denied` menjadi
`Segmentation fault`: proses mati begitu pemindai dibuka. Itu jatuh di dalam
libfprint/driver, bukan di logika agen, jadi yang perlu diketahui adalah panggilan
mana yang rusak. Ada alat diagnosis untuk itu:

    python3 libfprint-diag.py                 # tanpa menyentuh jari
    python3 libfprint-diag.py --with-enroll   # ikut mencoba mendaftarkan jari

Ia menjalankan setiap langkah di PROSES TERPISAH (daftar perangkat, `open_sync`,
`open` lewat jalur async, `list_prints_sync`), melaporkan mana yang OK dan mana
yang CRASH beserta kode sinyalnya, memeriksa apakah ada proses fprintd yang
menggantung memegang perangkat, lalu menutup dengan kesimpulan. Kirim seluruh
keluarannya — dari situ terlihat apakah cukup mengganti jalur agen ke async atau
masalahnya ada di driver.

## 5. Batas yang harus Anda tahu

**Identifikasi 1:N sudah ada — lewat `--capture libfprint`, bukan lewat fprintd.**
Perbedaan itu bukan gaya penulisan: fprintd menyimpan template per (akun Linux,
nama jari) di `/var/lib/fprint` yang hanya bisa dibaca root, sehingga jari satu
orang tidak bisa dicocokkan ke orang lain. Agen `libfprint` menyimpan template
di penyimpanannya sendiri, dan justru itu yang memungkinkan satu jari dicocokkan
ke semua template sekaligus.

**Login dengan jari itu SATU langkah, dan itu keputusan sadar.** Sejak 11 Okt 2026
pemilik aplikasi memilih sidik jari sebagai autentikasi penuh, seperti Windows
Hello — jadi perintah `login` memang membuka sesi, tanpa password. Konsekuensinya
jujur saja: risikonya berpindah ke agen dan ke PC itu, karena siapa pun yang bisa
menjalankan agen di PC tersebut dapat meminta tiket masuk untuk pengguna mana pun
yang jarinya terdaftar di sana. Kunci fisik PC-nya, jangan biarkan orang lain
memakai akun agen, dan perlakukan `secret` perangkat seperti kredensial admin.
Rincian lengkapnya di `docs/BIOMETRIC.md` §2.

**Template hanya ada di PC tempat pendaftaran dilakukan.** Penyimpanan agen
bersifat lokal, jadi karyawan yang memakai dua PC harus didaftarkan di keduanya.
Penyimpanan terpusat belum ada; kalau nanti dibutuhkan, `template_ref` sudah
menjadi penunjuk yang bisa dipindahkan.

**Skor mutu.** `fprintd` tidak melaporkan skor, jadi `enroll` mengirim
`quality=0` kecuali Anda memberi `--quality`. Angka 0 berarti "tidak ada skor
dari perangkat", bukan "mutu buruk" — kolom mutu di halaman admin akan
menampilkannya apa adanya supaya tidak ada angka karangan.

**Satu jari, satu baris.** Mendaftarkan jari yang sama dua kali ditolak server
kecuali memakai `--replace`, supaya pendaftaran lama tidak tertimpa diam-diam.
