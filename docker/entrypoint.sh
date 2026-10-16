#!/bin/sh
# Boots the container: caches config from the runtime env, runs migrations and
# picks which processes supervisord starts (CONTAINER_ROLE = all | web | worker | reverb).
# "reverb" is the WebSocket server alone, deployed as its own service with this same image.
set -e

cd /var/www/html

case "${CONTAINER_ROLE:-all}" in
    web)    export RUN_WEB=true  RUN_WORKER=false RUN_REVERB=false ;;
    worker) export RUN_WEB=false RUN_WORKER=true  RUN_REVERB=false ;;
    reverb) export RUN_WEB=false RUN_WORKER=false RUN_REVERB=true ;;
    *)      export RUN_WEB=true  RUN_WORKER=true  RUN_REVERB=false ;;
esac

if [ -z "$APP_KEY" ]; then
    echo "APP_KEY is not set. Generate one with: php artisan key:generate --show" >&2
    exit 1
fi

# Only one container should migrate: the one serving the web.
if [ "$RUN_WEB" = "true" ] && [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force --isolated
fi

php artisan optimize
chown -R www-data:www-data storage bootstrap/cache

exec "$@"
