# PHP 8.3 — the version recorded in the thesis (§4.2, §3.2.2).
# The image is pinned to 8.3 on purpose: the host machine may run a newer PHP,
# the deployable unit must not.
#
# `development` is the last stage, so a build without --target still produces
# the image docker-compose.yml expects. `production` is built by
# docker-compose.prod.yml, which names the target explicitly.
FROM php:8.3-fpm-alpine AS base

# Build tools are removed again at the end of the layer; the -dev packages are
# kept because they carry the runtime shared libraries (libpq, icu, libzip).
RUN apk add --no-cache \
        icu-dev \
        libzip-dev \
        linux-headers \
        postgresql-dev \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        pgsql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && pecl clear-cache \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

WORKDIR /var/www/html

EXPOSE 9000

ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# Production: the source tree is baked in, not bind-mounted, and vendor/ is
# installed at build time without the development dependencies.
# ---------------------------------------------------------------------------
FROM base AS production

ENV COMPOSER_ALLOW_SUPERUSER=1

# composer.json and composer.lock first, so a commit that touches no dependency
# reuses the installed layer.
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY backend/ ./

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && mkdir -p storage/logs storage/framework/cache/data \
        storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/php/php.prod.ini /usr/local/etc/php/conf.d/99-app.ini

# ---------------------------------------------------------------------------
FROM base AS development
