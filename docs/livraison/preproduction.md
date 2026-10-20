# Préproduction isolée et déclencheurs de livraison

*Préparé sur la branche `claude/livraison-preproduction`. Rien n'a été créé ni déployé sur Render.*

## 1. Ce qui déclenche quoi (constaté dans le dépôt)

| Événement | Ce qui se passe, d'après le dépôt | À vérifier dans les tableaux de bord |
|---|---|---|
| Push ou fusion sur `main` | **Actuellement** (fichiers de `main`) : `mobile-apk.yml` publie un APK public si `mobile-app/**` change. **Avec cette branche fusionnée** : plus aucune publication ; seules les CI tournent. | GitHub → Actions : aucun autre workflow actif |
| Push ou fusion sur `main` (Render) | `render.yaml` actuel ne fixe pas le déclenchement. Pour un nouveau service, la valeur par défaut de Render est « déploiement à chaque commit ». Cette branche ajoute `autoDeployTrigger: off`, qui ne s'applique qu'à la **synchronisation du Blueprint**. | Render → chaque service → *Settings → Build & Deploy → Auto-Deploy* : **doit être « Off »** avant toute fusion |
| Déploiement du backend (Render) | `start.sh` : copie `.env.example` si `.env` est absent, puis `config:cache`, puis **`php artisan migrate --force`** sur la base configurée, puis démarrage | Render → backend → Environment : `DB_*`, `BROADCAST_CONNECTION`, `SANCTUM_EXPIRATION` |
| Pull request (Render) | `render.yaml` actuel n'active pas les environnements de prévisualisation (désactivés par défaut pour un Blueprint). Cette branche les désactive explicitement (`previews.generation: off`). | Render → Blueprint et services → *Previews / Pull Request Previews* : **désactivés** |
| Synchronisation du Blueprint | Applique `render.yaml` (services, variables déclarées). La documentation Render ne précise pas si elle se fait automatiquement. | Render → Blueprint → *Settings* : mode de synchronisation |
| Pull request (GitHub) | `backend-ci.yml`, `web-ci.yml` et `mobile-ci.yml` testent sans rien déployer | — |

**À retenir :** tant que *Auto-Deploy* n'est pas confirmé « Off » dans Render, fusionner sur `main` équivaut à déployer la production et à migrer sa base.

## 2. Points critiques à vérifier d'abord dans Render

1. **Base de production :** `render.yaml` la déclare `plan: free`. D'après la documentation Render, une base gratuite :
   - **expire 30 jours après sa création** ;
   - est **supprimée 14 jours plus tard** ;
   - **n'a aucune sauvegarde**.

   Il faut vérifier immédiatement son plan réel, sa date de création ou d'expiration, et faire un export manuel (`pg_dump`) avant toute opération.
2. **Une seule base gratuite par espace de travail Render :** la préproduction ne peut pas en avoir une seconde gratuite (§3).
3. **Variables réelles du backend de production**, sans recopier les secrets : présence et valeur de `BROADCAST_CONNECTION`, de `SANCTUM_EXPIRATION`, et des `DB_*` (`fromDatabase` ou valeurs saisies).

## 3. Méthode de préproduction proposée

**Principe :** un second Blueprint Render (`render-preproduction.yaml`), avec des services `-preprod` et une **base de test séparée, saisie à la main**. Il n'y a aucun lien possible vers la base de production.

**Étapes (à faire par vous) :**
1. **Créer la base de test**, au choix :
   - une base PostgreSQL 16 gratuite chez un autre hébergeur (par exemple Neon) ;
   - une base Render **payante** séparée.

   Nommez la base **`senagrilink_preprod`** : la garde exige ce nom exact.
2. **(Facultatif) Données de test :** partir d'une base vide, ou d'une copie **anonymisée** d'un export de production (noms, e-mails, téléphones remplacés). Ne jamais restaurer un export brut contenant des données personnelles réelles.
3. **Render → New → Blueprint** → dépôt `Bachirsarr123/senagrilink`, chemin **`render-preproduction.yaml`** → saisir :
   - `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD` de la base de **test** ;
   - `SENAGRILINK_HOTE_INTERDIT` = hôte de la base de **production** (non secret).
4. **Vérifier les URL attribuées.** Si Render a modifié les noms, ajuster :
   - `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS` et `APP_URL` (backend) ;
   - `API_URL` (site).
5. **Déploiement manuel** (*Manual Deploy*) du backend, puis du site. Dans les journaux, attendre la ligne `[garde] Base vérifiée : senagrilink_preprod …` avant les migrations. Si c'est `[garde] REFUS`, le service s'arrête **sans migrer**.
6. **APK de test pointant vers la préproduction :** GitHub → Actions → « Mobile — CI » → *Run workflow* sur la branche, avec `api_url` = `https://senagrilink-backend-preprod.onrender.com/api`. Télécharger l'artefact `apk-test-…`.
7. **Après la recette :** suspendre les services `-preprod` (ou les supprimer) et purger la base de test si elle contient une copie de données.

**Isolation garantie par :**
- l'absence de `fromDatabase` ;
- la garde de démarrage (nom de base et hôte interdit) ;
- `MAIL_MAILER=log` (aucun e-mail réel) ;
- une `APP_KEY` propre ;
- `noindex` sur le site ;
- `autoDeployTrigger: off`.

## 4. Retour arrière de la migration 000018
- Commande : `php artisan migrate:rollback --path=database/migrations/2025_01_01_000018_create_requetes_idempotentes_table.php --force`.
- Elle affiche « Migration not found » pour chacune des autres migrations du dernier lot. C'est normal : elles sont hors du chemin indiqué et **restent en place**. Seule `requetes_idempotentes` est supprimée ; `historique_operations` est conservée (vérifié à chaque exécution de `backend-ci.yml`).
- Ne jamais lancer `migrate:rollback` sans `--path`.

## 5. Limites de la préproduction gratuite
- Les services gratuits s'endorment après 15 minutes et mettent environ 1 minute à se réveiller : le délai d'attente mobile de 60 s peut se déclencher au premier appel (comportement à observer, pas un défaut).
- Les heures gratuites (750 h/mois) sont partagées avec la production : suspendez la préproduction entre deux recettes.
