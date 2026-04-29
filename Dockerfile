FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql mysqli

COPY . /var/www/html/

RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

EXPOSE 80