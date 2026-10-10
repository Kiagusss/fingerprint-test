#!/usr/bin/env python3
"""Diagnosis libfprint: panggilan dan PUSTAKA mana yang membuat proses jatuh.

Dipakai saat `probe`, `enroll --capture libfprint`, atau `login` berhenti dengan
"Segmentation fault". Setiap langkah dijalankan di PROSES TERPISAH, supaya satu
langkah yang crash tidak menutupi langkah lain — dari situ terlihat panggilan mana
yang rusak dan mana yang sehat.

Skrip ini juga:
  - melaporkan berkas libfprint mana yang dipetakan ke proses Python;
  - membandingkannya dengan pustaka yang dipakai `fprintd` (yang di beberapa
    mesin terbukti bisa membuka perangkat yang sama);
  - mencoba SETIAP pustaka libfprint yang terpasang satu per satu, karena versi
    yang berbeda dari yang dipakai fprintd adalah sebab paling sering dari crash
    seperti ini.

Jalankan di PC yang ada pemindainya:

    python3 libfprint-diag.py                 # tidak menyentuh jari
    python3 libfprint-diag.py --with-enroll   # ikut mencoba mendaftarkan jari
    sudo python3 libfprint-diag.py            # untuk membandingkan dengan root
"""

from __future__ import annotations

import argparse
import glob
import os
import shutil
import subprocess
import sys
from pathlib import Path

# Program anak: dijalankan sekali per langkah, dengan faulthandler aktif supaya
# jejak Python tercetak sebelum proses mati.
CHILD = r'''
import sys, faulthandler
faulthandler.enable()

step = sys.argv[1]

import gi
gi.require_version("FPrint", "2.0")
from gi.repository import FPrint, GLib

def report(*parts):
    print(f"[{step}]", *parts, flush=True)

try:
    terpetakan = sorted({baris.split()[-1] for baris in open("/proc/self/maps") if "libfprint" in baris})
except OSError:
    terpetakan = []

report("pustaka:", ", ".join(terpetakan) if terpetakan else "(tidak terlihat di /proc/self/maps)")

devices = FPrint.Context().get_devices()

if not devices:
    report("tidak ada perangkat yang ditemukan libfprint")
    raise SystemExit(3)

device = devices[0]

report("driver", device.get_driver(), "| device_id", device.props.device_id,
       "| tahap", device.get_nr_enroll_stages())


def run_async(name, finish_name):
    """Panggil versi async (nama bisa `open` atau `open_async`) lalu tunggu."""
    method = getattr(device, name, None) or getattr(device, name + "_async", None)

    if method is None:
        report(f"metode {name} tidak ada di binding ini")
        raise SystemExit(5)

    loop = GLib.MainLoop()
    state = {}

    def on_done(dev, result, _user_data):
        try:
            getattr(dev, finish_name)(result)
            state["ok"] = True
        except Exception as error:
            state["error"] = error
        loop.quit()

    method(None, on_done, None)
    loop.run()

    return state


if step == "daftar":
    report("daftar perangkat OK")

elif step == "buka_sync":
    device.open_sync()
    report("open_sync OK")
    device.close_sync()
    report("close_sync OK")

elif step == "buka_async":
    state = run_async("open", "open_finish")

    if "error" in state:
        report("open (async) GAGAL:", state["error"])
        raise SystemExit(4)

    report("open (async) OK")

    state = run_async("close", "close_finish")
    report("close (async) OK" if "error" not in state else f"close (async) GAGAL: {state['error']}")

elif step == "print_sync":
    device.open_sync()
    report("open_sync OK")
    prints = device.list_prints_sync()
    report("list_prints_sync OK, jumlah", len(prints))
    device.close_sync()
    report("close_sync OK")

elif step == "enroll_sync":
    device.open_sync()
    report("open_sync OK")
    template = FPrint.Print.new(device)
    template.set_finger(FPrint.Finger.RIGHT_INDEX)
    print(f"[{step}] TEMPELKAN JARI SEKARANG (15 tahap)", flush=True)
    enrolled = device.enroll_sync(template, None, None)
    report("enroll_sync OK" if enrolled is not None else "enroll_sync selesai tanpa template")
    device.close_sync()

else:
    report("langkah tidak dikenal")
'''

STEPS = [
    ("daftar", "daftar perangkat dan kemampuan (tidak membuka perangkat)"),
    ("buka_sync", "buka perangkat dengan open_sync"),
    ("buka_async", "buka perangkat dengan jalur async + GLib.MainLoop"),
    ("print_sync", "open_sync lalu list_prints_sync"),
]

