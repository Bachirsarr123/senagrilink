#!/bin/sh
set -e

echo "================================================="
echo " AgriPlatform — Démarrage du backend Laravel"
echo "================================================="

# ── 1. Fichier .env ───────────────────────────────────────────────────────────
if [ ! -f /var/www/.env ]; then
    echo "[1/5] .env absent — copie depuis .env.example..."
    cp /var/www/.env.example /var/www/.env
else
    echo "[1/5] .env déjà présent."
fi

# ── 2. Dépendances Composer ───────────────────────────────────────────────────
if [ ! -d /var/www/vendor ]; then
    echo "[2/5] vendor/ absent — composer install en cours..."
    cd /var/www && composer install \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader \
        --no-progress
else
    echo "[2/5] vendor/ présent."
fi

# ── 3. Permissions storage/ et bootstrap/cache/ ───────────────────────────────
echo "[3/5] Configuration des permissions..."
mkdir -p /var/www/storage/framework/cache \
         /var/www/storage/framework/sessions \
         /var/www/storage/framework/views \
         /var/www/storage/logs \
         /var/www/bootstrap/cache

chmod -R 775 /var/www/storage /var/www/bootstrap/cache

# ── 4. Clé d'application ──────────────────────────────────────────────────────
if grep -q "^APP_KEY=$" /var/www/.env 2>/dev/null || ! grep -q "^APP_KEY=" /var/www/.env 2>/dev/null; then
    echo "[4/5] Génération de la clé d'application..."
    php /var/www/artisan key:generate --force
else
    echo "[4/5] Clé d'application déjà définie."
fi

# ── 5. Migrations ─────────────────────────────────────────────────────────────
echo "[5/5] Exécution des migrations..."
php /var/www/artisan migrate --force --no-interaction \
    && echo "      Migrations appliquées." \
    || echo "      Migrations ignorées (DB peut-être indisponible — relancer le conteneur)."

echo "================================================="
echo " Démarrage de PHP-FPM"
echo "================================================="
exec php-fpm
