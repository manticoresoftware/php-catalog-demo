FROM php:8.2-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        git \
        unzip \
        libzip-dev \
        libpng-dev \
        libcurl4-openssl-dev \
    && docker-php-ext-install gd zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY app/composer.json app/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY app/ ./
COPY docker/entrypoint.sh /entrypoint.sh

RUN composer dump-autoload --optimize --no-interaction \
    && chmod +x /entrypoint.sh \
    && mkdir -p storage/logs storage/state storage/imports public/images/uploads \
    && chown -R www-data:www-data storage public/images/uploads

ENV MANTICORE_HOST=manticore \
    MANTICORE_PORT=9308 \
    APP_HOST=0.0.0.0 \
    APP_PORT=8081

EXPOSE 8081

ENTRYPOINT ["/entrypoint.sh"]
