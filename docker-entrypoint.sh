#!/bin/bash
set -e

# Ensure only one MPM is loaded (fix AH00534)
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*
ln -sf /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf
ln -sf /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load

# Configure Apache port from Railway's PORT env var
PORT="${PORT:-80}"
echo "Listen ${PORT}" > /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/*.conf

# Ensure var directory is writable
mkdir -p /var/www/html/var/cache /var/www/html/var/log
chown -R www-data:www-data /var/www/html/var

# Warm Symfony cache
php /var/www/html/bin/console cache:clear --env=prod --no-debug 2>/dev/null || true
php /var/www/html/bin/console cache:warmup --env=prod --no-debug 2>/dev/null || true
chown -R www-data:www-data /var/www/html/var

# Verify Apache config before starting
apache2ctl configtest

exec apache2-foreground
