FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    libpq-dev \
    default-libmysqlclient-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libicu-dev \
    tesseract-ocr \
    && rm -rf /var/lib/apt/lists/*

# Configure and install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    gd \
    pdo \
    pdo_mysql \
    zip \
    intl \
    opcache

# Enable Apache mod_rewrite and ensure only mpm_prefork is active
RUN rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.* \
    && ln -sf /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf \
    && ln -sf /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load \
    && a2enmod rewrite

# Set document root to Symfony's public directory
RUN sed -ri -e "s!/var/www/html!/var/www/html/public!g" /etc/apache2/sites-available/*.conf
RUN sed -ri -e "s!AllowOverride None!AllowOverride All!g" /etc/apache2/apache2.conf

# Install Composer — allow running as root
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Install Symfony CLI
RUN curl -1sLf 'https://dl.cloudsmith.io/public/symfony/stable/setup.deb.sh' | bash \
    && apt-get install -y symfony-cli

# Set working directory
WORKDIR /var/www/html

# Copy composer files first for better layer caching
COPY composer.json composer.lock symfony.lock ./

# Install dependencies
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Copy the rest of the application
COPY . .

# Create var directories (excluded by .dockerignore)
RUN mkdir -p var/cache var/log var/share

# Run Symfony scripts (force dummy DATABASE_URL at build — real DB is only available at runtime)
# APP_RUNTIME_ENV skips Dotenv boot (so .env file is not required in container builds).
ENV APP_ENV=prod
ENV APP_RUNTIME_ENV=prod
ENV APP_SECRET=build-secret-change-in-runtime
RUN DATABASE_URL="mysql://root:fmcSBZZOdAqDMhoAZZQKSbGqtoKSCOrN@interchange.proxy.rlwy.net:43287/railway" \
    composer run-script post-install-cmd

# Compile assets for production (AssetMapper)
RUN DATABASE_URL="mysql://dummy:dummy@localhost:3306/dummy?serverVersion=8.0.32&charset=utf8mb4" \
    php bin/console asset-map:compile --env=prod

# Set permissions
RUN chown -R www-data:www-data var/ public/

# Use entrypoint script to configure PORT at runtime
COPY docker-entrypoint.sh /usr/local/bin/
RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh && chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80
CMD ["/usr/local/bin/docker-entrypoint.sh"]
