FROM php:8.1-apache

# Install extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql pgsql

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy PHP files
COPY *.php ./
COPY includes/ ./includes/
COPY assets/ ./assets/

# Copy config
COPY config.php ./
COPY composer.json ./

# Install composer if needed
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Install dependencies
RUN composer install --no-dev --optimize-autoloader || true

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Expose port
EXPOSE 8080

# Start Apache
CMD ["apache2-foreground"]
