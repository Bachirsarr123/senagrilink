# Signature et publication de l'application Android

*Préparé sur la branche `claude/livraison-preproduction`. Rien n'est publié, aucune clé n'a été générée.*

## 1. Situation actuelle (constatée)

| Élément | État |
|---|---|
| Identifiant Android (`applicationId`) | `com.agriplatform.agri_platform_mobile` (aussi `namespace` et dossier Kotlin `com/agriplatform/agri_platform_mobile`) |
| Version | `pubspec.yaml` : `1.0.0+1`, donc versionCode **1** pour toutes les builds publiées jusqu'ici |
| Signature release | `signingConfigs.getByName("debug")` : une **nouvelle clé de debug aléatoire** est générée sur chaque runner GitHub |
| Publication | `mobile-apk.yml` (sur `main`) : à chaque push touchant `mobile-app/**`, il **remplace** la release publique `mobile-latest` |
| APK publié (`mobile-latest`, 19 octobre, commit `7e7aa20`) | clé de debug `d2f408cc…`, **sans permission INTERNET** (inutilisable), 0 téléchargement enregistré pour ce fichier |

## 2. Ce que prépare la branche

| Fichier | Rôle |
|---|---|
| `.github/workflows/mobile-apk.yml` | **Supprimé** : plus aucune publication automatique |
| `.github/workflows/mobile-ci.yml` | Tests, analyse, APK **de test** (clé de debug éphémère), vérifications ; artefact privé de 7 jours. **Ne publie jamais.** Lancement manuel possible avec une URL d'API (préproduction). |
| `.github/workflows/mobile-release.yml` | **Seul** chemin de distribution. Manuel, dans l'environnement protégé `android-distribution`. Exige la clé stable, vérifie l'empreinte et la compatibilité, crée une release **en brouillon** `mobile-vX.Y.Z`. Ne touche jamais `mobile-latest`. |
| `.github/scripts/verifier-apk.sh` | Contrôle INTERNET, certificat, empreinte attendue, refus de la clé de debug, compatibilité avec une version de référence (même certificat, même identifiant, versionCode supérieur) |
| `mobile-app/android/app/build.gradle.kts` | Signature lue depuis les variables d'environnement (CI) ou `android/key.properties` (poste local, ignoré par Git). `SENAGRILINK_EXIGER_SIGNATURE=true` fait **échouer** la build au lieu de retomber sur la clé de debug. |

La CI prouve à chaque exécution que :
- la garde refuse une build de distribution sans clé ;
- le script refuse la clé de debug, une empreinte erronée et un versionCode non supérieur.

## 3. Ce que vous devez faire (personne d'autre ne doit le faire à votre place)

### 3.1 Choisir l'identifiant définitif, **avant** la première distribution officielle
Voir §5. L'identifiant et la clé forment l'identité de l'application : ils ne pourront plus changer sans casser les mises à jour.

### 3.2 Créer la clé de distribution, sur votre poste
```bash
keytool -genkeypair -v -storetype JKS -keyalg RSA -keysize 4096 -validity 10000 \
  -alias senagrilink -keystore senagrilink-distribution.jks
```
- **Mots de passe :** forts, et conservés dans un gestionnaire de mots de passe.
- **Sauvegarde :** le fichier `.jks` et ses mots de passe sont **irremplaçables**. Si la clé est perdue, aucune mise à jour n'est plus possible : les utilisateurs devraient désinstaller l'application pour en installer une nouvelle. Gardez au moins deux sauvegardes chiffrées hors ligne, dont une hors de votre ordinateur.
- **Jamais dans le dépôt Git :** `.gitignore` exclut déjà `*.jks`, `*.keystore` et `key.properties`.

### 3.3 Relever l'empreinte du certificat (information publique, non secrète)
```bash
keytool -list -v -keystore senagrilink-distribution.jks -alias senagrilink | grep SHA256
```

### 3.4 Configurer GitHub (Settings du dépôt)
1. **Environments → New environment → `android-distribution`** :
   - *Required reviewers* : vous-même ;
   - *Deployment branches* : `main` uniquement.
2. **Secrets** de cet environnement :
   - `ANDROID_KEYSTORE_BASE64` : sortie de `base64 -w0 senagrilink-distribution.jks` ;
   - `ANDROID_KEYSTORE_PASSWORD` ;
   - `ANDROID_KEY_ALIAS` (`senagrilink`) ;
   - `ANDROID_KEY_PASSWORD`.
3. **Variable** (non secrète) : `ANDROID_CERT_SHA256` = l'empreinte relevée au §3.3.

### 3.5 Préparer une version (après fusion, sur votre décision)
1. Onglet **Actions → « Mobile — Préparer une version distribuée » → Run workflow**.
2. Paramètres :
   - `version_name` : `1.0.0` ;
   - `version_code` : `2` (strictement supérieur à toute version précédente) ;
   - `reference_tag` : vide pour la première version officielle, puis le tag de la version précédente (par exemple `mobile-v1.0.0`) ;
   - `confirmation` : `PREPARER-BROUILLON`.
3. Approuver le déploiement de l'environnement `android-distribution`.
4. Vérifier le brouillon (page Releases), installer l'APK sur un téléphone de test, **puis** publier.

## 4. Conséquences pour les utilisateurs d'une ancienne version

| Ancienne version installée | Effet de la première version signée avec la clé stable |
|---|---|
| Toute build publiée jusqu'ici (clé de debug aléatoire) | Android refuse la mise à jour (« conflit de package », « application non installée »). Il faut **désinstaller** l'ancienne version, puis installer la nouvelle. |
| Conséquence de la désinstallation | Perte des données locales du téléphone : session (reconnexion nécessaire) et profil mis en cache. **Aucune donnée serveur n'est perdue** (récoltes, commandes, etc.). |
| APK `mobile-latest` actuel | De toute façon inutilisable (pas d'accès réseau) : la désinstallation ne fait rien perdre d'utile. |
| Après la première version officielle | Mises à jour normales, sans désinstallation, tant que la même clé et le même identifiant sont utilisés et que le versionCode augmente : c'est ce que vérifie `mobile-release.yml`. |

**Recommandation (à votre décision) :** retirer ou marquer comme obsolète la release `mobile-latest`, et prévenir les éventuels testeurs de désinstaller l'ancienne application avant d'installer la version officielle.

## 5. Identifiant de l'application

- **Actuel :** `com.agriplatform.agri_platform_mobile`. Il reprend l'ancien nom du projet (AgriPlatform) et un nom généré par défaut.
- **Le changer** crée, pour Android, une **autre application** :
  - pas de mise à jour depuis l'ancienne ;
  - installation côte à côte possible ;
  - données locales séparées.
  - Sur le Play Store, l'identifiant est **définitif** dès la première publication.
- **Comment choisir :**
  - nom de domaine inversé que vous contrôlez ou contrôlerez (par exemple `sn.senagrilink.app` si vous possédez `senagrilink.sn`, ou un équivalent lié à votre domaine) ;
  - minuscules, chiffres et `_` ou `.` ;
  - stable, sans numéro de version ni nom d'environnement.
- **Moment :** avant la première version signée avec la clé stable. Comme toutes les versions actuelles demandent de toute façon une désinstallation (§4), c'est le moment le moins coûteux pour changer.
- **Je ne l'ai pas modifié.** Sur votre accord, le changement concerne `applicationId`, `namespace`, le dossier Kotlin de `MainActivity` et la configuration iOS (`PRODUCT_BUNDLE_IDENTIFIER`).
