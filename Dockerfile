FROM php:8.3-cli-alpine

# Install system dependencies
RUN apk add --no-cache \
    bash \
    git \
    curl \
    unzip \
    icu-dev \
    oniguruma-dev \
    libzip-dev \
    mysql-client \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        intl \
        bcmath \
        zip \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy composer files first for layer caching
COPY composer.json composer.lock* ./

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction 2>/dev/null || \
    composer install --no-interaction --no-scripts

# Copy application source
COPY . .

# Generate autoloader
RUN composer dump-autoload --optimize

# Create var directory with permissions
RUN mkdir -p var/cache var/log config/jwt \
    && chmod -R 777 var/

# PHP configuration for production
RUN echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.memory_consumption=128" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.max_accelerated_files=10000" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.validate_timestamps=0" >> /usr/local/etc/php/conf.d/opcache.ini

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public/"]
