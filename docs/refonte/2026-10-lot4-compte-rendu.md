# Lot 4 — GPS fiable : compte rendu

*Branche `claude/lot4-gps-fiable`, partie de `claude/lot3-circuit-livraison`. Rien n'a été fusionné ni déployé, et aucun APK n'a été publié.*

## 1. Ce qui change

| Besoin | Avant | Maintenant |
|---|---|---|
| **Confidentialité** | Positions liées au transporteur : une ancienne livraison permettait de suivre sa mission suivante | **Chaque position est liée à sa mission.** Seuls les acteurs de cette mission la voient, et **seulement pendant le trajet** (Q11) |
| **Deux circuits** | Livraisons aux acheteurs seulement | Livraisons **et** acheminements vers l'entrepôt, y compris quand **le producteur livre lui-même** |
| **Téléphone verrouillé** | Le partage s'arrêtait dès que le transporteur quittait l'écran | Le partage passe par un **service Android de premier plan**, avec la notification « Suivi de livraison actif ». Il continue écran quitté, application en arrière-plan et téléphone verrouillé |
| **Coupure réseau** | Positions perdues | Positions **gardées sur le téléphone (ou dans le navigateur)** avec leur heure, puis **renvoyées par paquets**, sans doublon |
| **Reprise** | — | Au redémarrage de l'application, le partage d'une mission en route **reprend tout seul** |
| **Arrêt** | Manuel | À l'arrivée, ou dès que le serveur répond que la mission n'est plus en route |
| **Qui voit la carte** | Acheteur et producteur | Acheteur, producteur du lot, **gestionnaire de l'entrepôt**, administrateur, et le conducteur lui-même |
| **Heure d'arrivée estimée** | Distance à vol d'oiseau seulement | **« Arrivée vers 14:35, dans 35 min · 42 km »**, calculée par le serveur (voir §3) |
| **Trajet** | Ligne droite | **Trace réellement parcourue**, puis le reste du trajet **par la route** quand un service de routage est configuré |
| **Bouton « Itinéraire »** | Site seulement | Site **et application** : vers l'entrepôt ou le producteur avant le chargement, puis vers la destination |
| **Partage depuis le site** | Impossible | Le transporteur peut partager sa position **depuis le navigateur** tant que la page est ouverte (secours ; l'application reste la solution pour l'écran verrouillé) |
| **Fond de carte** | Serveurs publics d'OpenStreetMap, codés en dur | **Configurable par le serveur** (voir §3) |
| **Conservation** | Aucune limite | Positions **supprimées après 30 jours** |

Une position ancienne n'est **jamais présentée comme actuelle** : au-delà de 60 secondes, l'écran affiche « Dernière position connue · il y a X min ».

**Une seule définition du suivi** : `backend/app/Support/SuiviGps.php` (qui conduit, qui peut voir, départ et arrivée de chaque mission).

## 2. Vérifications réalisées

| Type | Où | Résultat |
|---|---|---|
| Tests automatiques du serveur, SQLite | Local | **194 réussis**, 10 ignorés (tests réservés à PostgreSQL) |
| Tests automatiques du serveur, **PostgreSQL 16** | Local | **204 réussis** |
| Cycle des migrations : retour ciblé de 000022, puis 000021 à 000018, réapplication, retour complet | Local (PostgreSQL), même script que la CI | Réussi |
| Tests du site, compilation de production | Local | **100 réussis**, compilation sans avertissement |
| Tests de l'application, dont la file hors connexion, la reprise au démarrage et la mise en page sur 5 formats | Local (Flutter 3.47.7) | **259 réussis** ; analyse sans erreur |
| **Parcours réel dans un navigateur**, position GPS simulée, coupure réseau simulée | Chromium | **14 contrôles sur 14 réussis** (détail ci-dessous) |
| Vérifications automatiques GitHub | GitHub Actions | Voir §6 |

**Le parcours navigateur contrôle :**
- aucune position transmise avant le départ ;
- le partage qui démarre seul au départ ;
- les positions reçues et liées à la livraison, avec l'arrivée estimée ;
- deux positions prises **hors connexion**, gardées puis renvoyées au retour du réseau, sans perte ;
- la carte de l'acheteur (arrivée estimée, conducteur, « En direct », trace et reste du trajet) ;
- l'accès du gestionnaire ;
- le refus pour un autre acheteur et pour un autre transporteur ;
- l'arrêt du partage à l'arrivée, puis plus aucune position visible.

**Non vérifié — à faire par vous :**
- **aucun test sur un téléphone Android réel** : c'est le seul moyen de vérifier le fonctionnement écran verrouillé (procédure au §5) ;
- aucun test en préproduction Render, ni sur la production ;
- le trajet routier réel : il dépend du service de routage choisi (§3). Il est testé automatiquement avec un service simulé.

## 3. Réglages à décider avant l'ouverture au public

Ce sont des **variables d'environnement du serveur**. Je n'ai créé aucun compte ni aucune clé.

