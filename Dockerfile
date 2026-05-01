FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql mysqli

COPY . /var/www/html/

RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf
RUN sed -i 's/80/8080/g' /etc/apache2/ports.conf
RUN sed -i 's/:80>/:8080>/g' /etc/apache2/sites-enabled/000-default.conf

EXPOSE 8080