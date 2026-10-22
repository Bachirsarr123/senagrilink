#!/usr/bin/env bash
# Vérifie un APK Android SenAgriLink, sans rien signer ni publier.
#
#   verifier-apk.sh <apk> [--empreinte-attendue <sha256>] [--reference <apk-de-reference>] [--distribution]
#
# Contrôles :
#   1. permission android.permission.INTERNET dans le manifeste fusionné ;
#   2. certificat de signature affiché (empreinte SHA-256, sujet) ;
#   3. --empreinte-attendue : le certificat doit être exactement celui-ci ;
#   4. --distribution : la clé de debug (CN=Android Debug) est refusée ;
#   5. --reference : même certificat que l'APK de référence (mise à jour possible
#      sans désinstallation), même identifiant d'application et versionCode
#      strictement supérieur (Android refuse une mise à jour de version inférieure).
#
# Sort en erreur au premier contrôle échoué. Outils : aapt2 et apksigner du SDK Android.
set -euo pipefail

APK=""
ATTENDUE=""
REFERENCE=""
DISTRIBUTION=false

while [ $# -gt 0 ]; do
  case "$1" in
    --empreinte-attendue) ATTENDUE=$(echo "$2" | tr -d ': ' | tr 'A-F' 'a-f'); shift 2 ;;
    --reference) REFERENCE="$2"; shift 2 ;;
    --distribution) DISTRIBUTION=true; shift ;;
    *) APK="$1"; shift ;;
  esac
done

[ -f "$APK" ] || { echo "::error::APK introuvable : $APK"; exit 1; }

OUTILS=$(ls -d "${ANDROID_HOME:?ANDROID_HOME non défini}"/build-tools/* | sort -V | tail -1)
AAPT2="$OUTILS/aapt2"
APKSIGNER="$OUTILS/apksigner"

echec() { echo "::error::$1"; exit 1; }

# Certificat du premier signataire : empreinte SHA-256 en minuscules, sans séparateur.
# Formats selon la version d'apksigner : « Signer #1 certificate … » ou « V2 Signer: certificate … ».
certificat() { "$APKSIGNER" verify --print-certs "$1" | sed -n -E "s/^(Signer #1|V[0-9.]+ Signer:) certificate $2: //p" | head -1; }
empreinte() { certificat "$1" 'SHA-256 digest'; }
sujet() { certificat "$1" 'DN'; }
badging() { "$AAPT2" dump badging "$1" | head -1; }
champ() { sed -n "s/.* $2='\([^']*\)'.*/\1/p" <<< "$1"; }

echo "== $APK"
"$APKSIGNER" verify "$APK" > /dev/null || echec "signature APK invalide"

# 1. Permission INTERNET (manifeste fusionné réellement embarqué)
"$AAPT2" dump permissions "$APK" | tee /tmp/permissions-apk.txt
grep -qE "uses-permission.*'android\.permission\.INTERNET'" /tmp/permissions-apk.txt \
  || echec "android.permission.INTERNET absente du manifeste fusionné"
echo "OK : permission INTERNET présente."

# Lot 4 : suivi GPS téléphone verrouillé par un service de premier plan ; la
# localisation « en arrière-plan » n'est volontairement pas demandée.
for p in FOREGROUND_SERVICE FOREGROUND_SERVICE_LOCATION ACCESS_FINE_LOCATION; do
  grep -qE "uses-permission.*'android\.permission\.$p'" /tmp/permissions-apk.txt \
    || echec "android.permission.$p absente : le suivi GPS s'arrêterait écran verrouillé"
done
if grep -qE "ACCESS_BACKGROUND_LOCATION" /tmp/permissions-apk.txt; then
  echec "ACCESS_BACKGROUND_LOCATION présente : non nécessaire avec le service de premier plan"
fi
echo "OK : permissions du suivi GPS en premier plan présentes, pas de localisation en arrière-plan."

# 2. Certificat
EMPREINTE=$(empreinte "$APK")
SUJET=$(sujet "$APK")
INFOS=$(badging "$APK")
echo "Identifiant : $(champ "$INFOS" name) — versionCode $(champ "$INFOS" versionCode) — versionName $(champ "$INFOS" versionName)"
echo "Certificat  : $SUJET"
echo "SHA-256     : $EMPREINTE"
[ -n "$EMPREINTE" ] || echec "empreinte du certificat illisible"

# 3. Empreinte attendue (certificat de distribution de référence)
if [ -n "$ATTENDUE" ]; then
  [ "$EMPREINTE" = "$ATTENDUE" ] || echec "certificat inattendu : $EMPREINTE (attendu : $ATTENDUE)"
  echo "OK : certificat conforme à l'empreinte de référence."
fi

# 4. Distribution : jamais la clé de debug
if [ "$DISTRIBUTION" = true ]; then
  case "$SUJET" in
    *"CN=Android Debug"*) echec "APK signé avec une clé de debug : distribution refusée" ;;
  esac
  echo "OK : pas de clé de debug."
fi

# 5. Compatibilité de mise à jour avec une version de référence
if [ -n "$REFERENCE" ]; then
  [ -f "$REFERENCE" ] || echec "APK de référence introuvable : $REFERENCE"
  EMPREINTE_REF=$(empreinte "$REFERENCE")
  INFOS_REF=$(badging "$REFERENCE")
  echo "Référence   : $(champ "$INFOS_REF" name) — versionCode $(champ "$INFOS_REF" versionCode) — SHA-256 $EMPREINTE_REF"
  [ "$EMPREINTE" = "$EMPREINTE_REF" ] \
    || echec "certificat différent de la référence : Android refusera la mise à jour (désinstallation obligatoire)"
  [ "$(champ "$INFOS" name)" = "$(champ "$INFOS_REF" name)" ] \
    || echec "identifiant d'application différent de la référence : ce serait une autre application"
  [ "$(champ "$INFOS" versionCode)" -gt "$(champ "$INFOS_REF" versionCode)" ] \
    || echec "versionCode non supérieur à la référence : Android refusera la mise à jour"
  echo "OK : mise à jour compatible avec la référence (même certificat, même identifiant, versionCode supérieur)."
fi
