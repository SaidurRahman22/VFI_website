# =============================================================================
# VFI API — PHP-FPM image (Laravel). Phase 0, step 3.
# Non-root, minimal extensions. Digest-pin the base in CI before signing.
# Build:  docker build -t vfi-api ./backend
# =============================================================================
# 8.4 to match the server. The 8.3 pin here was the same drift that had CI
# failing at composer install for weeks.
FROM php:8.4-fpm-alpine

# --- system + PHP extensions the app needs -----------------------------------
RUN apk add --no-cache \
        icu-dev oniguruma-dev postgresql-dev libzip-dev \
        freetype-dev libjpeg-turbo-dev libpng-dev $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql intl mbstring bcmath zip opcache gd exif \
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
# Inlined rather than COPY'd from deploy/php/: that path is at the repo root and
# the build context is ./backend, so the copy could never resolve and this image
# has never built. Written the same way as the opcache block above.
USER root
RUN { \
        echo "; Dedicated PHP-FPM pool for document uploads."; \
        echo "; Listens on :9001 so a slow ClamAV-gated upload never starves the"; \
        echo "; pool that serves the marketing site (:9000). Deliberately small."; \
        echo "[uploads]"; \
        echo "user = www-data"; \
        echo "group = www-data"; \
        echo "listen = 9001"; \
        echo "pm = dynamic"; \
        echo "pm.max_children = 6"; \
        echo "pm.start_servers = 2"; \
        echo "pm.min_spare_servers = 1"; \
        echo "pm.max_spare_servers = 3"; \
        echo "pm.max_requests = 200"; \
        echo "request_terminate_timeout = 120s"; \
    } > /usr/local/etc/php-fpm.d/zz-uploads.conf

USER www-data
EXPOSE 9000 9001
CMD ["php-fpm"]
