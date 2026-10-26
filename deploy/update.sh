#!/usr/bin/env bash
# Memperbarui NetWatch server di Linux dengan aman: backup -> mode pemeliharaan -> composer -> migrasi -> cache -> aktif lagi.
# Pakai: salin paket baru ke /var/www/netwatch (timpa folder server/, agent/, docs/, deploy/; JANGAN timpa server/.env),
# lalu jalankan skrip ini sebagai user deploy (bukan root) yang bisa sudo.
set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/netwatch/server}"
WEB_USER="${WEB_USER:-www-data}"
PHP_FPM="${PHP_FPM:-php8.3-fpm}"

artisan() { sudo -u "$WEB_USER" php "$APP_DIR/artisan" "$@"; }

cd "$APP_DIR"
[ -f .env ] || { echo "ERROR: $APP_DIR/.env tidak ada." >&2; exit 1; }

echo "==> 1/7 Backup database sebelum pembaruan"
artisan netwatch:backup

echo "==> 2/7 Mode pemeliharaan"
artisan down --retry=60
trap 'echo "GAGAL. Mengaktifkan kembali aplikasi (periksa log, lalu pulihkan dari backup bila perlu)." >&2; artisan up || true' ERR

echo "==> 3/7 Dependensi (composer; skrip artisan dijalankan sebagai $WEB_USER)"
composer install --no-dev --optimize-autoloader --no-interaction --no-scripts
artisan package:discover --ansi

echo "==> 4/7 Izin folder tulis"
sudo chown -R "$WEB_USER":"$WEB_USER" storage bootstrap/cache

echo "==> 5/7 Migrasi database"
artisan migrate --force

echo "==> 6/7 Cache konfigurasi, route, view"
artisan optimize:clear
artisan config:cache
artisan route:cache
artisan view:cache

echo "==> 7/7 Muat ulang PHP-FPM dan aktifkan aplikasi"
sudo systemctl reload "$PHP_FPM"
artisan up
trap - ERR

echo "Selesai. Cek: artisan schedule:list, halaman Administrasi > Database & Backup."
