FROM composer:2 AS composer

FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libfreetype6-dev \
    libzip-dev libxml2-dev libpq-dev \
    libc-client-dev libkrb5-dev \
    git unzip \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-configure imap --with-kerberos --with-imap-ssl \
    && docker-php-ext-install \
        bcmath gd imap mysqli pdo pdo_pgsql pgsql simplexml zip

RUN a2enmod rewrite
COPY .docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY .docker/php.ini /usr/local/etc/php/conf.d/facturasolwed.ini
COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Solo el core de FacturaScripts — sin plugins preinstalados
COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && chown -R www-data:www-data /var/www/html \
    && chmod +x .docker/entrypoint.sh

ENTRYPOINT [".docker/entrypoint.sh"]
CMD ["apache2-foreground"]
