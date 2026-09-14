FROM php:8.2-apache

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PORT=8080

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    gosu \
    libcurl4-openssl-dev \
    libgmp-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mysqli \
        mbstring \
        xml \
        gmp \
        bcmath \
        curl \
        gd \
        zip \
        pcntl \
        sockets \
        opcache \
    && a2enmod rewrite headers expires \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html
COPY . /var/www/html

RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader \
    && mkdir -p /var/www/html/storage/sessions \
    && chown -R www-data:www-data /var/www/html/storage \
    && chmod -R 770 /var/www/html/storage \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

COPY docker/start.sh /usr/local/bin/start-tmr
RUN chmod +x /usr/local/bin/start-tmr

EXPOSE 8080

CMD ["/usr/local/bin/start-tmr"]
