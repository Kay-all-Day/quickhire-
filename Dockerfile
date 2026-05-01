FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql mysqli

COPY . /var/www/html/

RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf \
    && a2enmod rewrite \
    && mkdir -p /var/www/html/uploads/verification \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/uploads

# Build a startup script that reads $PORT at runtime and reconfigures Apache.
# This is required because Railway injects PORT as a runtime env var.
RUN echo '#!/bin/sh' > /start.sh \
    && echo 'PORT="${PORT:-8080}"' >> /start.sh \
    && echo 'sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf' >> /start.sh \
    && echo 'sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-enabled/000-default.conf' >> /start.sh \
    && echo 'exec apache2-foreground' >> /start.sh \
    && chmod +x /start.sh

EXPOSE 8080

CMD ["/start.sh"]
