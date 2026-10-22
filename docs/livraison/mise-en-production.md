# Mise en production (lots 1 à 13)

*Branche `claude/lot13-commander-payer` : elle contient `main` et tous les lots. Chaque étape est faite par vous, dans l'ordre. Je vérifie après chacune.*

| # | Où | Quoi |
|---|---|---|
| 1 | **Neon, projet de production** | **Sauvegarde** : *Branches → New branch*, nom `avant-lot13`, à partir de `main`, à l'instant présent. La branche est créée en quelques secondes, sans export. Pour revenir en arrière : *Restore* depuis cette branche. |
| 2 | Render, `senagrilink-backend` et `senagrilink-frontend` | *Settings → Auto-Deploy* : **Off** (à vérifier sur les deux services) |
| 3 | Render, `senagrilink-backend` → *Environment* | `APP_LOCALE` = `fr` ; `CARTE_TUILES_URL` et `CARTE_ATTRIBUTION` (mêmes valeurs qu'en préproduction) ; `FIREBASE_CREDENTIALS_BASE64` et `ROUTAGE_CLE` (mêmes contenus qu'en préproduction, collés par vous). Enregistrez **sans déployer** (*Save only*). |
| 4 | GitHub | Ouvrez la pull request `claude/lot13-commander-payer` → `main`, puis fusionnez-la |
| 5 | Render, `senagrilink-backend` | *Manual Deploy → Deploy latest commit*. Dans les journaux : migrations 000018 à 000027, puis le service *Live*. |
| 6 | Render, `senagrilink-frontend` | *Manual Deploy → Deploy latest commit* |
| 7 | Vous et moi | Contrôles : connexion, catalogue, commande, carte, notification |

**Commandes déjà en cours en production.** La migration 000027 les marque comme réglées en dehors de l'application (à la livraison), comme avant. L'entrepôt peut donc continuer à les traiter. Les commandes reçues restent réglées, les commandes annulées restent sans paiement.

**Application Android.** La version officielle signée demande une clé de distribution créée sur votre ordinateur (`signature-android.md`). En attendant, un APK de test pointant vers la production peut être construit (artefact privé de 7 jours, rien n'est publié).
