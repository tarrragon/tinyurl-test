# syntax=docker/dockerfile:1
# 容器內同時跑 Nginx 與 PHP-FPM：對外講 HTTP（8080，與 Go 版相同），入口的 infra/nginx 才能把
# 兩個後端放進同一個 upstream；容器內的 Nginx 再把請求轉成 FastCGI 交給 PHP-FPM（127.0.0.1:9000）

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

FROM php:8.5-fpm-alpine
# pdo_pgsql：連 PostgreSQL；redis：Laravel 的 cache / session / queue driver
# opcache 從 PHP 8.5 起編進 PHP 本體，不再另外安裝（docker-php-ext-install opcache 會失敗）
# nginx 與 bash：容器內的 HTTP 入口與監看兩個程序的 entrypoint（見 docker/entrypoint.sh）
RUN apk add --no-cache postgresql-libs nginx bash \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev \
 && docker-php-ext-install pdo_pgsql \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && apk del .build-deps

WORKDIR /var/www/html
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN composer dump-autoload --optimize --no-dev \
 && chown -R www-data:www-data storage bootstrap/cache

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php-fpm-overrides.conf /usr/local/etc/php-fpm.d/zzz-overrides.conf

USER www-data
EXPOSE 8080
CMD ["/var/www/html/docker/entrypoint.sh"]
