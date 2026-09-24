# A rough stand-in for cPanel-style shared hosting, for local checks only:
# Apache with .htaccess enabled, the project root as the docroot (the
# layout the root .htaccess protects), and no persistent workers.
FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite \
    && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf
