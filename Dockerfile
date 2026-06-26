# Multi-stage build for Image Mosaic Gallery
# Stage 1: Build React frontend
FROM node:18-alpine AS builder

WORKDIR /app

# Copy package files
COPY package.json package-lock.json ./

# Install dependencies
RUN npm ci

# Copy source code
COPY . .

# Build React bundle with webpack
RUN npm run build

# Stage 2: Runtime environment with PHP
FROM php:8.4-fpm-alpine

# Install required PHP extensions and tools
RUN apk add --no-cache \
    curl \
    curl-dev \
    icu-dev \
    git \
    supervisor \
    nginx \
    gettext \
    && docker-php-ext-install -j$(nproc) \
        curl \
        intl \
    && apk del --no-cache curl-dev \
    && rm -rf /var/cache/apk/*

# Set working directory
WORKDIR /app

# Copy application code
COPY --from=builder /app .

# Create necessary directories with proper permissions
RUN mkdir -p \
    public/cache \
    /var/log/php \
    /var/log/supervisor \
    /var/run/php \
    && chown -R www-data:www-data /app /var/log/php /var/run/php \
    && chmod -R 755 public/cache \
    && chmod -R 755 /var/run/php

# Copy nginx configuration
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-custom.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy PHP configuration
COPY docker/php.ini /usr/local/etc/php/conf.d/99-custom.ini

# Copy entrypoint script
COPY docker/entrypoint.sh /app/entrypoint.sh
RUN chmod +x /app/entrypoint.sh

# Create .env file placeholder if it doesn't exist
RUN [ ! -f .env ] && echo "PHOTO_PRISM_BASE_URL=https://photoprism.example.com" > .env || true

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost:8080/api.php?action=photo-count || exit 1

# Expose port
EXPOSE 8080

# Run entrypoint script which clears cache and starts supervisor
ENTRYPOINT ["/app/entrypoint.sh"]
