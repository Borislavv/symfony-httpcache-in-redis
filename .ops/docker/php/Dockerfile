# Use the official PHP image
FROM php:8.1-fpm

# Install dependencies
RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    git \
    curl \
    libpng-dev \
    libicu-dev \
    libonig-dev \
    libxml2-dev \
    && docker-php-ext-install pdo_mysql zip intl opcache mbstring gd xml

# Install Redis extension
RUN pecl install redis \
    && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy the existing application directory contents
COPY . /var/www/html

# Change the owner of the working directory to www-data
RUN chown -R www-data:www-data /var/www/html

# Switch to the www-data user
USER www-data

# Install Symfony dependencies
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

# Expose port 9000 for PHP-FPM
EXPOSE 9000

# Start PHP-FPM server
CMD ["php-fpm"]
