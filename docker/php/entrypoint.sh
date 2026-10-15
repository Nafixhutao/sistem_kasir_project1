#!/bin/sh
set -e

APPDIR=/var/www/html
cd "$APPDIR"

echo "==> Menyiapkan izin folder storage"
mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

echo "==> Menyiapkan file .env"
if [ ! -f .env ]; then
    cat > .env <<EOF
APP_NAME="${APP_NAME:-Sultan Cell}"
APP_ENV="${APP_ENV:-production}"
APP_KEY="${APP_KEY:-}"
APP_DEBUG="${APP_DEBUG:-false}"
APP_URL="${APP_URL:-http://localhost:8080}"

LOG_CHANNEL="${LOG_CHANNEL:-stack}"
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL="${LOG_LEVEL:-error}"

DB_CONNECTION="${DB_CONNECTION:-mysql}"
DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_DATABASE="${DB_DATABASE:-sistem_project1}"
DB_USERNAME="${DB_USERNAME:-sultan}"
DB_PASSWORD="${DB_PASSWORD:-sultan123}"

BROADCAST_DRIVER=log
CACHE_DRIVER="${CACHE_DRIVER:-file}"
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
SESSION_DRIVER="${SESSION_DRIVER:-file}"
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE="${SESSION_SECURE_COOKIE:-true}"

MIDTRANS_SERVER_KEY="${MIDTRANS_SERVER_KEY:-}"
MIDTRANS_CLIENT_KEY="${MIDTRANS_CLIENT_KEY:-}"
MIDTRANS_MERCHANT_ID="${MIDTRANS_MERCHANT_ID:-}"
MIDTRANS_BASE_URL="${MIDTRANS_BASE_URL:-https://api.sandbox.midtrans.com}"
EOF
fi

if grep -q '^APP_KEY=$' .env; then
    echo "==> Membuat APP_KEY"
    php artisan key:generate --force
fi

echo "==> Menunggu database tersedia"
DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_DATABASE="${DB_DATABASE:-sistem_project1}"
DB_USERNAME="${DB_USERNAME:-sultan}"
DB_PASSWORD="${DB_PASSWORD:-sultan123}"

i=0
until php -r "try { new PDO('mysql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}'); exit(0); } catch (Throwable \$e) { exit(1); }" >/dev/null 2>&1; do
    i=$((i + 1))
    if [ "$i" -ge 60 ]; then
        echo "ERROR: Database tidak dapat diakses setelah 120 detik." >&2
        exit 1
    fi
    echo "    menunggu db... ($i)"
    sleep 2
done

echo "==> Menjalankan migrasi database"
php artisan migrate --force

echo "==> Menjalankan seeder"
php artisan db:seed --force

echo "==> Membangun cache produksi"
php artisan storage:link --force >/dev/null 2>&1 || true
php artisan package:discover --ansi >/dev/null 2>&1 || true
php artisan config:cache >/dev/null 2>&1 || true
php artisan view:cache >/dev/null 2>&1 || true

echo "==> Menjalankan perintah: $*"
exec "$@"