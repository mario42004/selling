FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev \
    && docker-php-ext-install pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

COPY php-production.ini /usr/local/etc/php/conf.d/zz-production.ini
COPY public/ /var/www/html/

RUN mkdir -p /var/www/html/uploads
RUN chown -R www-data:www-data /var/www/html
