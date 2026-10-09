# syntax=docker/dockerfile:1.7
#
# Dockerfile minimal commun : deux images, identiques en staging et en production (seul l'environnement change).
#   - cible « php » : PHP-FPM + code + dépendances ; sert aussi aux workers Messenger et au cron.
#   - cible « web » : Nginx + fichiers publics + page de maintenance (deploy/maintenance).
# Adapter : extensions PHP, étape des assets (AssetMapper ici ; avec Webpack Encore, ajouter une étape Node qui compile public/build).

ARG PHP_VERSION=8.4
ARG NGINX_VERSION=1.28

# ---------------------------------------------------------------- PHP de base
FROM php:${PHP_VERSION}-fpm-alpine AS php-base

COPY --from=ghcr.io/mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions apcu intl opcache pdo_pgsql zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1
WORKDIR /srv/app

# ---------------------------------------------------------------- Construction
FROM php-base AS build

COPY composer.json composer.lock symfony.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --prefer-dist --no-scripts --no-autoloader --no-progress

COPY . .
# Valeurs factices, uniquement pour construire : les vraies sont fournies au démarrage (app.env).
ENV APP_ENV=prod APP_DEBUG=0 APP_SECRET=build DATABASE_URL="postgresql://build@127.0.0.1/build?serverVersion=16"
RUN composer dump-autoload --classmap-authoritative --no-dev \
    && composer run-script --no-dev post-install-cmd \
    && if [ -f importmap.php ]; then bin/console importmap:install && bin/console asset-map:compile; fi \
    && rm -rf var/cache

# ---------------------------------------------------------------- Image php
FROM php-base AS php

COPY --from=build --chown=www-data:www-data /srv/app /srv/app
RUN mkdir -p var/cache var/log var/sessions && chown -R www-data:www-data var

ARG APP_VERSION=dev
ARG APP_COMMIT=unknown
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    APP_VERSION=${APP_VERSION} \
    APP_COMMIT=${APP_COMMIT}

USER www-data
CMD ["php-fpm"]

# ---------------------------------------------------------------- Image web
FROM nginx:${NGINX_VERSION}-alpine AS web

ENV AUTH_BASIC=off
COPY docker/nginx.conf.template /etc/nginx/templates/default.conf.template
RUN rm -f /etc/nginx/conf.d/default.conf && touch /etc/nginx/htpasswd
COPY --from=build /srv/app/public /srv/app/public
# La page de maintenance voyage dans l'image : aucun fichier côté serveur ne peut diverger de la CI.
COPY deploy/maintenance /srv/maintenance
