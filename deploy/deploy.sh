#!/usr/bin/env bash
#
# Deploy / update login.heloklin.com on the Heloklin server.
#
# Run as the `Heloklin` user from anywhere:
#     deploy/deploy.sh
#
# Skips: seeding is NOT run automatically (passwords live in .env and the
# seeder is idempotent, but re-seeding is a deliberate action), and the
# automated test-results sync (that needs dev dependencies, see PRODUCTION.md).
set -euo pipefail

APP_DIR=/www/wwwroot/login.heloklin.com/fingerprint-test
cd "$APP_DIR"

echo "==> 1/8 code"
if git rev-parse --git-dir >/dev/null 2>&1; then
    git pull --ff-only
fi

echo "==> 2/8 PHP dependencies (production, no dev)"
composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction

echo "==> 3/8 frontend assets"
npm ci --no-audit --no-fund
npm run build

echo "==> 4/8 database migrations"
php artisan migrate --force

echo "==> 5/8 permissions (storage, bootstrap/cache, .env)"
sudo chown -R Heloklin:www storage bootstrap/cache .env
sudo chmod -R 775 storage bootstrap/cache
sudo chmod 640 .env
find storage bootstrap/cache -name .gitignore -exec chmod 664 {} \;

echo "==> 6/8 caches (dijalankan sebagai user web www)"
# BladeCompiler memanggil touch() pada view terkompilasi setiap kali view
# sumbernya lebih baru, dan touch() dengan mtime eksplisit hanya boleh oleh
# PEMILIK file. Bila cache view dimiliki Heloklin, setiap view yang stale
# membuat halaman 500: "touch(): Utime failed: Operation not permitted".
# Karena itu compile dijalankan sebagai www dan cache view dipastikan www.
sudo -u www php artisan optimize
sudo chown -R www:www storage/framework/views

echo "==> 7/8 verify from the origin (loopback, TLS terminated at nginx)"
curl -s -o /dev/null -w '  /up            -> %{http_code}\n' \
    --resolve login.heloklin.com:443:127.0.0.1 https://login.heloklin.com/up
curl -s -o /dev/null -w '  /login         -> %{http_code}\n' \
    --resolve login.heloklin.com:443:127.0.0.1 https://login.heloklin.com/login

echo "==> 8/8 done"
echo "     log: $APP_DIR/storage/logs/laravel.log"
echo "     note: PHP OPcache is not enabled on this box; no PHP-FPM reload needed."
