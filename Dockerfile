FROM composer:2 AS composer

FROM php:8.2-apache

# System dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libfreetype6-dev \
    libzip-dev libxml2-dev libpq-dev \
    libc-client-dev libkrb5-dev \
    git unzip \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-configure imap --with-kerberos --with-imap-ssl \
    && docker-php-ext-install \
        bcmath gd imap mysqli pdo pdo_pgsql pgsql simplexml zip

# Apache
RUN a2enmod rewrite
COPY .docker/apache.conf /etc/apache2/sites-available/000-default.conf

# PHP config
COPY .docker/php.ini /usr/local/etc/php/conf.d/facturasolwed.ini

# Composer
COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Código fuente (Core + Plugins Solwed)
COPY . .

# Dependencias PHP (vendor en imagen)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Permisos
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod +x .docker/entrypoint.sh

ENTRYPOINT [".docker/entrypoint.sh"]
CMD ["apache2-foreground"]
