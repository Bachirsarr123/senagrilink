# Lot 7 — Recette et mise en ligne : guide de la préproduction

*Branche `claude/lot7-recette-mise-en-ligne`, partie de `claude/lot6-notifications-comptes`. Elle contient les lots 1 à 6. Rien n'a été fusionné ni déployé, et aucun APK n'a été publié.*

## 1. Ce qui est prêt

| Élément | État |
|---|---|
| `render-preproduction.yaml` | Pointe vers cette branche (lots 1 à 6). Il ajoute `FIREBASE_CREDENTIALS_BASE64` (secrète, saisie par vous). Déploiement automatique coupé. |
| Firebase côté application | Les 4 variables GitHub sont en place. **APK de test construit avec elles** (exécution 39682467976, réussie). |
| Firebase côté serveur | Accepte au choix la clé encodée en base64, ou **le contenu du fichier JSON collé tel quel**, plus simple depuis un téléphone. Espaces et retours à la ligne parasites ignorés. Test automatique ajouté. |
| Construction des APK | Ignore les espaces et retours à la ligne collés par erreur autour des variables Firebase (cause de l'échec de l'exécution 39681092688). |

## 2. Ce que vous ferez (avec mon aide, étape par étape)

1. **Base de test, chez Neon** : créez un **nouveau projet Neon**, distinct de celui de la production. La base doit s'appeler `senagrilink_preprod`.
   - Un projet distinct est **obligatoire** : la garde de démarrage refuse toute base hébergée sur l'hôte de la production.
2. **Render → New → Blueprint**, avec le dépôt `Bachirsarr123/senagrilink` et le fichier `render-preproduction.yaml`. Saisissez :
   - `DB_HOST`, `DB_PORT` (5432), `DB_USERNAME` et `DB_PASSWORD` : ceux du **projet Neon de test** ;
   - `SENAGRILINK_HOTE_INTERDIT` : l'hôte Neon de la **production** (non secret) ;
   - `FIREBASE_CREDENTIALS_BASE64` : ouvrez le fichier JSON téléchargé dans Firebase (*Comptes de service → Générer une nouvelle clé privée*) et **collez tout son contenu**. Vous ne me l'envoyez pas.
3. **Déploiement manuel** du backend, puis du site. Dans les journaux, attendez `[garde] Base vérifiée : senagrilink_preprod`.
4. **APK de test vers la préproduction** : je lance « Mobile — CI » avec `api_url` = `https://senagrilink-backend-preprod.onrender.com/api`. Vous téléchargez l'APK (gardé 7 jours, rien n'est publié).
5. **Recette** : sur un vrai téléphone et un ordinateur, avec les comptes de test, suivez :
   - le parcours complet : dépôt → stock → commande → livraison → réception ;
   - le test GPS écran verrouillé (compte rendu du lot 4, §5) ;
   - le scan du QR code (lot 5) ;
   - la **réception d'une notification push** (lot 6) ;
   - puis `docs/livraison/checklist-avant-decision.md`.

## 3. Après la recette (chaque étape seulement avec votre accord)

- Fusion des branches, dans l'ordre : vous ouvrez les pull requests.
- Production :
  - `APP_LOCALE=fr`, `CARTE_TUILES_URL`, `CARTE_ATTRIBUTION` et `FIREBASE_CREDENTIALS_BASE64` dans Render ;
  - export (`pg_dump`) de la base Neon de production avant les migrations 000018 à 000023 ;
  - vérification que *Auto-Deploy* est sur « Off ».
- Version Android signée (`docs/livraison/signature-android.md`), publiée en brouillon, à relire par vous.
