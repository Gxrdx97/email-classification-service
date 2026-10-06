#!/bin/sh
set -eu

cd /var/www/html

if [ "${LARAVEL_CONFIG_CACHE:-false}" = "true" ] && [ -f artisan ]; then
    php artisan config:cache
fi

exec "$@"
