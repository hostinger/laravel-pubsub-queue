FROM composer:2 AS builder

COPY composer.json /app/

RUN composer install  \
  --ignore-platform-reqs \
  --no-ansi \
  --no-autoloader \
  --no-interaction \
  --no-scripts

COPY . /app/

RUN composer dump-autoload --optimize --classmap-authoritative

FROM php:8.4-cli AS base

RUN apt-get update -y &&\
  apt-get -y install --no-install-recommends zip unzip &&\
  docker-php-ext-install pcntl &&\
  apt-get autoremove -y &&\
  apt-get clean &&\
  rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Install PECL and PEAR extensions
RUN pecl install xdebug pcov

# Enable php extensions
RUN docker-php-ext-enable pcov

# Add php extensions configuration
COPY xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini

# Cleanup
RUN rm -rf /var/lib/apt/lists/*
RUN rm -rf /tmp/pear/

# Setup working directory
WORKDIR /app

COPY --from=builder /app /app
COPY --from=builder /usr/bin/composer /usr/bin/composer
