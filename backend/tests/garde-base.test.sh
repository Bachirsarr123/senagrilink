#!/bin/sh
# Tests du garde-fou de démarrage (garde-base.sh), exécutés par backend-ci.yml.
. "$(dirname "$0")/../garde-base.sh"
echecs=0
cas() { # attendu(0|1) description, puis variables d'environnement
    attendu=$1; libelle=$2; shift 2
    if env -i "$@" sh -c '. ./garde-base.sh; garde_base' > /dev/null 2>&1; then obtenu=0; else obtenu=1; fi
    if [ "$obtenu" = "$attendu" ]; then echo "OK    $libelle"; else echo "ECHEC $libelle (attendu $attendu, obtenu $obtenu)"; echecs=$((echecs + 1)); fi
}
cas 0 "production actuelle : aucune variable de garde, démarrage autorisé"     DB_DATABASE=agri_platform DB_HOST=prod.example
cas 0 "préproduction : base attendue"                                          SENAGRILINK_BASE_ATTENDUE=senagrilink_preprod DB_DATABASE=senagrilink_preprod DB_HOST=test.example SENAGRILINK_HOTE_INTERDIT=prod.example
cas 1 "préproduction branchée par erreur sur la base de production (nom)"      SENAGRILINK_BASE_ATTENDUE=senagrilink_preprod DB_DATABASE=agri_platform DB_HOST=test.example
cas 1 "préproduction branchée par erreur sur l'hôte de production"             SENAGRILINK_BASE_ATTENDUE=senagrilink_preprod DB_DATABASE=senagrilink_preprod DB_HOST=prod.example SENAGRILINK_HOTE_INTERDIT=prod.example
cas 1 "base attendue mais DB_DATABASE vide"                                    SENAGRILINK_BASE_ATTENDUE=senagrilink_preprod DB_HOST=test.example
cas 1 "DB_URL défini : vérification impossible, refus"                         SENAGRILINK_BASE_ATTENDUE=senagrilink_preprod DB_DATABASE=senagrilink_preprod DB_URL=postgres://x
[ "$echecs" -eq 0 ] || { echo "$echecs cas en échec"; exit 1; }
