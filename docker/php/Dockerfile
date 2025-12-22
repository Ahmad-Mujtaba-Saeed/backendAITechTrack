FROM php:8.3-fpm

# System dependencies
RUN apt-get update && apt-get install -y \
    git curl zip unzip \
    libpng-dev libonig-dev libxml2-dev \
    && docker-php-ext-install \
    pdo_mysql mbstring bcmath gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Composer 2.8.x
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Copy composer files first (layer cache)
COPY composer.json composer.lock ./
RUN composer install --prefer-dist --no-interaction --no-scripts

# Copy application source
COPY . .

# Permissions for Laravel runtime
RUN chown -R www-data:www-data storage bootstrap/cache

CMD ["php-fpm"]
