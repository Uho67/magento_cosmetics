###############################################################################
# Magento Open Source 2.4.9 — multi-stage production build
#
# Targets
#   builder       — installs deps, compiles DI, deploys static content
#   php-runtime   — lean PHP-FPM image with baked-in application code
#   nginx-runtime — nginx image with static files copied from builder
#
# Build (from repo root):
#   docker build --secret id=composer_auth,src=auth.json \
#     --target php-runtime  -t legal/php:latest  -f deploy/Dockerfile .
#   docker build --secret id=composer_auth,src=auth.json \
#     --target nginx-runtime -t legal/nginx:latest -f deploy/Dockerfile .
###############################################################################

###############################################################################
# Stage 1 — builder
###############################################################################
FROM wardenenv/php-fpm:8.4-magento2 AS builder

USER root
WORKDIR /var/www/html

# Copy only manifests first so Composer layer is cached until they change
COPY --chown=www-data:www-data composer.json composer.lock ./

# Install production Composer deps using BuildKit secret (auth.json never baked in)
RUN --mount=type=secret,id=composer_auth,target=/root/.composer/auth.json \
    composer install \
      --no-dev \
      --no-interaction \
      --optimize-autoloader \
      --prefer-dist

# Copy full application source
COPY --chown=www-data:www-data . .

# Disable the dev-only 2FA disabler (it's a --dev dep so code exists, but must not be enabled)
RUN php bin/magento module:disable MarkShust_DisableTwoFactorAuth --no-backup 2>/dev/null || true

# Compile DI and deploy static content (en_US only; add more locales as needed)
RUN php bin/magento setup:di:compile && \
    php bin/magento setup:static-content:deploy -f en_US --no-interaction && \
    # Strip dev/test artefacts to keep the layer slim
    rm -rf \
      dev/tests \
      var/cache \
      var/page_cache \
      var/session

###############################################################################
# Stage 2 — php-runtime  (PHP-FPM + full application)
###############################################################################
FROM wardenenv/php-fpm:8.4-magento2 AS php-runtime

WORKDIR /var/www/html

COPY --from=builder --chown=www-data:www-data /var/www/html .

# Production PHP ini (overrides Warden defaults)
COPY deploy/php/php.ini /usr/local/etc/php/conf.d/99-magento-prod.ini

USER www-data

###############################################################################
# Stage 3 — nginx-runtime  (serves static files, proxies dynamic to php-fpm)
###############################################################################
FROM nginx:1.26-alpine AS nginx-runtime

# Copy nginx configuration
COPY deploy/nginx/nginx.conf  /etc/nginx/nginx.conf
COPY deploy/nginx/magento.conf /etc/nginx/conf.d/default.conf

# Copy static web assets from builder (pub/static is baked in; pub/media is a volume)
COPY --from=builder /var/www/html/pub        /var/www/html/pub
COPY --from=builder /var/www/html/.htaccess  /var/www/html/.htaccess

# pub/media is mounted as a named volume at runtime — create the mount point
RUN mkdir -p /var/www/html/pub/media && chown -R nginx:nginx /var/www/html
