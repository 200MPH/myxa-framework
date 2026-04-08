FROM php:8.4-cli

ARG UID=1000
ARG GID=1000
ARG APP_USER=app

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libonig-dev \
        libzip-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" \
        mbstring \
        pdo_mysql \
        pdo_pgsql \
        zip \
    && pecl install pcov redis \
    && docker-php-ext-enable pcov redis \
    && groupadd --gid "${GID}" "${APP_USER}" \
    && useradd --uid "${UID}" --gid "${GID}" --create-home --shell /bin/bash "${APP_USER}" \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php8.4-cli/conf.d/pcov.ini /usr/local/etc/php/conf.d/zz-myxa-pcov.ini

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer-cache

WORKDIR /app

USER app
