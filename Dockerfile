# syntax=docker/dockerfile:1

FROM node:22-bookworm-slim AS node

FROM php:8.4-fpm-bookworm AS app

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libzip-dev unzip \
    && docker-php-ext-install pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules

RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

WORKDIR /var/www/html

COPY composer.json composer.lock ./

RUN composer install --no-interaction --no-scripts --prefer-dist --optimize-autoloader

COPY package.json package-lock.json ./

RUN npm ci

COPY . .

RUN composer dump-autoload --optimize \
    && npm run build \
    && npm cache clean --force \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000

FROM nginx:1.28-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public

EXPOSE 80
