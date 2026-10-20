# Lot 0 — Compte rendu (sécurisation immédiate)

*Branche `claude/senagrilink-full-audit-mjty0f`, à partir de `main` (`20ab14b`) et de l'audit (`bab9fa5`).*
*Rien n'a été fusionné ni déployé. Aucune donnée ni variable de production n'a été modifiée. Aucune migration n'a été lancée sur Render.*

Références : [audit général](2026-10-audit-general.md), numéros P-xx.

## 1. Problèmes corrigés et causes

| # | Problème | Cause exacte | Correction | Reproduit avant ? |
|---|---|---|---|---|
| P-01 | Action enregistrée mais réponse 500 si Reverb est absent | Canal `broadcast` synchrone ; le client Pusher lève `BroadcastException`, propagée jusqu'au contrôleur | Canal `broadcast` tolérant (`DiffusionToleranteChannel`), enregistré dans `AppServiceProvider` : l'échec est journalisé (`warning`). La notification en base, écrite avant, est conservée et les destinataires suivants sont notifiés. | Oui : test (`BroadcastException`) et API locale (500 avec commande créée) |
| P-25 | Doublons quand l'utilisateur recommence | Aucune protection : chaque `POST` crée un enregistrement | En-tête `Idempotency-Key`, middleware `idempotence` sur les 7 routes de création. Une même soumission renvoie la réponse d'origine ; un échec n'est pas mémorisé. Table additive `requetes_idempotentes`, purgée après 24 h. Clés générées par le web et le mobile. | Oui : sonde API (2 commandes au double envoi) |
| P-05 | Double réception d'une marchandise, donc double stock | `validerMarchandise` vérifiait le statut hors verrou | `lockForUpdate` + revérification | Oui : test PostgreSQL en concurrence (échoue sur l'ancien code) |
| P-08 | Sortie de stock : mise à jour perdue | Lecture puis écriture sans verrou | Idem | Oui : idem |
| P-10 | Capacité d'entrepôt dépassée, double confirmation | Calcul de capacité et confirmation sans verrou | Verrou sur l'entrepôt puis sur la réservation | Oui : idem |
| — | Deux livraisons pour une commande | Unicité vérifiée en PHP seulement | Verrou sur la commande + revérification | Oui : idem |
| — | Arrivée signalée ou livraison mise à jour par un transporteur qui vient d'être remplacé | Propriété vérifiée avant, écriture après | Revérification sous verrou (403) | Oui : idem |
| — | Modification de commande appliquée à une commande en cours de confirmation | Statut vérifié hors verrou | Verrou commande + stock | Non reproduit en concurrence ; même schéma que les cas ci-dessus |
| P-07 | Mobile inutilisable sur les données PostgreSQL | `pdo_pgsql` renvoie `float`/`decimal` en chaînes ; Flutter faisait `as num` | Backend : casts `float` dans 9 modèles. Mobile : conversion tolérante (`nombre()`) dans les modèles, la carte et la chronologie | Oui : capture réelle (`'70.5'`) ; anciens modèles Flutter : `String is not a subtype of num` |
| P-06 | APK release sans accès réseau | `INTERNET` absente du manifeste principal | Permission ajoutée | Vérifié dans le code seulement (voir §6) |
| P-09 | Acheteur ne peut pas commander sur mobile | Aucun écran n'appelait `passerCommande` | Bouton « Commander » et formulaire (mêmes champs et contrôles que le web) | Oui : test d'écran (échoue sur l'ancien code) |
| P-19 | Administrateur bloqué sur mobile | Écran sans déconnexion, session persistante | Message « administration sur le site web » + bouton « Se déconnecter » | Oui : idem |
| P-20 | Chargements infinis sur mobile | Pas de délai d'attente ; erreurs réseau, `FormatException` et réponses HTML non converties | Délai de 60 s ; réseau, délai et réponse non JSON deviennent une `ApiException`, que tous les écrans savent afficher | Oui : test d'écran (échoue sur l'ancien code) |
| P-17 | Mot de passe ignoré ; adresse effacée (web) | Champ non traité par le backend ; formulaire web initialisé à vide | Changement réel avec **mot de passe actuel exigé** (sécurité standard) et révocation des autres sessions ; adresse préremplie | Oui (lecture du code) ; tests ajoutés |
| P-16 | Web : livraison en échec après confirmation, la commande paraît bloquée | Le nouvel essai reconfirmait (422) | Le nouvel essai ne crée que la livraison (même clé) ; message explicite ; liste rechargée | Oui : test de composant |
| P-21 (part.) | Alertes : « ✅ tout va bien » sur erreur | Erreur avalée | Message d'erreur + « Réessayer » | Oui : test de composant |

**Effet secondaire traité :** l'écran de suivi mobile distinguait la coupure réseau par le type d'exception. Il traite maintenant l'`ApiException` sans code comme une coupure : la dernière position reste affichée. Détecté par un test existant, qui passe de nouveau.

## 2. Fichiers modifiés

- **Backend :**
  - Notifications : `app/Notifications/Channels/DiffusionToleranteChannel.php` (nouveau), `app/Providers/AppServiceProvider.php`.
  - Idempotence :
    - `app/Http/Middleware/Idempotence.php` (nouveau) ;
    - `database/migrations/2025_01_01_000018_create_requetes_idempotentes_table.php` (nouvelle, additive, `down()` = suppression de la table) ;
    - `bootstrap/app.php`, `routes/api.php`.
  - Verrous : `app/Http/Controllers/Api/{Reservation,Livraison,Commande,Stock}Controller.php`.
  - Types numériques : 9 modèles (`Stock`, `Commande`, `Production`, `Reservation`, `Entrepot`, `Livraison`, `Producteur`, `Transporteur`, `AcheteurGros`).
  - Mot de passe : `AuthController.php`, `Requests/UpdateProfileRequest.php`.
  - Tests :
    - nouveaux : `NotificationResilienceTest`, `IdempotenceTest`, `TypesNumeriquesJsonTest`, `ChangementMotDePasseTest` ;
    - étendu : `ConcurrencePostgresTest`, avec 5 nouveaux cas.
- **Web :**
  - `shared/idempotence/cle-soumission.ts` (nouveau) et `core/services/api.service.ts`.
  - Composants : catalogue, productions, réservations du producteur, stocks, utilisateurs (admin), commandes de l'entrepôt, alertes, profil.
  - `features/lot0.spec.ts` (nouveau).
- **Mobile :**
  - `android/app/src/main/AndroidManifest.xml` ;
  - `lib/services/api_service.dart` ;
  - `lib/utils/nombres.dart` (nouveau), `lib/utils/geo.dart`, `lib/utils/chronologie.dart` ;
  - les 3 modèles ;
  - `lib/screens/acheteur/formulaire_commande.dart` (nouveau), `catalogue_screen.dart`, `suivi_livraison_screen.dart` ;
  - `home_router.dart`, `nouvelle_recolte_screen.dart`, `planifier_ventes_screen.dart`, `profil_screen.dart` ;
  - Tests :
    - nouveaux : `parcours_lot0_test`, `nombres_postgresql_test`, `donnees_postgresql_reelles_test` (avec la capture `fixtures/api_postgresql.json`), `configuration_android_test` ;
    - complété : `api_service_test`.

## 3. Tests exécutés et résultats

| Type | Où | Résultat |
|---|---|---|
| Automatisés backend, SQLite | `php artisan test` | 143 réussis, 8 ignorés (tests PostgreSQL seulement) |
| Automatisés backend, **PostgreSQL 16** local, concurrence incluse | idem avec `DB_CONNECTION=pgsql` | **151 réussis** |
| Preuve des reproductions | mêmes tests sur l'ancien code (fichiers mis de côté temporairement) | 5 tests de concurrence échouent ; `TypesNumeriquesJsonTest` échoue (`'80.5'`) |
| Automatisés Angular | `ng test` | 74 réussis |
| Compilation web | `tsc`, `ng build` | OK |
| Automatisés Flutter | `flutter test` | 197 réussis |
| Analyse Flutter | `flutter analyze` | 4 remarques de style préexistantes, aucune erreur |
| Preuve des reproductions mobile | nouveaux tests sur l'ancien `lib/` | 6 échecs (écrans, manifeste) et 3 échecs sur la capture PostgreSQL |
| **API réelle**, PostgreSQL local + Reverb configuré mais absent (comme Render) | sonde Python, 4 comptes | 7/7 : commande 201 au lieu de 500 ; nouvel essai rejoué, 1 seule commande et 1 seule notification par destinataire ; confirmation 200, stock débité une fois ; confirmation répétée 422, stock inchangé |
| **Navigateur** (Chromium, site Angular local, même API) | Playwright | 5/5 : réponse perdue après enregistrement, erreur affichée ; le nouvel essai affiche le succès, **sans doublon**, même clé |
| Appareil physique | — | **Non réalisé** (aucun téléphone ni SDK Android disponible) |

## 4. Problèmes toujours présents

- **Dépendant d'une décision (§5) :**
  - P-02 (livraison « probleme » sans issue) ;
  - P-03 (commande jamais « livrée ») ;
  - P-04 (prix saisi par l'acheteur) ;
  - P-11, P-12, P-13, P-14 (réception, lots, refus de marchandise) ;
  - P-18 (inscription libre en gestionnaire) ;
  - P-22 (GPS en arrière-plan) ;
  - P-26 (prix visibles des autres producteurs).
- **Corrigeables techniquement, laissés pour les lots suivants :**
  - P-15 (profils vides créés par l'admin) ;
  - P-21 (erreurs avalées dans une douzaine d'autres écrans web) ;
  - rapport admin cassé ;
  - P-24 (notifications manquantes) ;
  - P-27 à P-32 ;
  - P-33 (collision de numéros) ;
  - P-36 et P-37 ;
  - P-38 (seeder cassé) ;
  - P-39 et P-40 ;
  - mise à jour du profil mobile non reflétée avant reconnexion ;
  - limitation de débit sur `/register`.
- **Contrainte d'unicité `livraisons.commande_id` en base :** non ajoutée. La migration échouerait si la production contient déjà des doublons, et je ne peux pas le vérifier. Le verrou applicatif protège dès maintenant.
- **P-23 :** la signature release Android utilise toujours la clé de debug. Il faut votre keystore, que je ne dois pas créer à votre place.
- **CI :** les tests PostgreSQL et de concurrence ne tournent pas dans Jenkins (il faudrait un service PostgreSQL).

## 5. Décisions métier encore nécessaires

Questions Q1 à Q14 de l'audit, en particulier :
- **Q3 et Q8** (clôture d'une livraison, issue d'un problème) : bloquent P-02 et P-03.
- **Q5** (prix) : bloque P-04.
- **Q10, Q11, Q14** (réception, lots multi-entrepôts, sorties manuelles).
- **Q7** (validation des gestionnaires et transporteurs).
- **Q9** (GPS en arrière-plan).

Une précision sur le mot de passe : j'ai exigé le mot de passe actuel, ce qui est une règle de sécurité standard. J'ai aussi déconnecté les autres sessions après un changement, ce que vous aviez indiqué comme légitime. Dites-moi si vous préférez l'inverse.

## 6. Risques résiduels et vérification en préproduction

**Risques :**
- **Notifications :** P-01 reste une hypothèse forte pour la production, tant que les journaux Render ne sont pas consultés. Si `BROADCAST_CONNECTION` y est déjà neutralisé, la correction est sans effet visible mais inoffensive. Les diffusions temps réel restent absentes tant que Reverb n'est pas déployé : le site et le mobile ne voient les notifications qu'au rechargement ou par l'interrogation périodique du mobile.
- **Idempotence :** seuls les clients mis à jour envoient la clé. Une ancienne version de l'APK reste sans protection, mais fonctionne comme avant.
- **Types numériques :** les quantités saisies auparavant sont inchangées en base ; seul leur format JSON change. Le web les lisait déjà correctement.
- **Mobile :** le délai de 60 s peut se déclencher pendant un réveil Render très long ; l'utilisateur voit alors « Le serveur ne répond pas » et peut réessayer.

**Étapes de vérification en préproduction** (service Render séparé, avec une copie de la base) :
1. Sauvegarder la base de production. Créer la préproduction sur une **copie**, jamais sur la base réelle.
2. Déployer la branche ; `start.sh` lance `migrate`. Vérifier que seule `2025_01_01_000018` s'applique (`php artisan migrate:status`).
3. Avec un compte acheteur de test : passer une commande. Attendu : message de succès et aucune ligne `Pusher error` en erreur dans les journaux, seulement un avertissement « Diffusion temps réel impossible ».
4. Couper le réseau (mode avion) juste après « Confirmer la commande », le rétablir, recommencer : une seule commande dans « Mes commandes ».
5. Gestionnaire : confirmer cette commande. Attendu : succès et stock diminué une seule fois.
6. Construire l'APK (CI GitHub Actions) et l'installer sur un Android réel :
   - connexion avec chaque rôle ;
   - listes des stocks, des productions et des commandes affichées (P-07) ;
   - commande depuis le catalogue (P-09) ;
   - administrateur : bouton « Se déconnecter » (P-19) ;
   - mode avion : message au lieu d'un chargement infini (P-20).
7. Profil : changer le mot de passe (avec l'actuel). L'ancien est refusé, le nouveau accepté, et l'autre appareil est déconnecté. L'adresse est conservée.
8. **Retour arrière :**
   - redéployer le commit précédent (« Rollback » Render) ;
   - la table `requetes_idempotentes` peut rester (inoffensive) ou être supprimée par `php artisan migrate:rollback --step=1` ;
   - aucune autre donnée n'est modifiée par le lot.
