#!/usr/bin/env bash
set -e
cd /var/www/html

# Make sure the container has everything it needs to boot. Safe to run for
# every service; the heavy one-time work (migrate/seed) only runs for `init`.
ensure_ready() {
    if [ ! -f vendor/autoload.php ]; then
        echo "[entrypoint] installing composer dependencies..."
        composer install --no-interaction --prefer-dist --no-progress
    fi

    if [ ! -f .env ]; then
        echo "[entrypoint] creating .env from .env.example"
        cp .env.example .env
    fi

    mkdir -p storage/framework/cache storage/framework/sessions \
             storage/framework/views storage/logs bootstrap/cache
    chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

    if ! grep -q '^APP_KEY=base64:' .env; then
        echo "[entrypoint] generating APP_KEY"
        php artisan key:generate --force
    fi
}

case "$1" in
    init)
        ensure_ready
        echo "[entrypoint] running migrations..."
        php artisan migrate --force
        echo "[entrypoint] seeding humans (idempotent-ish; ignore errors on re-run)..."
        php artisan db:seed --class="Database\\Seeders\\HumanSeeder" --force || true
        echo "[entrypoint] init complete."
        exit 0
        ;;
    *)
        ensure_ready
        exec "$@"
        ;;
esac