# Kode sinyal datang sebagai negatif dari subprocess (mis. -11 = SIGSEGV), tetapi
# sebagian shell melaporkannya positif. Keduanya dikenali.
SIGNALS = {
    -11: "CRASH (SIGSEGV)",
    -6: "ABORT (SIGABRT)",
    -7: "CRASH (SIGBUS)",
    -9: "DIBUNUH (SIGKILL)",
    139: "CRASH (SIGSEGV)",
    134: "ABORT (SIGABRT)",
    135: "CRASH (SIGBUS)",
    137: "DIBUNUH (SIGKILL)",
}

LIBRARY_GLOBS = [
    "/usr/lib/*/libfprint-2.so.2",
    "/usr/lib/libfprint-2.so.2",
    "/usr/local/lib/libfprint-2.so.2",
    "/usr/local/lib/*/libfprint-2.so.2",
    "/opt/*/lib/libfprint-2.so.2",
    "/opt/*/lib/*/libfprint-2.so.2",
]


def run_step(step: str, timeout: int, env: dict | None = None) -> tuple[str, str]:
    """Jalankan satu langkah di proses terpisah. Kembalikan (kesimpulan, keluaran)."""
    try:
        done = subprocess.run(
            [sys.executable, "-X", "faulthandler", "-c", CHILD, step],
            capture_output=True,
            text=True,
            timeout=timeout,
            env=env,
        )
    except subprocess.TimeoutExpired:
        return "TIMEOUT", f"tidak selesai dalam {timeout} detik"

    output = (done.stdout + done.stderr).strip()

    if done.returncode == 0:
        return "OK", output

    if done.returncode in SIGNALS:
        return SIGNALS[done.returncode], output

    return f"keluar dengan kode {done.returncode}", output


def reported_library(output: str) -> str:
    """Ambil baris 'pustaka: ...' dari keluaran anak."""
    for line in output.splitlines():
        if "pustaka:" in line:
            return line.split("pustaka:", 1)[1].strip()

    return "(tidak terbaca)"


def installed_libraries() -> dict[str, str]:
    """Peta direktori -> berkas nyata, untuk setiap libfprint-2.so.2 yang ada."""
    found: dict[str, str] = {}

    for pattern in LIBRARY_GLOBS:
        for path in sorted(glob.glob(pattern)):
            resolved = Path(path).resolve()
            found[str(resolved.parent)] = str(resolved)

    return found


