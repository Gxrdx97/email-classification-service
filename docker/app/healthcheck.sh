#!/bin/sh
set -eu

case "${1:-}" in
    fpm)
        php -r 'exit(@fsockopen("127.0.0.1", 9000) ? 0 : 1);'
        ;;
    web)
        curl --fail --silent --show-error --max-time 5 \
            http://127.0.0.1:8080/api/health/live >/dev/null
        ;;
    worker)
        php -r '$command = file_get_contents("/proc/1/cmdline"); exit(is_string($command) && str_contains($command, "queue:work") ? 0 : 1);'
        ;;
    scheduler)
        php -r '$command = file_get_contents("/proc/1/cmdline"); exit(is_string($command) && str_contains($command, "schedule:work") ? 0 : 1);'
        ;;
    *)
        exit 2
        ;;
esac
