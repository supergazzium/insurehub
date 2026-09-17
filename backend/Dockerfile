# syntax=docker/dockerfile:1.7

# ─── Stage 1: composer install (vendor only) ──────────────────────────────
# This stage depends ONLY on the composer manifest, so it rebuilds solely when
# dependencies change — never on app-code edits. Post-install scripts that need
# the framework tree (e.g. artisan package:discover) are skipped here and run
# in the runtime stage where the full app tree is present.
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --prefer-dist \
        --optimize-autoloader

# ─── Stage 2: runtime — FrankenPHP + php extensions ────────────────────────
# FrankenPHP is single-process Caddy + PHP; no separate nginx + php-fpm to
# supervise. It supports HTTP/2/3, and its `--workers` mode keeps Laravel
# in memory across requests (opt-in later via APP_WORKER_MODE).
FROM dunglas/frankenphp:1-php8.4-alpine AS runtime

# System deps for the PHP extensions we install below. `curl` is also
# included so Coolify's UI-defined healthcheck (which overrides the
# Dockerfile HEALTHCHECK) can probe the container from inside.
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
        curl \
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

# composer binary — needed to finish the install (post-install scripts +
# authoritative classmap) once the app tree is present.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Runtime PHP config (opcache + upload limits + Bangkok tz).
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini

# Caddyfile — serves /app/public over HTTPS/HTTP with php_fastcgi baked in.
COPY docker/Caddyfile /etc/caddy/Caddyfile

WORKDIR /app

# Vendored dependencies from stage 1. This layer is cached and only busts when
# composer.json/lock change — NOT on app-code edits.
COPY --from=vendor --chown=www-data:www-data /app/vendor /app/vendor

# App code. This is the ONLY layer that carries application source, so a code
# change invalidates it (and only it) cleanly — the vendor layer above stays
# cached. Previously the code came bundled through the vendor stage's own
# `COPY . .`, which made code changes rebuild the whole vendor stage and, under
# BuildKit's cross-stage cache, could ship stale code to the runtime image.
COPY --chown=www-data:www-data . /app

# Finish the composer install now that the full app tree is present: runs the
# post-install hooks (package:discover) and rewrites the authoritative
# classmap so newly-added classes are autoloadable. `--no-scripts` is omitted
# on purpose so the discover hook runs.
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --optimize-autoloader \
        --classmap-authoritative

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
