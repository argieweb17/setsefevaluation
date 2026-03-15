#!/bin/bash
set -e

# Configure Apache port from Railway's PORT env var
PORT="${PORT:-80}"
# Overwrite ports.conf to avoid sed match issues on restarts
echo "Listen ${PORT}" > /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/*.conf

# Ensure var directory is writable
mkdir -p /var/www/html/var/cache /var/www/html/var/log
chown -R www-data:www-data /var/www/html/var

# Warm Symfony cache
php /var/www/html/bin/console cache:clear --env=prod --no-debug 2>/dev/null || true
php /var/www/html/bin/console cache:warmup --env=prod --no-debug 2>/dev/null || true
chown -R www-data:www-data /var/www/html/var

exec apache2-foreground
