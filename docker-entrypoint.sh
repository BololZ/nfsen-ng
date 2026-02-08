#!/usr/bin/bash
set -e

# If settings.php does not exist in config volume, copy default
if [ ! -f /config/nfsen-ng/settings.php ]; then
    echo "No settings.php found in /config/nfsen-ng. Copying default..."
    mv /var/www/html/backend/settings/settings.php.dist /config/nfsen-ng/settings.php
fi

# Link settings.php to nfsen-ng directory
ln -sf /config/nfsen-ng/settings.php /var/www/html/backend/settings/settings.php

# Ensure correct ownership
chown -R www-data:www-data /var/www/html
chown -R www-data:www-data /data/nfsen-ng
chown -R www-data:www-data /config/nfsen-ng

# Link nfsen-ng data
ln -s /data/nfsen-ng /var/www/html/backend/datasources/data

# Execute container command
exec "$@"