def main() -> int:
    parser = argparse.ArgumentParser(description="Diagnosis libfprint: langkah dan pustaka.")
    parser.add_argument("--with-enroll", action="store_true", help="ikutkan langkah yang meminta jari")
    parser.add_argument("--timeout", type=int, default=120, help="batas detik per langkah")
    parser.add_argument("--skip-library-sweep", action="store_true", help="jangan mencoba tiap pustaka")
    args = parser.parse_args()

    # --- keadaan mesin --------------------------------------------------------
    print("=== keadaan mesin ===")
    print(f"  python       : {sys.version.split()[0]}")
    print(f"  dijalankan   : uid {os.geteuid()} ({'root' if os.geteuid() == 0 else os.environ.get('USER', '?')})")

    fprint_ok = False

    try:
        import gi

        gi.require_version("FPrint", "2.0")
        from gi.repository import FPrint

        fprint_ok = True

        print(f"  pygobject    : {gi.__version__}")
        print(f"  FPrint       : MAJOR={getattr(FPrint, 'MAJOR_VERSION', '?')} "
              f"MINOR={getattr(FPrint, 'MINOR_VERSION', '?')} "
              f"MICRO={getattr(FPrint, 'MICRO_VERSION', '?')}")
    except Exception as error:  # noqa: BLE001
        print(f"  GAGAL impor FPrint: {error}")
        print("  (pasang dulu: sudo apt install python3-gi gir1.2-fprint-2.0)")

    lsusb = shutil.which("lsusb")

    if lsusb:
        listed = subprocess.run([lsusb], capture_output=True, text=True).stdout
        hits = [line.strip() for line in listed.splitlines() if "2541:0236" in line]
        print(f"  pemindai USB : {'; '.join(hits) if hits else 'TIDAK terlihat (2541:0236)'}")

    pgrep = shutil.which("pgrep")

    if pgrep:
        # -x: cocokkan nama prosesnya saja, supaya perintah lain yang kebetulan
        # memuat kata "fprintd" di argumennya tidak ikut terhitung.
        holders = subprocess.run([pgrep, "-a", "-x", "fprintd"], capture_output=True, text=True).stdout.strip()
        print(f"  proses fprintd: {holders if holders else 'tidak ada'}")

        if holders:
            print("                  (fprintd yang menggantung bisa memegang perangkat;")
            print("                   tutup dengan: sudo systemctl restart fprintd)")

    # --- pustaka: yang terpasang dan yang dipakai fprintd ---------------------
    print()
    print("=== pustaka ===")

    libraries = installed_libraries()

    if libraries:
        for path in libraries.values():
            print(f"  terpasang    : {path}")
    else:
        print("  tidak ada libfprint-2.so.2 yang ditemukan di jalur umum")

    fprintd = shutil.which("fprintd")

    if fprintd:
        print(f"  fprintd      : {fprintd}")

        for line in subprocess.run(["ldd", fprintd], capture_output=True, text=True).stdout.splitlines():
            if "libfprint" in line:
                print(f"  fprintd pakai: {line.strip()}")
    else:
        print("  fprintd      : tidak ada di PATH")

    # --- langkah per langkah --------------------------------------------------
    print()
    print("=== langkah per langkah (tiap langkah di proses terpisah) ===")

    steps = list(STEPS)

    if args.with_enroll:
        steps.append(("enroll_sync", "open_sync lalu enroll_sync (meminta jari)"))

    verdicts: dict[str, str] = {}

    for step, description in steps:
        verdict, output = run_step(step, args.timeout)
        verdicts[step] = verdict

        print(f"  [{verdict}] {step} — {description}")
        print(f"        pustaka: {reported_library(output)}")

        for line in output.splitlines():
            if "TEMPELKAN" in line or 'File "' in line or "Fatal Python" in line:
                print(f"        {line.strip()}")

    # --- sapu semua pustaka yang terpasang ------------------------------------
    working: list[str] = []

    if not args.skip_library_sweep and libraries:
        print()
        print("=== mencoba setiap pustaka yang terpasang, satu per satu (open_sync) ===")

        for directory in sorted(libraries):
            env = dict(os.environ, LD_LIBRARY_PATH=directory)
            verdict, output = run_step("buka_sync", args.timeout, env=env)

            print(f"  [{verdict}] LD_LIBRARY_PATH={directory}")
            print(f"        pustaka: {reported_library(output)}")

            for line in output.splitlines():
                if "open_sync OK" in line or "Fatal Python" in line:
                    print(f"        {line.strip()}")

            if verdict == "OK":
                working.append(directory)

    # --- kesimpulan -----------------------------------------------------------
    print()
    print("=== kesimpulan ===")

    if not fprint_ok:
        print("  FPrint belum bisa diimpor dari Python, jadi langkah di atas tidak menguji")
        print("  apa pun. Pasang python3-gi + gir1.2-fprint-2.0 lebih dulu, lalu ulangi.")

        return 1

    if working:
        print("  KESIMPULAN PENTING: ada pustaka yang BISA membuka perangkat:")
        for directory in working:
            print(f"    {directory}")

        print()
        print("  Artinya masalahnya salah pustaka, bukan salah driver. Coba langsung:")
        directory = working[0]
        print(f"    LD_LIBRARY_PATH={directory} python3 ~/heloklin-biometric-agent.py enroll --username admin --finger right-index-finger --capture libfprint --replace")
        print(f"    LD_LIBRARY_PATH={directory} python3 ~/heloklin-biometric-agent.py login")

        print()
        print("  Kirim seluruh keluaran ini. Dari sini urutan pustaka di sistem bisa")
        print("  dirapikan supaya Anda tidak perlu menulis LD_LIBRARY_PATH terus-menerus.")

        return 0

    if verdicts.get("buka_sync") == "OK":
        print("  Perangkat terbuka dengan baik tanpa perlakuan khusus. Kalau probe/enroll")
        print("  masih gagal, kirim keluarannya apa adanya.")

        return 0

    print("  Tidak ada pustaka libfprint yang terpasang bisa membuka perangkat ini,")
    print("  padahal fprintd bisa. Tiga hal yang paling berguna dikirim sekarang:")
    print("    1. bagian === pustaka === di atas (termasuk baris 'fprintd pakai:');")
    print("    2. keluaran: sudo apt list --installed 2>/dev/null | grep -i fprint")
    print("    3. keluaran: sudo dpkg -l | grep -i fprint")

    return 1


if __name__ == "__main__":
    sys.exit(main())
