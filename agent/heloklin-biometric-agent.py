#!/usr/bin/env python3
"""
Agen pemindai sidik jari untuk login.heloklin.com.

Berjalan di PC bersama tempat pemindai tercolok — BUKAN di server. Agen memegang
secret perangkat, membaca jari, lalu melaporkan pendaftaran ke API.

Yang agen ini TIDAK lakukan: ia tidak membuat sesi login dan tidak mengambil
keputusan otorisasi. Sidik jari hanya menjawab "ini siapa"; password/passkey
milik karyawan tetap yang membuktikan identitasnya.

Kontrak tanda tangan (lihat docs/BIOMETRIC.md):

    canonical = METHOD \n PATH \n TIMESTAMP \n sha256_hex(raw_body)
    signature = hex(hmac_sha256(secret_perangkat, canonical))

Header: X-Device-Id, X-Device-Timestamp, X-Device-Signature.

Perintah:
    python3 heloklin-biometric-agent.py doctor
    python3 heloklin-biometric-agent.py check
    python3 heloklin-biometric-agent.py enroll --username budi --finger right-index-finger
"""

from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import os
import platform
import shutil
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

DEFAULT_BASE_URL = "https://login.heloklin.com"
DEFAULT_CONFIG = os.path.expanduser("~/.config/heloklin-biometric/device.env")
DEFAULT_FINGER = "right-index-finger"
VENDOR_USB_ID = "2541:0236"  # Chipsailing CS9711

FINGERS = [
    "right-index-finger",
    "left-index-finger",
    "right-thumb",
    "left-thumb",
    "right-middle-finger",
    "left-middle-finger",
    "right-ring-finger",
    "left-ring-finger",
    "right-little-finger",
    "left-little-finger",
]


class AgentError(Exception):
    """Kesalahan yang harus ditampilkan apa adanya, tanpa jejak tumpukan."""


# --------------------------------------------------------------------------- config


def load_config(path: str, args: argparse.Namespace) -> dict:
    """Baca konfigurasi dari berkas, lalu menangkan nilai dari CLI.

    Menerima beberapa bentuk: `KEY=VALUE` per baris (berkas yang ditulis
    `biometric:register-device --secret-out`), JSON, atau berkas yang hanya berisi
    secret mentah.
    """
    config: dict[str, str] = {}

    if path and os.path.isfile(path):
        with open(path, "r", encoding="utf-8") as handle:
            raw = handle.read()

        parsed = False

        for line in raw.splitlines():
            line = line.strip()

            if not line or line.startswith("#") or "=" not in line:
                continue

            key, _, value = line.partition("=")
            config[key.strip().upper()] = value.strip()
            parsed = True

        if not parsed:
            text = raw.strip()

            if text.startswith("{"):
                data = json.loads(text)
                config = {str(k).upper(): str(v) for k, v in data.items()}
            elif text:
                config["BIOMETRIC_DEVICE_SECRET"] = text

    for arg, key in (
        (args.uuid, "BIOMETRIC_DEVICE_UUID"),
        (args.secret, "BIOMETRIC_DEVICE_SECRET"),
        (args.base_url, "BIOMETRIC_BASE_URL"),
    ):
        if arg:
            config[key] = arg

    missing = [k for k in ("BIOMETRIC_DEVICE_UUID", "BIOMETRIC_DEVICE_SECRET") if not config.get(k)]

    if missing:
        raise AgentError(
            "Konfigurasi belum lengkap: "
            + ", ".join(missing)
            + f"\nIsi di {path} (mode 0600), atau lewat --uuid/--secret."
        )

    config.setdefault("BIOMETRIC_BASE_URL", DEFAULT_BASE_URL)
    config["BIOMETRIC_BASE_URL"] = config["BIOMETRIC_BASE_URL"].rstrip("/")

    return config


# --------------------------------------------------------------------------- HTTP


