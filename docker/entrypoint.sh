#!/bin/sh
# Entrypoint script for Image Mosaic Gallery Docker container
# Runs on every container start/deploy

set -e

echo "🚀 Starting Image Mosaic Gallery..."

# Clear cache on startup/deploy
echo "🧹 Clearing cache from previous deployment..."
CACHE_DIR="/app/public/cache"

if [ -d "$CACHE_DIR" ]; then
    # Remove all cache files
    rm -f "$CACHE_DIR"/.tiles_*
    rm -f "$CACHE_DIR"/.count_*
    rm -f "$CACHE_DIR"/.url_mapping.json
    echo "✅ Cache cleared successfully"
else
    echo "⚠️  Cache directory not found, creating..."
    mkdir -p "$CACHE_DIR"
fi

# Ensure proper permissions
chmod 755 "$CACHE_DIR"
chown www-data:www-data "$CACHE_DIR"

echo "✅ Permissions set correctly"

# Ensure required directories exist
mkdir -p /var/log/php /var/log/supervisor /var/run/php
chmod 755 /var/run/php
chown www-data:www-data /app /var/log/php /var/run/php

# Start supervisord
echo "🎯 Starting supervisord..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
