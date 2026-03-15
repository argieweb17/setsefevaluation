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
mkdir -p /var/www/html/var/cache /var/www/html/var/log /var/www/html/var/share
chown -R www-data:www-data /var/www/html/var

# Debug: show which DATABASE_URL is being used (mask password)
echo "=== DATABASE_URL check ==="
echo "$DATABASE_URL" | sed 's/:[^@]*@/:***@/'
echo "==========================="

# Warm Symfony cache
php /var/www/html/bin/console cache:clear --env=prod --no-debug || echo "WARNING: cache:clear failed"
php /var/www/html/bin/console cache:warmup --env=prod --no-debug || echo "WARNING: cache:warmup failed"

# Ensure database schema is up to date
echo "=== Updating database schema ==="
php /var/www/html/bin/console doctrine:schema:update --force --env=prod --no-interaction || echo "WARNING: schema update failed"
php /var/www/html/bin/console doctrine:migrations:migrate --no-interaction --env=prod --allow-no-migration || echo "WARNING: migrations failed"
echo "==================================="

chown -R www-data:www-data /var/www/html/var

# Enable PHP error display temporarily for debugging
echo "display_errors = On" > /usr/local/etc/php/conf.d/debug.ini
echo "error_reporting = E_ALL" >> /usr/local/etc/php/conf.d/debug.ini
echo "log_errors = On" >> /usr/local/etc/php/conf.d/debug.ini

# Verify Apache config before starting
apache2ctl configtest

exec apache2-foreground