def signed_request(config: dict, method: str, path: str, payload: dict | None) -> tuple[int, dict]:
    """Kirim satu request bertanda tangan. Inilah kontrak yang dipakai server."""
    body = "" if payload is None else json.dumps(payload, separators=(",", ":"))

    timestamp = str(int(time.time()))

    canonical = "\n".join(
        [method, path, timestamp, hashlib.sha256(body.encode("utf-8")).hexdigest()]
    )

    signature = hmac.new(
        config["BIOMETRIC_DEVICE_SECRET"].encode("utf-8"),
        canonical.encode("utf-8"),
        hashlib.sha256,
    ).hexdigest()

    url = config["BIOMETRIC_BASE_URL"] + path

    request = urllib.request.Request(url, data=body.encode("utf-8") if body else None, method=method)
    request.add_header("X-Device-Id", config["BIOMETRIC_DEVICE_UUID"])
    request.add_header("X-Device-Timestamp", timestamp)
    request.add_header("X-Device-Signature", signature)
    request.add_header("Accept", "application/json")

    if body:
        request.add_header("Content-Type", "application/json")

    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            raw = response.read().decode("utf-8", "replace")
            status = response.status
    except urllib.error.HTTPError as error:
        raw = error.read().decode("utf-8", "replace")
        status = error.code
    except urllib.error.URLError as error:
        raise AgentError(f"Tidak bisa menghubungi server: {error.reason}") from error

    try:
        decoded = json.loads(raw)
    except json.JSONDecodeError:
        decoded = {"_raw": raw}

    return status, decoded if isinstance(decoded, dict) else {"data": decoded}


# --------------------------------------------------------------------------- capture


def have(binary: str) -> bool:
    return shutil.which(binary) is not None


def usb_sensor_visible() -> bool | None:
    """Apakah pemindai terlihat di USB? None = tidak bisa diperiksa di OS ini."""
    if platform.system() != "Linux":
        return None

    if not have("lsusb"):
        return None

    try:
        output = subprocess.run(["lsusb"], capture_output=True, text=True, timeout=10).stdout
    except (OSError, subprocess.SubprocessError):
        return None

    return VENDOR_USB_ID in output


def capture_fprintd(finger: str, linux_user: str) -> str:
    """Pendaftaran lewat fprintd (libfprint). Menampilkan prompt di perangkat."""
    if not have("fprintd-enroll"):
        raise AgentError(
            "fprintd-enroll tidak ada. Pasang dukungan libfprint untuk pemindai ini "
            "(lihat agent/README.md), lalu ulangi."
        )

    print(f">> Tempelkan jari pada pemindai sekarang ({finger}). Ikuti prompt perangkat.")

    process = subprocess.run(
        ["fprintd-enroll", "-f", finger, linux_user],
        capture_output=True,
        text=True,
    )

    output = (process.stdout + process.stderr).strip()

    if output:
        for line in output.splitlines():
            print("   " + line)

    if process.returncode != 0 or "enroll-completed" not in output:
        raise AgentError("Pendaftaran di perangkat tidak selesai.")

    return f"fprintd:{platform.node()}:{linux_user}:{finger}"


def capture_command(command: str) -> str:
    """Pendaftaran lewat perintah milik Anda sendiri.

    Pakai ini untuk SDK vendor (mis. helper DLL Chipsailing di Windows). Perintah
    harus keluar dengan status 0 hanya bila jari benar-benar berhasil didaftarkan,
    dan boleh mencetak satu baris berisi template_ref ke stdout.
    """
    print(f">> Menjalankan perintah pendaftaran: {command}")

    process = subprocess.run(command, shell=True, capture_output=True, text=True)

    output = (process.stdout + process.stderr).strip()

    if output:
        for line in output.splitlines():
            print("   " + line)

    if process.returncode != 0:
        raise AgentError(f"Perintah pendaftaran gagal (exit {process.returncode}).")

    template_ref = process.stdout.strip().splitlines()[-1] if process.stdout.strip() else ""

    return template_ref or f"command:{platform.node()}:{int(time.time())}"


def run_capture(backend: str, finger: str, command: str | None) -> str:
    """Jalankan pembacaan jari dan kembalikan template_ref-nya."""
    chosen = backend

    if backend == "auto":
        system = platform.system()

        if system == "Linux" and have("fprintd-enroll"):
            chosen = "fprintd"
        elif command:
            chosen = "command"
        else:
            raise AgentError(
                "Tidak menemukan cara membaca pemindai di mesin ini.\n"
                "Jalankan `doctor` untuk melihat apa yang tersedia, lalu:\n"
                "  - Linux: pasang dukungan libfprint untuk pemindai (lihat agent/README.md), atau\n"
                "  - Windows: bangun helper SDK vendor lalu pakai --capture-command, atau\n"
                "  - pakai --capture command --capture-command '<perintah>'."
            )

    if chosen == "fprintd":
        return capture_fprintd(finger, os.environ.get("USER") or os.environ.get("LOGNAME") or "user")

    if chosen == "command":
        if not command:
            raise AgentError("--capture command memerlukan --capture-command '<perintah>'.")
        return capture_command(command)

    if chosen == "none":
        return ""

    raise AgentError(f"Backend pendaftaran tidak dikenal: {chosen}")


