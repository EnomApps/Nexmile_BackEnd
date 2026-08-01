# Local development image.
#
# Pinned to the same PHP minor as the server (8.5) so behaviour matches. The
# production host still runs PHP-FPM directly; this image exists to remove the
# XAMPP 8.2 / server 8.5 drift while developing.
FROM php:8.5-fpm

# System libraries required by the PHP extensions below.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libzip-dev \
        libonig-dev \
        libpng-dev \
        libxml2-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        bcmath \
        intl \
        zip \
        exif \
        pcntl \
        gd

# phpredis, matching the server. Local .env should use REDIS_CLIENT=phpredis
# when running through Docker; predis stays for running under XAMPP.
RUN pecl install redis \
    && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# php-fpm runs as www-data; matching the uid to the usual host user keeps
# files written inside the container editable from the host. -o permits a
# duplicate id rather than failing the build if 1000 is already taken.
RUN usermod -u 1000 -o www-data && groupmod -g 1000 -o www-data

EXPOSE 9000

CMD ["php-fpm"]
