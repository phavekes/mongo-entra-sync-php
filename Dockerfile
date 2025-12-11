FROM php:8.2-cli-alpine

WORKDIR /app

RUN apk update && \
    apk add --no-cache \
    git \
    ca-certificates \
    supervisor \
    libcurl \
    libxml2-dev \
    openssl curl-dev openssl openssl-dev \
    php-openssl \
    autoconf \
    g++ \
    make

RUN pecl install mongodb && \
    docker-php-ext-enable mongodb && \
    apk del autoconf g++ make && \
    pecl config-set php_ini /etc/php7/php.ini

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY composer.json composer.json
COPY sync.php sync.php

RUN composer install --no-dev --optimize-autoloader

RUN echo "0 * * * * /usr/local/bin/php /app/sync.php" > /etc/crontabs/root

COPY supervisor.conf /etc/supervisor/conf.d/supervisor.conf

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisor.conf"]