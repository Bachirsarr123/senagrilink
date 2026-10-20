# Liste de contrôle avant la décision de fusion, de déploiement ou de publication

Cocher chaque ligne avec sa **preuve** : lien vers l'exécution CI, capture ou journal. Un contrôle sans preuve est considéré comme non fait.
- **[CI]** automatique dans GitHub Actions ;
- **[PRÉPROD]** en préproduction Render ;
- **[APPAREIL]** sur un téléphone réel ;
- **[VOUS]** action manuelle de votre part.

## A. Automatisés (doivent être verts sur le dernier commit de la branche)
- [ ] **[CI] `backend-ci.yml`** :
  - tests SQLite ;
  - tests **PostgreSQL 16** avec concurrence (`ConcurrencePostgresTest`) ;
  - migrations : application, retour ciblé de `000018` qui conserve `historique_operations`, retour complet, réapplication ;
  - garde de démarrage (6 cas).
- [ ] **[CI] `web-ci.yml`** : `ng test` et compilation de production.
- [ ] **[CI] `mobile-ci.yml`** :
  - `flutter analyze` et `flutter test` ;
  - garde de signature (build de distribution refusée sans clé) ;
  - APK de test construit : INTERNET présente dans le manifeste fusionné ;
  - contrôles négatifs : clé de debug, empreinte erronée et versionCode non supérieur refusés.
- [ ] Le commit vérifié est **exactement** celui qui sera fusionné (comparer le SHA affiché dans chaque exécution).

## B. Configuration (avant toute fusion)
- [ ] **[VOUS]** Render, services de production : *Auto-Deploy* **Off**.
- [ ] **[VOUS]** Render : prévisualisations de pull request **désactivées**.
- [ ] **[VOUS]** Render, base de production : plan, date d'expiration, et **export `pg_dump` réalisé et relu** (taille, présence des tables).
- [ ] **[VOUS]** Render, backend de production : valeurs de `BROADCAST_CONNECTION` et de `SANCTUM_EXPIRATION` relevées.
- [ ] **[VOUS]** `php artisan migrate:status` dans le shell du backend de production (lecture seule) : `000017` exécutée ou non.
  - Si oui, le déploiement exécutera `000018` seule.
  - Si non, il exécutera `000017` et `000018`.

## C. Migrations
- [ ] Une seule migration nouvelle dans la branche : `2025_01_01_000018_create_requetes_idempotentes_table` (additive). `migrate --pretend` en préproduction le confirme.
- [ ] Retour arrière connu de tous :
  1. *Rollback* Render du code (suffisant) ;
  2. si nécessaire, `migrate:rollback --path=database/migrations/2025_01_01_000018_create_requetes_idempotentes_table.php --force`.
- [ ] **Interdit :** `migrate:rollback` sans `--path`. Si `000017` et `000018` sont dans le même lot, il supprimerait aussi l'historique (`historique_operations`).

## D. Android
- [ ] **[VOUS]** Identifiant définitif choisi (voir `signature-android.md` §5).
- [ ] **[VOUS]** Clé de distribution créée, **sauvegardée deux fois**, empreinte relevée.
- [ ] **[VOUS]** Environnement `android-distribution` configuré : approbation requise, secrets et variable `ANDROID_CERT_SHA256`.
- [ ] **[CI]** Brouillon produit par `mobile-release.yml`. Le script de vérification affiche :
  - INTERNET présente ;
  - pas de clé de debug ;
  - empreinte égale à `ANDROID_CERT_SHA256` ;
  - (versions suivantes) compatibilité avec `reference_tag`.
- [ ] **[APPAREIL]** Installation du brouillon sur un Android réel, **après désinstallation** de toute ancienne version.

## E. Parcours complets en préproduction, avec des comptes distincts
Comptes de test : producteur, gestionnaire, acheteur, transporteur A, transporteur B, administrateur.

- [ ] **[PRÉPROD]** Récolte, puis réservation d'entrepôt, puis confirmation, puis assignation au transporteur A. A voit la mission, B ne la voit pas ; réassignation à B : A ne la voit plus.
- [ ] **[PRÉPROD]** Arrivée signalée, puis réception : le stock est créé **une seule fois** (double clic sur « Valider »).
- [ ] **[PRÉPROD]** Commande de l'acheteur, puis confirmation (stock débité une fois), puis livraison, transporteur, démarrage et livraison.
- [ ] **[PRÉPROD]** Refus et annulations (réservation, commande, confirmation) avec motif, historique visible côté entrepôt.
- [ ] **[PRÉPROD]** Changement de mot de passe : l'ancien est refusé, l'autre appareil est déconnecté, l'adresse est conservée.
- [ ] **[PRÉPROD]** Site installé sur l'écran d'accueil : réouverture sans reconnexion.
- [ ] **[APPAREIL]** Mêmes parcours, pour les rôles prévus, avec l'APK de test pointant vers la préproduction :
  - producteur : récolte ;
  - acheteur : commande ;
  - transporteur : missions et GPS, écran ouvert ;
  - administrateur : déconnexion.

## F. Pannes simulées
- [ ] **[PRÉPROD]** Reverb absent (configuration par défaut) :
  - les actions répondent par un succès ;
  - le journal contient « Diffusion temps réel impossible » ;
  - la notification reste visible après rechargement.
- [ ] **[PRÉPROD]** Réponse perdue : couper le réseau juste après « Confirmer la commande », le rétablir, recommencer. Résultat attendu : **une seule** commande.
- [ ] **[APPAREIL]** Mode avion sur chaque écran mobile : message et « Réessayer », jamais de chargement infini.
- [ ] **[APPAREIL]** Réseau Wi-Fi à portail captif, si possible : le suivi GPS ne s'arrête pas définitivement.
- [ ] **[PRÉPROD]** Backend endormi (plus de 15 minutes) : le premier appel aboutit, ou affiche un message, sans perte.

## G. Retour arrière (répété au moins une fois en préproduction)
- [ ] **[PRÉPROD]** *Rollback* Render vers le commit précédent : le service démarre et les données restent intactes.
- [ ] **[PRÉPROD]** `migrate:rollback --path=…000018…` puis `migrate` : sans erreur, l'historique est conservé.
- [ ] **[VOUS]** Plan de retour arrière de production écrit avec : la personne qui décide, le commit de retour, et l'export de base à restaurer en dernier recours.

## H. Décision
- [ ] Toutes les lignes ci-dessus cochées avec leur preuve.
- [ ] **[VOUS]** Autorisation explicite et séparée pour chacune de ces trois opérations :
  1. la fusion ;
  2. le déploiement Render (manuel) ;
  3. la publication du brouillon APK.