| Variable | Rôle | Par défaut |
|---|---|---|
| `CARTE_TUILES_URL`, `CARTE_ATTRIBUTION` | Fond de carte. Les serveurs publics d'OpenStreetMap **interdisent un usage applicatif en production**. Il faut un fournisseur (MapTiler, Stadia Maps, Thunderforest…, offres gratuites limitées) : son adresse de tuiles, avec sa clé publique, se colle ici. | Tuiles publiques OpenStreetMap (tests seulement) |
| `ROUTAGE_URL` | Service de routage compatible OSRM, pour le trajet par la route et une heure d'arrivée réaliste. Sans lui, l'estimation est approximative : distance à vol d'oiseau × 1,3, à la vitesse récente du véhicule (ou 40 km/h). | Non défini (estimation approximative) |
| `SUIVI_CONSERVATION_JOURS` | Durée de conservation des positions | 30 |
| `SUIVI_VITESSE_DEFAUT_KMH` | Vitesse utilisée quand le véhicule est arrêté ou que sa vitesse est inconnue | 40 |

- La purge des positions anciennes est planifiée chaque nuit (`php artisan suivi:purger`, via le planificateur Laravel). Elle est aussi déclenchée de temps en temps à la réception des positions : elle fonctionne donc **même sans tâche planifiée** sur Render.
- L'estimation est recalculée au plus une fois par minute et partagée par tous les acteurs.

## 4. Base de données

- **Nouvelle migration** : `2025_01_01_000022_create_suivi_gps`.
  - Les positions reçoivent la mission suivie, la personne qui conduit, l'heure de réception, la précision, la vitesse et le cap.
  - Le transporteur devient facultatif sur une position : le producteur qui livre lui-même n'a pas de profil transporteur.
  - Les livraisons et les acheminements reçoivent l'arrivée estimée, la distance restante et le trajet.
  - Les acheminements reçoivent leurs heures de départ et d'arrivée.
- **Reprise** : les anciennes positions ne sont liées à aucune mission. Elles ne sont plus affichées et partiront avec la purge des 30 jours.
- **Retour arrière**, toujours de la plus récente à la plus ancienne : `migrate:rollback --path=database/migrations/2025_01_01_000022_create_suivi_gps.php --force`, puis 000021 à 000018. Ce cycle est vérifié à chaque exécution de `backend-ci.yml`.

## 5. Test sur un vrai téléphone (à faire par vous)

Ce test demande un serveur qui contient les lots 1 à 4 : la **préproduction**, déployée seulement avec votre autorisation (voir `docs/livraison/preproduction.md`). La production actuelle ne connaît pas ces écrans.

1. Après le déploiement de la préproduction, lancez manuellement « Mobile — CI ». Renseignez `api_url` avec l'adresse de l'API de préproduction. Téléchargez l'APK de test dans les fichiers produits par l'exécution : ils sont gardés 7 jours, et rien n'est publié.
2. Installez-le sur un téléphone Android, connectez-vous avec un compte transporteur de test et **autorisez la localisation** (« pendant l'utilisation de l'application » suffit). Sous Android 13 ou plus, acceptez aussi les notifications.
3. Avec un second compte (acheteur), sur un ordinateur, organisez une livraison jusqu'au chargement.
4. Sur le téléphone : « Je pars vers l'acheteur ». La notification « Suivi de livraison actif » doit apparaître.
5. **Verrouillez le téléphone** et déplacez-vous 10 à 15 minutes. Sur l'ordinateur, la carte de l'acheteur doit avancer, et « En direct » doit rester affiché.
6. Activez le **mode avion** quelques minutes en roulant, puis désactivez-le. La trace doit se compléter, sans trou.
7. « Je suis arrivé chez l'acheteur » : la notification disparaît et la carte affiche « Trajet terminé ».

Certains fabricants (Xiaomi, Huawei, Tecno, Infinix, Samsung…) arrêtent les applications en arrière-plan de façon agressive. Si le suivi s'arrête écran verrouillé, réglez l'application sur « Sans restriction » dans les réglages de batterie du téléphone. C'est justement ce que ce test doit révéler.

## 6. Vérifications GitHub sur la branche

| Vérification | Commit | Résultat |
|---|---|---|
| Serveur : SQLite, PostgreSQL 16 (concurrence comprise), cycle des migrations 000022 → 000018, garde de démarrage (exécution 39628512664) | `0459253` | **Réussi** |
| Site : tests et compilation de production (exécution 39628512712) | `0459253` | **Réussi** |
| Mobile : analyse, tests, APK de test construit ; **permissions du suivi GPS contrôlées dans l'APK** (service de premier plan présent, pas de localisation en arrière-plan) ; sans publication (exécution 39628512845) | `0459253` | **Réussi** |

## 7. Limites connues

| Limite | Suite |
|---|---|
| Si l'utilisateur ferme l'application de force (balayage dans les applications récentes), le partage s'arrête jusqu'à sa réouverture, puis il reprend. | Comportement Android standard |
| Une seule mission partagée à la fois par téléphone (la première en route) | Suffisant pour l'usage actuel |
| iPhone : le site fonctionne, mais le partage en arrière-plan n'y est pas possible (Q16) | Hors périmètre |
| Les positions arrivent par interrogation toutes les 10 secondes, pas en temps réel par WebSocket | Suffisant pour le suivi, à revoir si besoin |
| Notifications push sur le téléphone (Firebase, Q15) | Lot 5 ou 6 |