# --------------------------------------------------------------------------- perintah


def cmd_doctor(config: dict | None) -> int:
    system = platform.system()

    print("Pemeriksaan agen pemindai sidik jari")
    print(f"  OS            : {system} {platform.release()}")
    print(f"  Host          : {platform.node()}")
    print(f"  Python        : {platform.python_version()}")

    visible = usb_sensor_visible()

    if visible is None:
        print(f"  Pemindai USB  : tidak bisa diperiksa dari sini (cek manual: VID {VENDOR_USB_ID})")
    else:
        print(f"  Pemindai USB  : {'terlihat' if visible else 'TIDAK terlihat'} (VID {VENDOR_USB_ID})")

    if system == "Linux":
        print(f"  fprintd-enroll: {'ada' if have('fprintd-enroll') else 'tidak ada'}")
        print(f"  fprintd-verify: {'ada' if have('fprintd-verify') else 'tidak ada'}")
    elif system == "Windows":
        print("  Catatan       : di Windows, pembacaan sensor butuh SDK vendor")
        print("                  (mis. CSAlgDll.dll) lewat --capture-command.")

    print(f"  Berkas konfig : {DEFAULT_CONFIG} ({'ada' if os.path.isfile(DEFAULT_CONFIG) else 'belum ada'})")

    if config:
        status, body = signed_request(config, "GET", "/api/biometric/heartbeat", None)
        print(f"  Server        : {config['BIOMETRIC_BASE_URL']} -> HTTP {status}")

        if status == 200:
            print(f"  Perangkat     : {body.get('device', {}).get('name')} ({body.get('device', {}).get('platform')})")
        else:
            print(f"  Pesan server  : {body.get('message', '-')}")

    print()
    print("Yang masih diperlukan untuk alur lengkap: kemampuan identifikasi 1:N")
    print("(fungsi identify/search) pada SDK pemindai. Tanpa itu, langkah login")
    print("'tempel jari lalu sistem tahu siapa' belum bisa; enrolmen sudah bisa.")

    return 0


def cmd_check(config: dict) -> int:
    status, body = signed_request(config, "GET", "/api/biometric/heartbeat", None)

    if status != 200:
        print(f"GAGAL heartbeat (HTTP {status}): {body.get('message', '-')}", file=sys.stderr)
        return 1

    print("Heartbeat OK.")
    print(f"  Perangkat   : {body.get('device', {}).get('name')}")
    print(f"  Server time : {body.get('server_time')}")

    status, body = signed_request(config, "GET", "/api/biometric/enrollments", None)

    if status != 200:
        print(f"GAGAL membaca daftar (HTTP {status})", file=sys.stderr)
        return 1

    rows = body.get("enrollments", [])
    print(f"  Terdaftar   : {len(rows)}")

    for row in rows[:10]:
        print(
            "    {u:<14} {f:<20} {r}".format(
                u=row.get("username", "?"),
                f=row.get("finger", "?"),
                r=row.get("template_ref", "?"),
            )
        )

    return 0


def cmd_enroll(config: dict, args: argparse.Namespace) -> int:
    if args.finger not in FINGERS:
        raise AgentError("Jari harus salah satu dari: " + ", ".join(FINGERS))

    template_ref = args.template_ref or run_capture(args.capture, args.finger, args.capture_command)

    if not template_ref:
        # Backend `none`: kirim penunjuk yang jelas-jelas bukan template perangkat.
        raise AgentError(
            "Tidak ada template_ref. Backend 'none' hanya untuk menguji jalur API; "
            "sertakan --template-ref=<penanda> bila memang itu yang diinginkan."
        )

    quality = args.quality

    if quality is None:
        quality = 0
        print(">> Catatan: perangkat/backend ini tidak melaporkan skor mutu, jadi quality=0.")

    payload = {
        "username": args.username,
        "finger": args.finger,
        "template_ref": template_ref,
        "quality": int(quality),
        "consent": True,
        "replace": bool(args.replace),
    }

    status, body = signed_request(config, "POST", "/api/biometric/enrollments", payload)

    if status != 201:
        print(f"GAGAL mendaftarkan (HTTP {status}): {json.dumps(body, ensure_ascii=False)}", file=sys.stderr)
        return 1

    enrollment = body.get("enrollment", {})

    print("Pendaftaran tercatat di server.")
    print(f"  id           : {enrollment.get('id')}")
    print(f"  user         : {enrollment.get('username')}")
    print(f"  jari         : {enrollment.get('finger')}")
    print(f"  template_ref : {enrollment.get('template_ref')}")
    print(f"  mutu         : {enrollment.get('quality')}")
    print()
    print("Lihat di https://login.heloklin.com/biometric-devices (perlu akun admin).")

    return 0


