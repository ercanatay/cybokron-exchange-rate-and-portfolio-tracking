# Cybokron Exchange Rate & Portfolio Tracking
# PHP 8.3 + Apache

FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    libzip-dev \
    curl \
    libcurl4-openssl-dev \
    unzip \
    && docker-php-ext-install pdo pdo_mysql zip \
    && a2enmod rewrite headers

ENV APACHE_DOCUMENT_ROOT /var/www/html
WORKDIR /var/www/html

COPY . .
RUN cp config.docker.php config.php
RUN mkdir -p cybokron-logs && chown -R www-data:www-data /var/www/html

EXPOSE 80
