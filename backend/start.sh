#!/bin/sh
set -e

echo "================================================="
echo " SenAgriLink — Démarrage du backend Laravel (Render)"
echo "================================================="

# ── 1. Fichier .env ───────────────────────────────────────────────────────────
if [ ! -f /var/www/.env ]; then
    echo "[1/5] .env absent — copie depuis .env.example..."
    cp /var/www/.env.example /var/www/.env
else
    echo "[1/5] .env déjà présent."
fi

# ── 2. Clé d'application ───────────────────────────────────────────────────────
if [ -z "$APP_KEY" ] && (grep -q "^APP_KEY=$" /var/www/.env 2>/dev/null || ! grep -q "^APP_KEY=" /var/www/.env 2>/dev/null); then
    echo "[2/5] Génération de la clé d'application..."
    php artisan key:generate --force
else
    echo "[2/5] Clé d'application déjà définie."
fi

# ── 3. Permissions storage/ et bootstrap/cache/ ───────────────────────────────
echo "[3/5] Configuration des permissions..."
mkdir -p storage/framework/cache \
         storage/framework/sessions \
         storage/framework/views \
         storage/logs \
         bootstrap/cache
chmod -R 775 storage bootstrap/cache

# ── 4. Cache de configuration et migrations ───────────────────────────────────
echo "[4/5] Nettoyage du cache et mise en cache de la config..."
php artisan config:clear --quiet
php artisan route:clear --quiet
php artisan view:clear --quiet
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "      Exécution des migrations..."
php artisan migrate --force --no-interaction

# ── 5. Démarrage du serveur ────────────────────────────────────────────────────
echo "[5/5] Démarrage du serveur sur le port ${PORT:-8000}..."
echo "================================================="
exec php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"
