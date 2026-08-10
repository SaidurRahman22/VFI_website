# =============================================================================
# VFI API — PHP-FPM image (Laravel). Phase 0, step 3.
# Non-root, minimal extensions. Digest-pin the base in CI before signing.
# Build:  docker build -t vfi-api ./backend
# =============================================================================
FROM php:8.3-fpm-alpine

# --- system + PHP extensions the app needs -----------------------------------
RUN apk add --no-cache \
        icu-dev oniguruma-dev postgresql-dev libzip-dev $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql intl mbstring bcmath zip opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

# --- opcache: production posture ---------------------------------------------
RUN { \
        echo "opcache.enable=1"; \
        echo "opcache.validate_timestamps=0"; \
        echo "opcache.max_accelerated_files=20000"; \
        echo "opcache.memory_consumption=192"; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# --- composer (from the official image) --------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install deps first for better layer caching.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

# App code.
COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache

# --- dedicated uploads pool on :9001 (Phase 0, step 3) -----------------------
COPY deploy/php/uploads.pool.conf /usr/local/etc/php-fpm.d/zz-uploads.conf

USER www-data
EXPOSE 9000 9001
CMD ["php-fpm"]
