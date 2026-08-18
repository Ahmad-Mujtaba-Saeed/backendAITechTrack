FROM php:8.3-fpm

# ===============================
# System dependencies
# ===============================
RUN apt-get update && apt-get install -y \
    git \
    curl \
    zip \
    unzip \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    python3 \
    python3-pip \
    tesseract-ocr \
    poppler-utils \
    && docker-php-ext-install \
        pdo_mysql \
        mbstring \
        bcmath \
        gd \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*


# ===============================
# Python OCR dependencies
# ===============================
RUN pip3 install --no-cache-dir --break-system-packages \
    pytesseract \
    pdf2image \
    pillow

# ===============================
# Composer
# ===============================
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# ===============================
# Composer dependencies (cached)
# ===============================
COPY composer.json composer.lock ./
RUN composer install --prefer-dist --no-interaction --no-scripts

# ===============================
# Application source
# ===============================
COPY . .

# ===============================
# FINAL permissions (MUST be last)
# ===============================
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

CMD ["php-fpm"]