def cmd_probe(config: dict | None) -> int:
    """Laporan kesiapan libfprint untuk jalur identifikasi 1:N.

    Tidak menyentuh sidik jari: tidak enroll, verify, maupun identify. Hanya
    melaporkan kemampuan perangkat dan tanda tangan fungsi yang harus dipakai
    kode berikutnya.
    """
    lines: list[str] = []
    add = lines.append

    add("=== libfprint di mesin ini ===")

    try:
        import gi

        add(f"  pygobject      : ada (gi {getattr(gi, '__version__', '?')})")
        gi.require_version("FPrint", "2.0")
        from gi.repository import FPrint
    except Exception as error:  # noqa: BLE001 - laporan harus tetap jalan
        add(f"  GAGAL memuat FPrint: {type(error).__name__}: {error}")
        add("")
        add("Pasang dulu:  sudo apt install python3-gi gir1.2-fprint-2.0")
        print("\n".join(lines))

        return 1

    def attempt(label: str, fn):
        try:
            return fn()
        except Exception as error:  # noqa: BLE001
            add(f"      {label}: GAGAL -> {type(error).__name__}: {error}")
            return None

    add("  FPrint 2.0     : ada (versi "
        + " ".join(str(getattr(FPrint, name, "?")) for name in ("MAJOR_VERSION", "MINOR_VERSION", "MICRO_VERSION"))
        + ")")

    add("")
    add("=== perangkat ===")

    devices = FPrint.Context().get_devices()

    if not devices:
        add("  tidak ada perangkat yang ditemukan libfprint")

    for index, device in enumerate(devices):
        add(f"  [{index}] {device.get_name()}")
        add(f"      driver            : {device.get_driver()}")
        add(f"      device_id         : {attempt('device_id', lambda: device.props.device_id)}")
        add(f"      nr_enroll_stages  : {attempt('nr_enroll_stages', device.get_nr_enroll_stages)}")
        add(f"      has_storage       : {attempt('has_storage', device.has_storage)}")
        add(f"      supports_identify : {attempt('supports_identify', device.supports_identify)}")
        add(f"      supports_capture  : {attempt('supports_capture', device.supports_capture)}")

        def open_and_report() -> str:
            device.open_sync()
            try:
                return f"terbuka, print tersimpan di perangkat: {len(device.list_prints_sync())}"
            finally:
                device.close_sync()

        add(f"      uji buka perangkat : {attempt('open', open_and_report)}")

    add("")
    add("=== tanda tangan fungsi (salin apa adanya) ===")

    for name, function in (
        ("FPrint.Print.new", getattr(FPrint.Print, "new", None)),
        ("FPrint.Print.serialize", getattr(FPrint.Print, "serialize", None)),
        ("FPrint.Print.deserialize", getattr(FPrint.Print, "deserialize", None)),
        ("FPrint.Print.set_finger", getattr(FPrint.Print, "set_finger", None)),
        ("FPrint.Device.enroll_sync", getattr(FPrint.Device, "enroll_sync", None)),
        ("FPrint.Device.identify_sync", getattr(FPrint.Device, "identify_sync", None)),
        ("FPrint.Device.verify_sync", getattr(FPrint.Device, "verify_sync", None)),
    ):
        if function is None:
            add(f"  {name}: TIDAK ADA di versi ini")
            continue

        doc = (function.__doc__ or "").strip().splitlines()
        signature = next((line.strip() for line in doc if "(" in line and "->" in line), "")

        add(f"  {name}: {signature or '(tanda tangan tidak terbaca)'}")

    add("")
    add("  anggota FPrint.Finger: " + ", ".join(sorted(
        name for name in dir(FPrint.Finger) if name.isupper() and not name.startswith("_")
    )))

    add("")
    add("=== penyimpanan fprintd (apakah template lama bisa dipakai ulang) ===")
    add(f"  /var/lib/fprint: {'ada' if os.path.isdir('/var/lib/fprint') else 'tidak ada'}"
        f" | bisa dibaca: {'ya' if os.access('/var/lib/fprint', os.R_OK) else 'tidak'}")
    add("  (bisa dibaca 'tidak' itu normal: fprintd menyimpannya sebagai root)")

    add("")
    add("=== selesai. Kirim seluruh keluaran ini. ===")

    print("\n".join(lines))

    return 0


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Agen pemindai sidik jari login.heloklin.com (identifikasi, bukan autentikasi)."
    )

    add_global_options(parser, suppress=False)

    # Opsi global juga tersedia SETELAH subperintah (`... doctor --config X`),
    # karena itu bentuk yang paling wajar ditulis. `SUPPRESS` penting: tanpa itu
    # nilai default parser anak akan menimpa nilai yang sudah diberikan di depan.
    parent = argparse.ArgumentParser(add_help=False)
    add_global_options(parent, suppress=True)

    sub = parser.add_subparsers(dest="command", required=True)

    sub.add_parser("doctor", parents=[parent], help="periksa OS, pemindai, dan kredensial perangkat")
    sub.add_parser(
        "probe",
        parents=[parent],
        help="laporan kesiapan libfprint untuk identifikasi 1:N (tidak menyentuh jari)",
    )
    sub.add_parser(
        "check",
        parents=[parent],
        help="heartbeat + daftar pendaftaran yang akan diterima agen",
    )

    enroll = sub.add_parser(
        "enroll",
        parents=[parent],
        help="baca jari lalu laporkan pendaftarannya ke server",
    )
    enroll.add_argument("--username", required=True, help="username karyawan yang didaftarkan")
    enroll.add_argument("--finger", default=DEFAULT_FINGER, help=f"default {DEFAULT_FINGER}")
    enroll.add_argument("--quality", type=int, help="skor mutu 0-100; default 0 bila backend tidak melaporkan")
    enroll.add_argument("--replace", action="store_true", help="ganti pendaftaran lama untuk jari yang sama")
    enroll.add_argument("--template-ref", help="pakai penunjuk template ini alih-alih hasil pembacaan")
    enroll.add_argument(
        "--capture",
        default="auto",
        choices=["auto", "fprintd", "command", "none"],
        help="cara membaca pemindai (default auto)",
    )
    enroll.add_argument("--capture-command", help="perintah pendaftaran sendiri, untuk SDK vendor")

    return parser


