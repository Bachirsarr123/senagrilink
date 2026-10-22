# Image de production pour Render : PHP CLI + `php artisan serve` (voir start.sh).
# Pour du dev local avec Nginx + PHP-FPM, voir docker/Dockerfile + docker-compose.yml.
FROM php:8.3-cli-alpine

RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    curl \
    oniguruma-dev

RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    pgsql \
    mbstring \
    zip \
    bcmath \
    opcache

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY composer.json composer.lock ./
RUN composer install \
    --no-interaction \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-progress \
    --no-scripts

# Copie explicite du seul code nécessaire à l'exécution (Sonar docker:S6470) :
# un « COPY . . » embarquerait tout le contexte (.env local, .env.testing, tests,
# fichiers de CI…). Le .dockerignore exclut en plus ces fichiers par défense.
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY lang ./lang
COPY public ./public
COPY resources ./resources
COPY routes ./routes
COPY storage ./storage
COPY artisan start.sh garde-base.sh .env.example ./

# Le serveur tourne sans privilèges root (Sonar docker:S6471) : www-data ne peut
# écrire que dans storage/, bootstrap/cache/ et le .env créé par start.sh.
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod +x start.sh \
    && composer dump-autoload --optimize \
    && chown www-data:www-data /var/www \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

USER www-data

EXPOSE 8000
CMD ["./start.sh"]
