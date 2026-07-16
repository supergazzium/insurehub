# syntax=docker/dockerfile:1.7

# ─── Stage 1: composer install (vendor cache) ─────────────────────────────
FROM composer:2 AS vendor

WORKDIR /app

# Copy only the manifest first so composer's layer cache survives code-only
# changes. Post-install scripts (`php artisan package:discover`) need the
# app tree present, so we defer them until the app is copied.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --prefer-dist \
        --optimize-autoloader

# Copy the rest of the app and finish the install (runs the post-install
# hooks that need the framework tree, e.g. artisan package:discover).
COPY . .
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --optimize-autoloader \
        --classmap-authoritative

# ─── Stage 2: runtime — FrankenPHP + php extensions ────────────────────────
# FrankenPHP is single-process Caddy + PHP; no separate nginx + php-fpm to
# supervise. It supports HTTP/2/3, and its `--workers` mode keeps Laravel
# in memory across requests (opt-in later via APP_WORKER_MODE).
FROM dunglas/frankenphp:1-php8.4-alpine AS runtime

# System deps for the PHP extensions we install below.
RUN apk add --no-cache \
        icu-libs \
        libpng \
        libjpeg-turbo \
        libwebp \
        freetype \
        libzip \
        oniguruma \
        tzdata \
        bash \
    && cp /usr/share/zoneinfo/Asia/Bangkok /etc/localtime \
    && echo "Asia/Bangkok" > /etc/timezone

# PHP extensions. install-php-extensions ships with the FrankenPHP image and
# handles the apk build deps + configure flags for us.
RUN install-php-extensions \
        pdo_mysql \
        mbstring \
        intl \
        gd \
        zip \
        opcache \
        bcmath \
        exif \
        pcntl

# Runtime PHP config (opcache + upload limits + Bangkok tz).
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini

# Caddyfile — serves /app/public over HTTPS/HTTP with php_fastcgi baked in.
COPY docker/Caddyfile /etc/caddy/Caddyfile

WORKDIR /app

# Bring in vendored code from stage 1 + the app tree. `--chown` avoids a
# separate `chown -R` layer (which would double the image size).
COPY --from=vendor --chown=www-data:www-data /app /app

# Laravel needs write access to storage/ and bootstrap/cache. Pre-warm the
# cache dirs so a bind-mount volume doesn't need to be pre-created.
RUN set -eux; \
    mkdir -p storage/framework/{cache,sessions,views} \
             storage/logs \
             bootstrap/cache; \
    chown -R www-data:www-data storage bootstrap/cache; \
    chmod -R ug+rwX storage bootstrap/cache

# Entrypoint runs migrations + config/route/view caching once per container
# start, then hands off to FrankenPHP.
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# FrankenPHP listens on 80/443. Coolify's proxy terminates TLS in front, so
# in most Coolify setups you only need :80.
EXPOSE 80 443

# HEALTHCHECK: hits the JSON /up route Laravel 11+ registers by default.
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD wget -qO- http://127.0.0.1/up || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
