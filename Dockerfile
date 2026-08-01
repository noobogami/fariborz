# ── Research Agent PHP image ─────────────────────────────────────────────────
# Used by three services (app / worker / scheduler) — same image, different
# command. Alpine + PHP-FPM 8.3 with the extensions Laravel + this project need.
FROM php:8.3-fpm-alpine

# System + build deps. Build deps ($PHPIZE_DEPS) are removed after compiling
# the PECL extensions to keep the image small.
RUN apk add --no-cache \
        git curl bash libzip-dev icu-dev oniguruma-dev linux-headers \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql mysqli mbstring pcntl bcmath opcache zip intl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# Composer (from the official image).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install PHP deps first for better layer caching. (composer.lock may not exist
# yet in a fresh scaffold — the glob keeps the COPY valid either way.)
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --no-scripts --no-progress --prefer-dist --no-autoloader || true

# App code.
COPY . .
RUN composer install --no-interaction --no-progress --prefer-dist \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 9000
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
