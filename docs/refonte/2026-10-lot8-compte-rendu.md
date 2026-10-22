# Lot 8 — Positions automatiques : compte rendu

*Branche `claude/lot8-positions-automatiques`, partie de `claude/lot7-recette-mise-en-ligne`. Rien n'a été fusionné, et aucun APK n'a été publié. Le déploiement de la préproduction se fait seulement avec votre accord.*

## 1. Ce qui change (demande du 22 octobre, « comme Yango »)

| Moment | Avant | Maintenant | Site | Application |
|---|---|---|---|---|
| **Le producteur choisit un entrepôt** | Liste sans distance | Sa position est prise **toute seule**, et les entrepôts **les plus proches** viennent d'abord, avec leur distance (« à 3,2 km ») | ✅ | ✅ |
| **Dépôt avec transporteur** | Aucun lieu de prise en charge : le bouton « Itinéraire » n'apparaissait pas | Le **lieu de prise en charge** est rempli automatiquement avec la position du téléphone, et une carte permet de le corriger d'un appui. Il est gardé sur le dépôt. | ✅ | ✅ |
| **Commande de l'acheteur** | Lieu de livraison seulement dans le profil, rarement rempli | Bandeau « 📍 Livraison à votre position actuelle » sur le catalogue, **modifiable sur la carte**. Le lieu part avec chaque commande, puis avec la livraison. | ✅ | ✅ |
| **Emplacement de l'entrepôt** | À saisir à la main dans le profil | Le profil propose la position actuelle (à confirmer en enregistrant). Tant que l'entrepôt n'est pas placé, « À faire » affiche en tête « 📍 Placez votre entrepôt sur la carte ». | ✅ | ✅ |
| **Transporteur** | « Itinéraire » absent faute de position | « Itinéraire vers le producteur » mène au **lieu exact de prise en charge**, puis « Itinéraire vers l'entrepôt » ; pour une livraison, vers l'entrepôt puis **le lieu choisi par l'acheteur** (Google Maps) | ✅ | ✅ |

**Règles communes :**
- **Personne n'a à taper sa position.** Si la position manque (GPS éteint, autorisation refusée), le serveur reprend le **lieu habituel** du profil.
- Le **premier lieu connu devient le lieu habituel** : il sert la fois suivante, même sans GPS.
- Un lieu différent ce jour-là (corrigé sur la carte) reste propre à ce dépôt ou à cette commande. Le lieu habituel ne change pas.
- **L'autorisation de position n'est demandée qu'une fois.**
  - Accordée : le téléphone (ou le navigateur) la retient, et rien n'est plus demandé.
  - Refusée : l'application ne la redemande jamais d'elle-même. La personne peut toujours placer le point sur la carte.
- Toutes les cartes utilisent maintenant le fond **MapTiler** réglé sur le serveur.

**Une seule définition** des lieux : `backend/app/Support/Lieux.php`.

## 2. Vérifications réalisées

| Type | Où | Résultat |
|---|---|---|
| Tests automatiques du serveur, SQLite | Local | **210 réussis**, 10 ignorés (réservés à PostgreSQL), dont **4 nouveaux** sur les lieux |
| Tests automatiques du serveur, **PostgreSQL 16** | Local | **220 réussis** |
| Cycle des migrations : retour ciblé de 000024, puis 000023 à 000018, réapplication, retour complet | Local, même script que la CI | Réussi |
| Tests du site, compilation de production | Local | **112 réussis** (4 nouveaux), compilation sans avertissement |
| Tests de l'application | Local (Flutter 3.47.7) | **333 réussis** (5 nouveaux) ; analyse sans erreur |
| Vérifications automatiques GitHub | GitHub Actions | Voir §5 |

**Non vérifié :**
- **aucun essai dans un navigateur réel ni sur un téléphone** : à faire en préproduction (§4) ;
- la précision du GPS sur le terrain dépend du téléphone (souvent 5 à 20 m dehors, davantage à l'intérieur) : la carte permet de corriger.

## 3. Base de données

- **Nouvelle migration** `2025_01_01_000024_create_lieux_automatiques` :
  - les dépôts reçoivent leur lieu de prise en charge (`collecte_latitude`, `collecte_longitude`, `collecte_adresse`) ;
  - les commandes reçoivent leur lieu de livraison (`livraison_latitude`, `livraison_longitude`, `livraison_adresse`).
- **Reprise** : les dépôts et les commandes existants n'ont pas de lieu propre. Ils gardent le lieu habituel du profil, comme avant.
- **Retour arrière**, toujours de la plus récente à la plus ancienne : `migrate:rollback --path=database/migrations/2025_01_01_000024_create_lieux_automatiques.php --force`, puis 000023 à 000018. Ce cycle est vérifié à chaque exécution de `backend-ci.yml`.

## 4. Essai en préproduction (avec votre accord)

`render-preproduction.yaml` pointe maintenant vers cette branche.

1. **Render → Blueprint `senagrilink-preprod` → Manual sync.**
2. Déployez le serveur, puis le site. La migration 000024 s'applique **sur la base de test uniquement**.
3. **Gestionnaire :** à l'entrepôt, ouvrez « À faire » → « Placez votre entrepôt sur la carte » → **Enregistrer**.
4. **Producteur, sur le téléphone :** « Déposer une récolte ». Les entrepôts sont triés par distance. Choisissez « Un transporteur vient la chercher » : la carte montre votre position. Corrigez-la si besoin, puis envoyez.
5. **Transporteur :** après l'acceptation, « Itinéraire vers le producteur » ouvre Google Maps sur le lieu exact.
6. **Acheteur :** le catalogue affiche « Livraison à votre position actuelle ». Commandez, puis suivez la livraison jusqu'à chez vous.

## 5. Vérifications GitHub sur la branche

| Vérification | Commit | Résultat |
|---|---|---|
| Serveur : SQLite, PostgreSQL 16, cycle des migrations 000024 → 000018, garde de démarrage, Dockerfile complet (exécution 39750075963) | `39e9183` | **Réussi** |
| Site : tests et compilation de production (exécution 39750075826) | `39e9183` | **Réussi** |
| Mobile : analyse, tests, APK de test construit et contrôlé, sans publication (exécution 39750076022) | `39e9183` | **Réussi** |
