# =============================================================================
# Label Extraction Agent — multi-stage build
#
# Produces one artifact that runs as either the web process or the queue
# worker; only the command differs. Stages:
#
#   base        PHP 8.4 + FrankenPHP + the extensions both roles need
#   vendor      production composer dependencies  (--no-dev)
#   vendor-dev  the same, plus dev dependencies   (for the test stage)
#   assets      node build producing public/build
#   test        app + dev dependencies + tests    -> docker compose run --rm test
#   runtime     app + prod dependencies + assets  -> web and worker
#
# Deliberately avoids BuildKit-only syntax (cache mounts, the `# syntax=`
# directive) so it builds on the classic builder in Docker 20.10 too.
# =============================================================================


# -----------------------------------------------------------------------------
# base — shared foundation. Everything that both the build and the runtime need.
# -----------------------------------------------------------------------------
FROM dunglas/frankenphp:php8.4 AS base

WORKDIR /app

# pcntl is NOT optional: Laravel's queue worker needs it to trap SIGTERM.
#   Without it the worker cannot shut down gracefully, and compose's
#   stop_grace_period: 150s buys nothing — Docker just kills it mid-call.
# fileinfo backs finfo, which is how uploads are MIME-sniffed from content
#   rather than trusting the client's Content-Type.
# opcache matters because we are NOT using FrankenPHP worker mode; each
#   request re-parses the app, and opcache is what makes that cheap.
RUN install-php-extensions \
        pdo_pgsql \
        redis \
        gd \
        zip \
        pcntl \
        fileinfo \
        opcache

# curl is a system binary, not a PHP extension. The compose healthcheck shells
# out to it (`curl -fsS http://localhost:8080/up`); without it `web` never
# reports healthy and every depends_on: service_healthy blocks forever.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

# Take composer from its official image rather than building this stage FROM it.
# The composer image ships a different PHP with a different extension set, so
# resolving platform requirements there checks against the wrong target and the
# mismatch only surfaces at runtime. This way the platform check that runs is
# the platform we actually deploy.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# PHP configuration. Both files are deliberate, not boilerplate.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Upload limits must agree with UPLOAD_MAX_FILE_SIZE_KB (20480 = 20M) and
# UPLOAD_MAX_FILES_PER_REQUEST (20) in .env. PHP's defaults are 2M per file and
# an 8M post body — leave them and Laravel's validator never even sees a large
# upload, because PHP discards the request body first and hands over an empty
# $_FILES. The failure looks like a validation bug and is not one.
# post_max_size is a deliberate ceiling rather than 20 x 20M: a genuine
# 400MB request is an abuse vector, not a use case.
RUN { \
        echo 'upload_max_filesize = 20M'; \
        echo 'post_max_size = 200M'; \
        echo 'max_file_uploads = 20'; \
        echo 'memory_limit = 256M'; \
    } > "$PHP_INI_DIR/conf.d/uploads.ini"

RUN { \
        echo 'opcache.enable = 1'; \
        echo 'opcache.enable_cli = 0'; \
        echo 'opcache.memory_consumption = 128'; \
        echo 'opcache.max_accelerated_files = 20000'; \
        echo 'opcache.validate_timestamps = 0'; \
    } > "$PHP_INI_DIR/conf.d/opcache.ini"


# -----------------------------------------------------------------------------
# vendor — production dependencies only.
#
# Only composer.json/composer.lock are copied before `install`, so this layer is
# cached against the lockfile alone. Editing application code does not re-run a
# dependency install.
#
# --no-scripts because post-autoload-dump runs package:discover, which needs the
# full application and a writable bootstrap/cache — neither exists yet. It runs
# in the runtime stage instead, once the source is present.
# -----------------------------------------------------------------------------
FROM base AS vendor

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader


# -----------------------------------------------------------------------------
# vendor-dev — same, with dev dependencies. Feeds the test stage only.
# -----------------------------------------------------------------------------
FROM base AS vendor-dev

COPY composer.json composer.lock ./
RUN composer install \
        --no-scripts \
        --no-interaction \
        --prefer-dist


# -----------------------------------------------------------------------------
# assets — Vite build. Same lockfile-first caching trick.
#
# Nothing from node reaches the final image: only the built public/build output
# is copied forward, so there is no node, no npm and no node_modules in runtime.
# -----------------------------------------------------------------------------
FROM node:26-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

# The whole context (minus .dockerignore) — the Vite config, resources/ and the
# Tailwind content globs that scan resources/views and resources/js.
COPY . .
RUN npm run build


# -----------------------------------------------------------------------------
# runtime — what actually ships. No composer sources, no node, no secrets.
# -----------------------------------------------------------------------------
FROM base AS runtime

# FrankenPHP is built on Caddy and provisions TLS automatically by default.
# `:8080` pins it to plain HTTP on a NON-PRIVILEGED port, which is the whole
# reason this container can run as a non-root user without CAP_NET_BIND_SERVICE.
# Nothing outside the container cares which port it listens on internally.
ENV SERVER_NAME=":8080"

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

# .dockerignore excludes the CONTENTS of these directories (host logs, compiled
# Blade carrying absolute Windows paths, a bootstrap cache registering dev-only
# providers), so they arrive empty or missing and have to be recreated.
# Laravel throws "The bootstrap/cache directory must be present and writable"
# at boot otherwise.
# Written out longhand: /bin/sh here is dash, which has no brace expansion.
RUN mkdir -p \
        bootstrap/cache \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs

# Now that the application source is present, generate the optimised autoloader
# and run package:discover (the post-autoload-dump script skipped above).
RUN composer dump-autoload --no-dev --optimize --no-interaction

# NOT config:cache or route:cache. Those would bake build-time environment
# values into the image and break the twelve-factor contract — the same image
# has to run in dev and production with only the environment differing.

# Run as a non-root user. /data and /config are Caddy's state directories,
# created by the FrankenPHP base image and owned by root until now.
RUN groupadd --system app \
    && useradd --system --gid app --create-home --shell /bin/sh app \
    && chown -R app:app /app /data /config

USER app

EXPOSE 8080

# The base image's CMD (frankenphp run) is inherited. The worker service
# overrides it with `php artisan queue:work` in docker-compose.yml — same
# image, different role.
#
# The healthcheck lives in docker-compose.yml rather than here, so that the
# probe and the port mapping stay described in one place.


# -----------------------------------------------------------------------------
# test — dev dependencies and the suite. Never deployed.
#
# This stage exists because the runtime image installs --no-dev: it has neither
# Pest nor PHPUnit and could not run a test if asked. Built on demand:
#   docker compose run --rm test
# -----------------------------------------------------------------------------
FROM base AS test

ENV SERVER_NAME=":8080"

COPY --from=vendor-dev /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

RUN mkdir -p \
        bootstrap/cache \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs

RUN composer dump-autoload --optimize --no-interaction

RUN groupadd --system app \
    && useradd --system --gid app --create-home --shell /bin/sh app \
    && chown -R app:app /app /data /config

USER app

CMD ["php", "artisan", "test"]
