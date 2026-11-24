# syntax=docker/dockerfile:1
# ---------------------------------------------------------------------------
# Pixelforge Image Generator — production image
#
# One image, two roles:
#   * combined (default CMD): nginx + php-fpm + `queue:work` under supervisor.
#     Use this on platforms where web and worker cannot share a filesystem
#     (e.g. Railway free tiers: a volume attaches to ONE service, and the
#     SQLite file + locally stored result images must be shared).
#   * worker-only: set the service command to
#         /usr/local/bin/entrypoint.sh worker
#     and use it next to a web service when the platform gives both services
#     the same database (e.g. managed Postgres) — see DEPLOY.md.
# ---------------------------------------------------------------------------

# --- Stage 1: production PHP dependencies ----------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction \
        --no-scripts --optimize-autoloader

# --- Stage 2: build the Vue/Inertia front-end assets -----------------------
# resources/js/app.js imports ZiggyVue from vendor/tightenco/ziggy, so the
# Ziggy package must be present in this stage before `npm run build`.
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js jsconfig.json postcss.config.js tailwind.config.js ./
COPY resources ./resources
COPY public ./public
COPY --from=vendor /app/vendor/tightenco/ziggy ./vendor/tightenco/ziggy
RUN npm run build

# --- Stage 3: runtime -------------------------------------------------------
FROM php:8.3-fpm-bookworm AS runtime

# System packages: nginx + supervisor for the combined container, sqlite3
# CLI for debugging, everything else is compiled PHP extensions.
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx supervisor sqlite3 libsqlite3-dev libzip-dev unzip \
    && docker-php-ext-install pdo_sqlite bcmath pcntl zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Application code (see .dockerignore: no vendor/, node_modules/, .env, .git)
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# Container configuration
COPY docker/nginx.conf /etc/nginx/sites-available/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/app.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Writable paths. /data is the persistent volume mount point: it holds the
# SQLite database (DB_DATABASE=/data/database.sqlite) and the downloaded
# result images (storage/app/public is symlinked there by the entrypoint).
RUN mkdir -p /data \
        storage/app/public storage/framework/cache storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data /var/www/html /data

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["all"]
