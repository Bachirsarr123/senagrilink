#!/bin/sh
# Garde-fou d'environnement, exécuté par start.sh AVANT toute migration.
#
# Inactif si aucune des variables ci-dessous n'est définie (cas de la
# production actuelle : comportement inchangé). En préproduction, on définit :
#   SENAGRILINK_BASE_ATTENDUE  nom exact de la base de test (ex. senagrilink_preprod)
#   SENAGRILINK_HOTE_INTERDIT  hôte de la base de PRODUCTION, à ne jamais toucher
# Si la base configurée ne correspond pas, le service refuse de démarrer :
# aucune migration ne peut alors s'exécuter sur une base inattendue.

garde_base() {
    if [ -n "${SENAGRILINK_BASE_ATTENDUE:-}" ]; then
        if [ -n "${DB_URL:-}" ]; then
            echo "[garde] REFUS : DB_URL est défini ; en préproduction, utiliser DB_HOST/DB_DATABASE pour que la garde puisse vérifier la base." >&2
            return 1
        fi
        if [ "${DB_DATABASE:-}" != "$SENAGRILINK_BASE_ATTENDUE" ]; then
            echo "[garde] REFUS : base « ${DB_DATABASE:-(vide)} » différente de la base attendue « $SENAGRILINK_BASE_ATTENDUE »." >&2
            return 1
        fi
    fi
    if [ -n "${SENAGRILINK_HOTE_INTERDIT:-}" ] && [ "${DB_HOST:-}" = "$SENAGRILINK_HOTE_INTERDIT" ]; then
        echo "[garde] REFUS : DB_HOST désigne l'hôte interdit (base de production)." >&2
        return 1
    fi
    if [ -n "${SENAGRILINK_BASE_ATTENDUE:-}${SENAGRILINK_HOTE_INTERDIT:-}" ]; then
        echo "[garde] Base vérifiée : ${DB_DATABASE:-?} sur ${DB_HOST:-?}."
    fi
    return 0
}
