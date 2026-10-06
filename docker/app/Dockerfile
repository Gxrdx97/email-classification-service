# syntax=docker/dockerfile:1.7

# Local builds use the official PHP image and install the runtime here. The
# manual build-runtime workflow publishes this target for CI/CD to reuse.
ARG PHP_RUNTIME_IMAGE=php:8.4-fpm-bookworm
FROM ${PHP_RUNTIME_IMAGE} AS runtime

ARG INSTALL_RUNTIME=1
RUN if [ "$INSTALL_RUNTIME" = "1" ]; then \
        apt-get update \
        && apt-get install -y --no-install-recommends \
            $PHPIZE_DEPS \
            ca-certificates \
            curl \
            libicu-dev \
            libpq-dev \
            libzip-dev \
            nginx \
        && docker-php-ext-install -j"$(nproc)" \
            bcmath \
            intl \
            pcntl \
            pdo_pgsql \
            pgsql \
            zip \
        && pecl install redis \
        && docker-php-ext-enable redis \
        && rm -f /etc/nginx/sites-enabled/default \
        && rm -rf /var/lib/apt/lists/*; \
    fi

WORKDIR /var/www/html

COPY docker/php/99-app.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php/zz-app.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --chmod=0755 docker/app/entrypoint.sh /usr/local/bin/app-entrypoint
COPY --chmod=0755 docker/app/healthcheck.sh /usr/local/bin/healthcheck

EXPOSE 9000 8080
STOPSIGNAL SIGQUIT

FROM runtime AS vendor-production
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache,sharing=locked \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --no-autoloader

FROM runtime AS vendor-development
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache,sharing=locked \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install --no-interaction --prefer-dist --no-progress --no-scripts --no-autoloader

# Keep coverage tooling out of production images while making the local
# quality gate (Infection) available in the development target.
FROM runtime AS development-runtime
RUN pecl install pcov-1.0.12 \
    && docker-php-ext-enable pcov

FROM development-runtime AS development
WORKDIR /var/www/html
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY . .
COPY --from=vendor-development /var/www/html/vendor ./vendor
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --no-scripts \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

ENTRYPOINT ["/usr/local/bin/app-entrypoint"]
CMD ["php-fpm", "-F"]

FROM runtime AS production-build
WORKDIR /var/www/html
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --chown=www-data:www-data artisan composer.json composer.lock ./
COPY --chown=www-data:www-data app ./app
COPY --chown=www-data:www-data bootstrap ./bootstrap
COPY --chown=www-data:www-data config ./config
COPY --chown=www-data:www-data database/migrations ./database/migrations
COPY --chown=www-data:www-data public ./public
COPY --chown=www-data:www-data resources ./resources
COPY --chown=www-data:www-data routes ./routes
COPY --from=vendor-production --chown=www-data:www-data /var/www/html/vendor ./vendor
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --no-dev --optimize --no-scripts \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

FROM runtime AS production
WORKDIR /var/www/html
COPY --from=production-build --chown=www-data:www-data /var/www/html /var/www/html
ENTRYPOINT ["/usr/local/bin/app-entrypoint"]
CMD ["php-fpm", "-F"]
