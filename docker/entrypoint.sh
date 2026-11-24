#!/bin/sh
# Container entrypoint.
#   entrypoint.sh all     -> migrate, cache, then nginx + php-fpm + worker
#   entrypoint.sh worker  -> migrate, then only `php artisan queue:work`
#   entrypoint.sh web     -> migrate, cache, then nginx + php-fpm (no worker)
set -e

cd /var/www/html
ROLE="${1:-all}"

# --- Persistent data on the /data volume (when one is mounted) -------------
# SQLite database: DB_DATABASE should point at /data/database.sqlite.
if [ -d /data ]; then
    case "${DB_DATABASE:-}" in
        /data/*)
            mkdir -p "$(dirname "$DB_DATABASE")"
            [ -f "$DB_DATABASE" ] || touch "$DB_DATABASE"
            ;;
    esac
    # Locally stored result images must survive restarts and be visible to
    # both the web server and the worker -> keep them on the volume.
    mkdir -p /data/public-storage
    if [ ! -L storage/app/public ]; then
        cp -rn storage/app/public/. /data/public-storage/ 2>/dev/null || true
        rm -rf storage/app/public
        ln -s /data/public-storage storage/app/public
    fi
    chown -R www-data:www-data /data || true
fi

# --- Laravel boot steps ------------------------------------------------------
php artisan migrate --force
php artisan storage:link || true

if [ "$ROLE" != "worker" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

chown -R www-data:www-data storage bootstrap/cache || true

# --- Run ---------------------------------------------------------------------
case "$ROLE" in
    worker)
        exec php artisan queue:work --tries=3 --timeout=120 --sleep=3
        ;;
    web)
        # supervisor config also starts the worker; strip it for web-only.
        sed -i '/\[program:queue-worker\]/,/^$/d' /etc/supervisor/conf.d/app.conf
        exec supervisord -n -c /etc/supervisor/supervisord.conf
        ;;
    *)
        exec supervisord -n -c /etc/supervisor/supervisord.conf
        ;;
esac