def add_global_options(target: argparse.ArgumentParser, suppress: bool) -> None:
    """Opsi yang boleh muncul sebelum maupun sesudah subperintah."""
    target.add_argument(
        "--config",
        default=(argparse.SUPPRESS if suppress else DEFAULT_CONFIG),
        help=f"berkas konfigurasi (default {DEFAULT_CONFIG})",
    )
    target.add_argument(
        "--uuid",
        default=(argparse.SUPPRESS if suppress else None),
        help="UUID perangkat (menimpa berkas konfigurasi)",
    )
    target.add_argument(
        "--secret",
        default=(argparse.SUPPRESS if suppress else None),
        help="secret perangkat (menimpa berkas konfigurasi; hindari)",
    )
    target.add_argument(
        "--base-url",
        default=(argparse.SUPPRESS if suppress else None),
        help=f"default {DEFAULT_BASE_URL}",
    )


def main() -> int:
    args = build_parser().parse_args()

    try:
        if args.command in {"doctor", "probe"}:
            # Kedua perintah ini harus tetap berguna sebelum konfigurasi ada.
            try:
                config: dict | None = load_config(args.config, args)
            except AgentError:
                config = None

            if args.command == "probe":
                return cmd_probe(config)

            return cmd_doctor(config)

        config = load_config(args.config, args)

        if args.command == "check":
            return cmd_check(config)

        return cmd_enroll(config, args)
    except AgentError as error:
        print(str(error), file=sys.stderr)
        return 1
    except KeyboardInterrupt:
        print("\nDibatalkan.", file=sys.stderr)
        return 130


if __name__ == "__main__":
    sys.exit(main())
