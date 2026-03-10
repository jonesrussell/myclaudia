FROM php:8.4-fpm-alpine

RUN apk add --no-cache sqlite-dev \
    && docker-php-ext-install pdo_sqlite

WORKDIR /srv
