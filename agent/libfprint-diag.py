#!/usr/bin/env python3
"""Diagnosis libfprint: cari tahu panggilan mana yang membuat proses jatuh.

Dipakai saat `probe` atau `enroll --capture libfprint` berhenti dengan
"Segmentation fault". Setiap langkah dijalankan di PROSES TERPISAH, supaya satu
langkah yang crash tidak menghentikan langkah berikutnya — dari situ terlihat
panggilan mana yang rusak, dan mana yang sebenarnya sehat.

Jalankan di PC yang ada pemindainya:

    python3 libfprint-diag.py

Tidak menyentuh data apa pun: tidak mendaftarkan, tidak menghapus, hanya membuka
dan menutup perangkat. Satu langkah (`enroll_sync`) memang meminta jari, dan itu
hanya dijalankan bila Anda memintanya:

    python3 libfprint-diag.py --with-enroll
"""

from __future__ import annotations

import argparse
import shutil
import subprocess
import sys

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
    print(f"[{step}]", *parts)

devices = FPrint.Context().get_devices()

if not devices:
    report("tidak ada perangkat yang ditemukan libfprint")
    raise SystemExit(3)

device = devices[0]

report("driver", device.get_driver(), "| device_id", device.props.device_id,
       "| tahap", device.get_nr_enroll_stages())


def run_async(name, callback_target):
    """Panggil versi async (nama bisa `open` atau `open_async`) lalu tunggu."""
    method = getattr(device, name, None) or getattr(device, name + "_async", None)

    if method is None:
        report(f"metode {name} tidak ada di binding ini")
        raise SystemExit(5)

    loop = GLib.MainLoop()
    state = {}

    def on_done(dev, result, _user_data):
        try:
            getattr(dev, callback_target)(result)
            state["ok"] = True
        except Exception as error:
            state["error"] = error
        loop.quit()

    method(None, on_done, None)
    loop.run()

    return state


if step == "buka_sync":
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

SIGNALS = {
    139: "CRASH (SIGSEGV)",
    134: "ABORT (SIGABRT)",
    135: "CRASH (SIGBUS)",
    137: "DIBUNUH (SIGKILL / kehabisan waktu)",
}


def run_step(step: str, timeout: int) -> tuple[str, str]:
    """Jalankan satu langkah di proses terpisah. Kembalikan (kesimpulan, keluaran)."""
    try:
        done = subprocess.run(
            [sys.executable, "-X", "faulthandler", "-c", CHILD, step],
            capture_output=True,
            text=True,
            timeout=timeout,
        )
    except subprocess.TimeoutExpired:
        return "TIMEOUT", f"tidak selesai dalam {timeout} detik"

    output = (done.stdout + done.stderr).strip()

    if done.returncode == 0:
        return "OK", output

    if done.returncode in SIGNALS:
        return SIGNALS[done.returncode], output

    return f"keluar dengan kode {done.returncode}", output


def main() -> int:
    parser = argparse.ArgumentParser(description="Diagnosis libfprint langkah demi langkah.")
    parser.add_argument("--with-enroll", action="store_true", help="ikutkan langkah yang meminta jari")
    parser.add_argument("--timeout", type=int, default=120, help="batas detik per langkah")
    args = parser.parse_args()

    # --- keadaan awal yang mudah terlewat -----------------------------------
    print("=== keadaan mesin ===")
    print(f"  python      : {sys.version.split()[0]}")

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
        holders = subprocess.run(
            [pgrep, "-a", "-f", "fprintd"],
            capture_output=True,
            text=True,
        ).stdout.strip()
        print(f"  proses fprintd: {holders if holders else 'tidak ada'}")
        if holders:
            print("                  (proses fprintd yang menggantung bisa memegang perangkatnya;")
            print("                   tutup dengan: sudo systemctl restart fprintd, atau bunuh PID di atas)")

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

        for line in output.splitlines():
            if "[ENROLL]" in line or "faulthandler" in line.lower() or "File \"" in line or "Segmentation" in line:
                print(f"        {line.strip()}")

    print()
    print("=== kesimpulan ===")

    if not fprint_ok:
        print("  FPrint belum bisa diimpor dari Python, jadi langkah-langkah di atas tidak")
        print("  menguji apa pun. Pasang python3-gi + gir1.2-fprint-2.0 lebih dulu, lalu")
        print("  jalankan skrip ini lagi.")

        return 1

    sync_ok = verdicts.get("buka_sync") == "OK"
    async_ok = verdicts.get("buka_async") == "OK"

    if sync_ok and async_ok:
        print("  Kedua jalur membuka perangkat dengan baik. Kalau probe/enroll masih")
        print("  gagal, kirim keluarannya apa adanya.")
    elif async_ok and not sync_ok:
        print("  Jalur ASYNC sehat, jalur SYNC yang jatuh. Ini kesimpulan yang paling")
        print("  berguna: kirim keluaran ini ke asisten, karena backend agen hanya")
        print("  perlu diubah memakai jalur async (open/enroll/identify) dan masalahnya")
        print("  selesai tanpa menyentuh sistem Anda.")
    elif sync_ok and not async_ok:
        print("  Jalur SYNC sehat, ASYNC yang jatuh: agen yang ada seharusnya sudah")
        print("  cukup. Kirim keluaran ini.")
    else:
        print("  Kedua jalur sama-sama jatuh saat membuka perangkat. Artinya yang")
        print("  bermasalah ada di driver/libfprint saat diakses langsung, bukan di")
        print("  jalur Python-nya. Dua hal yang bisa dicoba sebelum menyimpulkan:")
        print("    1. Pastikan tidak ada proses fprintd yang memegang perangkat (lihat di atas).")
        print("    2. Coba sebagai root: sudo python3 libfprint-diag.py --with-enroll")
        print("  Kirim keluaran keduanya.")

    print()
    print("Kirim seluruh keluaran ini ke asisten.")

    return 0


if __name__ == "__main__":
    sys.exit(main())
