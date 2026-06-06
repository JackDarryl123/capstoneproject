FROM php:8.2-apache

RUN apt-get update \
  && apt-get install -y --no-install-recommends \
    libcurl4-openssl-dev \
    libpng-dev \
    libzip-dev \
    unzip \
    zip \
  && docker-php-ext-install curl gd mysqli pdo_mysql zip \
  && a2enmod rewrite \
  && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . /var/www/html

RUN composer install --no-dev --no-interaction --prefer-dist \
  || composer update --no-dev --no-interaction --prefer-dist

RUN chown -R www-data:www-data \
    /var/www/html/cache \
    /var/www/html/temp_qr \
    /var/www/html/uploads \
    /var/www/html/users/temp_qr \
  || true

COPY docker/app-entrypoint.sh /usr/local/bin/app-entrypoint.sh
RUN chmod +x /usr/local/bin/app-entrypoint.sh

ENTRYPOINT ["app-entrypoint.sh"]
CMD ["apache2-foreground"]
