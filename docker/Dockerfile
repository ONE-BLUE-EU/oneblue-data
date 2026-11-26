# syntax=docker/dockerfile:1
#
# One image definition for every environment.
#   target "dev"  -> code is bind-mounted from the host, Xdebug available.
#   target "prod" -> code, vendor/ and the built frontend are baked in.
# Both share the same "base" stage, so PHP, extensions and Apache are identical.

# Base images come from .env.images via compose build args (no defaults here,
# so a build that bypasses run.sh fails instead of silently using other versions).
ARG PHP_IMAGE
ARG NODE_IMAGE
ARG COMPOSER_IMAGE
ARG PHP_EXT_INSTALLER_IMAGE
ARG FRONTEND_REPO
ARG FRONTEND_REF

########################################################################
# Decoupled frontend, built from its own repo -> build/static
# BuildKit re-resolves FRONTEND_REF on every build, so a new commit on the
# branch is picked up without --no-cache.
########################################################################
# COPY --from can't take variables, so helper images get named stages.
FROM ${COMPOSER_IMAGE} AS composer-bin
FROM ${PHP_EXT_INSTALLER_IMAGE} AS php-ext-installer

FROM ${NODE_IMAGE} AS frontend
ARG FRONTEND_REPO
ARG FRONTEND_REF
WORKDIR /app
ADD ${FRONTEND_REPO}#${FRONTEND_REF} ./
RUN rm -rf build \
 && if [ -f package-lock.json ]; then npm ci --loglevel=error --no-audit --no-fund; \
    else npm install --loglevel=error --no-audit --no-fund; fi \
 && npm run build

########################################################################
# Runtime shared by dev and prod
########################################################################
FROM ${PHP_IMAGE} AS base

COPY --from=php-ext-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      git unzip patch curl ca-certificates mariadb-client msmtp \
 && rm -rf /var/lib/apt/lists/* \
 && install-php-extensions gd pdo_mysql mysqli zip intl opcache bcmath apcu exif

ENV APACHE_DOCUMENT_ROOT=/var/www/html/docroot \
    PATH=/var/www/html/vendor/bin:$PATH \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

COPY docker/apache/vhost.conf /etc/apache2/sites-available/000-default.conf
RUN a2enmod rewrite headers expires remoteip \
 && echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf \
 && a2enconf servername \
 && sed -ri 's/^ServerTokens .*/ServerTokens Prod/; s/^ServerSignature .*/ServerSignature Off/' /etc/apache2/conf-available/security.conf

COPY docker/php/base.ini $PHP_INI_DIR/conf.d/10-app.ini
COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint
COPY docker/cron.sh /usr/local/bin/app-cron
RUN chmod +x /usr/local/bin/app-entrypoint /usr/local/bin/app-cron

WORKDIR /var/www/html
ENTRYPOINT ["app-entrypoint"]
CMD ["apache2-foreground"]

########################################################################
# Development: same runtime + Xdebug, www-data mapped to the host user
########################################################################
FROM base AS dev
ARG UID=1000
ARG GID=1000
RUN install-php-extensions xdebug \
 && groupmod -o -g "${GID}" www-data \
 && usermod -o -u "${UID}" -g "${GID}" www-data
COPY docker/php/dev.ini $PHP_INI_DIR/conf.d/20-env.ini
ENV APP_ENV=dev

########################################################################
# Composer install (no dev dependencies) for the production image
########################################################################
FROM base AS build
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader
COPY . .
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader \
 && rm -rf /root/.composer/cache

########################################################################
# Production
########################################################################
FROM base AS prod
COPY docker/php/prod.ini $PHP_INI_DIR/conf.d/20-env.ini
COPY --from=build /var/www/html /var/www/html
COPY --from=frontend /app/build /var/www/html/docroot/frontend/build
RUN mkdir -p docroot/sites/default/files private \
 && chown -R www-data:www-data docroot/sites/default/files private
ENV APP_ENV=prod
