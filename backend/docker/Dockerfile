FROM php:8.3-fpm-alpine

# ── Extensions système requises par Laravel + PostgreSQL ──────────────────────
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

# ── Composer ──────────────────────────────────────────────────────────────────
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# ── Entrypoint (copié hors de /var/www pour survivre au volume mount) ─────────
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www

# Exécution sans privilèges root (Sonar docker:S6471). L'utilisateur www-data
# reçoit l'identifiant de l'utilisateur de l'hôte (UID/GID, docker-compose) :
# il peut donc écrire dans le dossier monté sans permissions ouvertes à tous.
ARG UID=1000
ARG GID=1000
RUN apk add --no-cache shadow \
    && groupmod -o -g "${GID}" www-data \
    && usermod -o -u "${UID}" -g www-data www-data \
    && chown www-data:www-data /var/www
USER www-data
EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
