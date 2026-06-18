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

# Exécution en root pour l'environnement de développement (permissions storage/).
# En production, utiliser un utilisateur dédié avec les volumes correctement configurés.
EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
