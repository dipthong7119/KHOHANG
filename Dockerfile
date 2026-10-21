FROM php:8.2-fpm
RUN docker-php-ext-install pdo_mysql
WORKDIR /var/www/html
COPY --chown=www-data:www-data app/public/ /var/www/html/
