FROM php:8.3-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev zip unzip libsqlite3-dev libpq-dev default-libmysqlclient-dev \
    && docker-php-ext-install pdo_mysql pdo_pgsql pdo_sqlite zip bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist || composer install --no-interaction --no-scripts --prefer-dist

COPY . .
RUN composer dump-autoload --optimize \
    && php artisan config:clear || true

ENV APP_ENV=production
EXPOSE 8000

CMD ["sh", "-c", "touch database/database.sqlite && php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=8000"]
