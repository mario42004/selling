FROM php:8.4-apache

RUN docker-php-ext-install pdo_mysql

COPY public/ /var/www/html/

RUN mkdir -p /var/www/html/uploads
RUN chown -R www-data:www-data /var/www/html
