#!/usr/bin/env python3
"""Uji asap jalur login sidik jari pada server yang hidup.

Membuktikan rantai lengkap di produksi, bukan hanya di suite tes:

    agen bertanda tangan -> terbit tiket -> tukar tiket di browser -> sesi terbuka

Cara kerjanya: membuat PERANGKAT dan satu PENDAFTARAN sementara untuk satu user,
menukar tiketnya, lalu MENGHAPUS semuanya lagi. Perangkat dan pendaftaran yang
sudah ada tidak pernah disentuh — terutama, tidak memakai `replace`, karena itu
akan mencabut jari asli pemilik akun.

Pemakaian (di server, dari akar repo):

    php artisan biometric:register-device "SMOKE TEST (uji asap)" \\
        --host=SMOKE --platform=linux --secret-out=/tmp/smoke.env
    python3 scripts/smoke_fingerprint_login.py --device-config=/tmp/smoke.env --username=admin
    rm -f /tmp/smoke.env

Setelah selesai, hapus sisa barisnya (skrip ini tidak bisa memanggil DB):

    php artisan tinker --execute="App\\Models\\BiometricEnrollment::where('template_ref','like','tpl-smoke-%')->delete();"
    php artisan tinker --execute="App\\Models\\BiometricDevice::where('name','SMOKE TEST (uji asap)')->delete();"

Token dan secret tidak pernah dicetak.
"""

from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import os
import secrets
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from http.cookiejar import CookieJar

FINGER = "left-little-finger"  # sengaja bukan jari yang biasa dipakai


def fail(message: str) -> None:
    print(f"GAGAL: {message}", file=sys.stderr)
    sys.exit(1)


def read_config(path: str) -> dict[str, str]:
    values: dict[str, str] = {}

    with open(path, encoding="utf-8") as handle:
        for line in handle:
            line = line.strip()

            if not line or line.startswith("#") or "=" not in line:
                continue

            key, _, value = line.partition("=")
            values[key.strip()] = value.strip()

    for key in ("BIOMETRIC_DEVICE_UUID", "BIOMETRIC_DEVICE_SECRET"):
        if not values.get(key):
            fail(f"{path} tidak memuat {key}")

    values.setdefault("BIOMETRIC_BASE_URL", "https://login.heloklin.com")

    return values


def signed_request(config: dict[str, str], method: str, path: str, payload: dict | None):
    body = "" if payload is None else json.dumps(payload, separators=(",", ":"))

    timestamp = str(int(time.time()))

    canonical = "\n".join([method, path, timestamp, hashlib.sha256(body.encode()).hexdigest()])
    signature = hmac.new(
        config["BIOMETRIC_DEVICE_SECRET"].encode(),
        canonical.encode(),
        hashlib.sha256,
    ).hexdigest()

    request = urllib.request.Request(
        config["BIOMETRIC_BASE_URL"].rstrip("/") + path,
        data=body.encode() if body else None,
        method=method,
        headers={
            "X-Device-Id": config["BIOMETRIC_DEVICE_UUID"],
            "X-Device-Timestamp": timestamp,
            "X-Device-Signature": signature,
            "Content-Type": "application/json",
            "Accept": "application/json",
        },
    )

    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            return response.status, json.loads(response.read() or b"{}")
    except urllib.error.HTTPError as error:
        try:
            return error.code, json.loads(error.read() or b"{}")
        except ValueError:
            return error.code, {}


class NoRedirect(urllib.request.HTTPRedirectHandler):
    """Supaya kode 302 terlihat apa adanya, bukan diikuti diam-diam."""

    def redirect_request(self, *args, **kwargs):  # noqa: D102
        return None


def browser(config: dict[str, str]):
    jar = CookieJar()

    return urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(jar),
        NoRedirect(),
    )


def fetch(opener, url: str):
    try:
        request = urllib.request.Request(url, headers={"Accept": "text/html"})
        with opener.open(request, timeout=20) as response:
            return response.status, response.headers.get("Location", ""), response.read(400).decode(errors="replace")
    except urllib.error.HTTPError as error:
        return error.code, error.headers.get("Location", ""), ""


def main() -> int:
    parser = argparse.ArgumentParser(description="Uji asap login sidik jari di server hidup.")
    parser.add_argument("--device-config", required=True, help="berkas KEY=VALUE dari biometric:register-device")
    parser.add_argument("--username", required=True, help="user yang akan dipakai untuk pendaftaran sementara")
    parser.add_argument("--base-url", default=None)
    args = parser.parse_args()

    config = read_config(args.device_config)

    if args.base_url:
        config["BIOMETRIC_BASE_URL"] = args.base_url

    template_ref = f"tpl-smoke-{secrets.token_hex(6)}"

    print("Uji asap login sidik jari")
    print(f"  server       : {config['BIOMETRIC_BASE_URL']}")
    print(f"  perangkat    : {config['BIOMETRIC_DEVICE_UUID']}")
    print(f"  user         : {args.username}")
    print(f"  jari         : {FINGER} (pendaftaran sementara, dihapus lagi sesudah uji)")
    print(f"  template_ref : {template_ref}")

    status, body = signed_request(config, "GET", "/api/biometric/heartbeat", None)

    if status != 200:
        fail(f"heartbeat HTTP {status}")

    print(f"  heartbeat    : OK ({body.get('device', {}).get('name')})")

    status, body = signed_request(config, "POST", "/api/biometric/enrollments", {
        "username": args.username,
        "finger": FINGER,
        "template_ref": template_ref,
        "quality": 0,
        "consent": True,
    })

    if status != 201:
        fail(f"pendaftaran sementara HTTP {status}: {json.dumps(body, ensure_ascii=False)}")

    print(f"  pendaftaran  : id={body.get('enrollment', {}).get('id')} dibuat")

    status, body = signed_request(config, "POST", "/api/biometric/login-tickets", {
        "template_ref": template_ref,
    })

    if status != 201:
        fail(f"terbit tiket HTTP {status}: {json.dumps(body, ensure_ascii=False)}")

    login = body.get("login", {})
    url = str(login.get("url", ""))

    print(f"  tiket        : terbit untuk {login.get('username')} (berlaku {login.get('expires_in')} detik)")

    # --- sisi browser: tukar tiket jadi sesi ---------------------------------
    opener = browser(config)

    status, location, _ = fetch(opener, url)
    print(f"  tukar tiket  : HTTP {status} -> {location or '(tanpa Location)'}")

    if status != 302:
        fail("tiket tidak menghasilkan pengalihan ke dashboard")

    if "/dashboard" not in location:
        fail(f"pengalihan tidak ke dashboard, melainkan ke {location}")

    status, location, body_text = fetch(opener, config["BIOMETRIC_BASE_URL"].rstrip("/") + "/dashboard")

    print(f"  buka /dashboard: HTTP {status}")

    if status != 200:
        fail(f"sesi tidak terbuka (HTTP {status}); tiket ada tapi sesi tidak bertahan")

    print("  isi halaman  :", "Halo" if "Halo" in body_text or "dashboard" in body_text.lower() else "(tidak diperiksa)")

    # Tiket sekali pakai: percobaan kedua harus ditolak.
    status, location, _ = fetch(opener, url)

    print(f"  pakai ulang  : HTTP {status} -> {location or '(tanpa Location)'}")

    if "/login" not in location:
        fail("tiket masih bisa dipakai dua kali")

    print()
    print("HASIL: jalur login sidik jari bekerja di server ini.")
    print("Bersihkan sisa datanya dengan perintah di docstring skrip ini.")

    return 0


if __name__ == "__main__":
    sys.exit(main())
