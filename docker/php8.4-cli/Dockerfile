FROM php:8.4-cli

ARG UID=1000
ARG GID=1000
ARG APP_USER=app

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libonig-dev \
        libzip-dev \
    && docker-php-ext-install -j"$(nproc)" \
        mbstring \
        pdo_mysql \
        zip \
    && groupadd --gid "${GID}" "${APP_USER}" \
    && useradd --uid "${UID}" --gid "${GID}" --create-home --shell /bin/bash "${APP_USER}" \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer-cache

WORKDIR /app

USER app
