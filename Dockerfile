# syntax=docker/dockerfile:1
#
# Imagem unica usada tanto pelo servico "app" (nginx + php-fpm, via
# supervisord) quanto pelo servico "worker" (php artisan queue:work) no
# docker-compose.yml — os dois rodam o MESMO codigo, so muda o comando de
# entrada. Isso evita duas imagens divergindo com o tempo.
#
# Build em dois estagios:
#   1) frontend  - compila os assets do Vite (Vue/Inertia/Tailwind)
#   2) app       - PHP-FPM + extensoes + dependencias do Composer

# ---------------------------------------------------------------------------
# Estagio 1: build do frontend (Vite)
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --no-progress --ignore-platform-reqs

# ---------------------------------------------------------------------
# Estagio 1: build do frontend (Vite)
# ---------------------------------------------------------------------
FROM node:20-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY resources ./resources
COPY vite.config.js tailwind.config.js postcss.config.js jsconfig.json ./
COPY public ./public
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# ---------------------------------------------------------------------------
# Estagio 2: aplicacao (PHP-FPM + nginx + supervisord)
# ---------------------------------------------------------------------------
FROM php:8.3-fpm-alpine AS app

# ffmpeg: fornece o binario 'ffprobe' usado por ValidateChannelsJob. No
# Linux, ao contrario do Windows, o PATH do processo do supervisord/worker
# ja inclui /usr/bin, entao o FFPROBE_PATH default ('ffprobe', sem caminho
# completo) resolve sozinho — nao precisa configurar nada no .env pra isso.
RUN apk add --no-cache \
        nginx \
        supervisor \
        ffmpeg \
        curl \
        bash \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        gd \
        zip \
        intl \
        mbstring \
        bcmath \
        pcntl \
        opcache \
    && apk del $PHPIZE_DEPS

# pcntl habilitado acima: no Linux ele existe de verdade (ao contrario do
# Windows), entao o $timeout nativo de job do Laravel passa a funcionar como
# uma camada extra de protecao, alem do checkTimeout() explicito que ja
# corrigimos no ValidateChannelsJob.

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencias do PHP primeiro (cache de camada do Docker: só reinstala se
# composer.json/lock mudarem, nao a cada alteracao de codigo).
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --no-progress

COPY . .
COPY --from=frontend /app/public/build ./public/build

RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/entrypoint.sh"]
# Comando padrao = sobe o "app" (nginx + php-fpm). O servico "worker" no
# docker-compose.yml sobrescreve isso pra rodar o queue:work.
CMD ["app"]
