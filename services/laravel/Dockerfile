# syntax=docker/dockerfile:1
# PHP-FPM 只講 FastCGI，HTTP 由 infra/nginx 轉成 FastCGI 送進來（port 9000）

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

FROM php:8.4-fpm-alpine
# pdo_pgsql：連 PostgreSQL；redis：Laravel 的 cache / session / queue driver；opcache：正式環境效能
RUN apk add --no-cache postgresql-libs \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev \
 && docker-php-ext-install pdo_pgsql opcache \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && apk del .build-deps

WORKDIR /var/www/html
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN composer dump-autoload --optimize --no-dev \
 && chown -R www-data:www-data storage bootstrap/cache

USER www-data
EXPOSE 9000
CMD ["php-fpm"]
