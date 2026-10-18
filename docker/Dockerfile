ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-cli
RUN apt-get update \
 && apt-get install -y --no-install-recommends $PHPIZE_DEPS valgrind locales \
 && sed -i 's/^# *de_DE.UTF-8/de_DE.UTF-8/' /etc/locale.gen && locale-gen \
 && rm -rf /var/lib/apt/lists/*
WORKDIR /build
