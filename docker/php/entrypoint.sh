#!/usr/bin/env bash
set -e

cd /var/www

echo "[entrypoint] Preparing application..."

# --- .env ---
if [ ! -f .env ]; then
    echo "[entrypoint] .env not found, creating from .env.example"
    cp .env.example .env
fi

# --- PHP dependencies (bind-mounted volume hides whatever was installed at build time) ---
echo "[entrypoint] Installing composer dependencies"
composer install --no-interaction --prefer-dist --optimize-autoloader

# --- storage / bootstrap cache permissions (bind mount hides the build-time chown too) ---
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/testing storage/framework/views storage/logs storage/app/public bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache

# --- app key ---
if ! grep -q "^APP_KEY=base64:" .env 2>/dev/null; then
    echo "[entrypoint] Generating application key"
    php artisan key:generate --force
fi

# --- wait for the database to accept connections ---
echo "[entrypoint] Waiting for database at ${DB_HOST:-127.0.0.1}:${DB_PORT:-3306}..."
until php -r '
    $host = getenv("DB_HOST") ?: "127.0.0.1";
    $port = getenv("DB_PORT") ?: "3306";
    $user = getenv("DB_USERNAME") ?: "root";
    $pass = getenv("DB_PASSWORD") ?: "";
    try {
        new PDO("mysql:host={$host};port={$port}", $user, $pass);
        exit(0);
    } catch (Throwable $e) {
        exit(1);
    }
' > /dev/null 2>&1; do
    echo "[entrypoint] Database not ready yet, retrying in 2s..."
    sleep 2
done
echo "[entrypoint] Database is up."

# --- migrations (safe to run every start, Laravel tracks what's already applied) ---
echo "[entrypoint] Running migrations"
php artisan migrate --force

# --- storage symlink ---
if [ ! -L public/storage ]; then
    echo "[entrypoint] Linking public/storage"
    php artisan storage:link
fi

# --- seed once only ---
# PlanSeeder creates live Stripe products/prices and is NOT idempotent, so we must
# never let db:seed run more than once against the same database.
SEED_FLAG="storage/.seeded"
if [ ! -f "$SEED_FLAG" ]; then
    echo "[entrypoint] First run detected, seeding database"
    php artisan db:seed --force
    touch "$SEED_FLAG"
else
    echo "[entrypoint] Database already seeded, skipping (delete ${SEED_FLAG} to force reseed)"
fi

echo "[entrypoint] Setup complete, starting: $*"
exec "$@"
