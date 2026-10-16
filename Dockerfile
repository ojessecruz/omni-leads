# Production image: nginx + php-fpm + Horizon + scheduler in one container (supervisord).
# The same image runs the Reverb WebSocket server with CONTAINER_ROLE=reverb (port 8080).
# Runtime config comes from environment variables (Easypanel "Environment" tab).

# --- PHP dependencies -------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --ignore-platform-reqs

# --- Front-end assets (app.css imports Flux's CSS from vendor/) --------------
FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund
COPY --from=vendor /app/vendor ./vendor
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# --- Runtime ------------------------------------------------------------------
FROM php:8.5-fpm-alpine AS app

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN apk add --no-cache nginx supervisor \
    && install-php-extensions pdo_pgsql redis intl bcmath pcntl zip opcache

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan package:discover --ansi \
    && rm -f public/hot \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x /usr/local/bin/entrypoint

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    CONTAINER_ROLE=all

EXPOSE 80 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD if [ "$CONTAINER_ROLE" = "reverb" ]; then url=http://127.0.0.1:8080/up; else url=http://127.0.0.1/up; fi; \
        wget -qO- "$url" > /dev/null || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
