FROM php:8.4-fpm AS base

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql redis pcntl bcmath intl zip opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Development: source is bind-mounted, vendor lives in a named volume.
# PHP-FPM, queue workers, the scheduler and artisan all run as www-data so that
# files written by one process (logs, exports) are readable by the others.
# UID/GID can be matched to the host user on Linux to keep bind mounts writable.
FROM base AS development
ARG UID=1000
ARG GID=1000
RUN groupmod -o -g ${GID} www-data \
    && usermod -o -u ${UID} -g ${GID} www-data \
    && mkdir -p /var/www/html/vendor /tmp/composer \
    && chown www-data:www-data /var/www/html/vendor /tmp/composer
ENV COMPOSER_HOME=/tmp/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
USER www-data

# Production: immutable image with optimized autoloader and OPcache.
FROM base AS production
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
