# Lot 2 — Circuit « récolte → entrepôt » : compte rendu

*Branche `claude/lot2-circuit-depot`, partie de `claude/lot1-fondations`. Rien n'a été fusionné ni déployé, et aucun APK n'a été publié.*

## 1. Le circuit tel qu'il fonctionne maintenant

| # | Étape | Qui agit | Sur le site | Sur l'application |
|---|---|---|---|---|
| 1 | Récolte enregistrée, avec son prix (lot 1) | Producteur | ✅ | ✅ |
| 2 | **Dépôt demandé** : entrepôt qui a la place, récolte, **mode de transport** (transporteur ou « je livre moi-même ») ; produit et quantité repris du lot | Producteur | ✅ | ✅ **nouveau** (écran « Mes dépôts ») |
| 3 | Dépôt accepté ou refusé (motif) ; **le gestionnaire est prévenu de chaque demande** | Gestionnaire | ✅ | — (étape 5) |
| 4 | Transporteur affecté, **les moins occupés proposés d'abord** | Gestionnaire | ✅ | — (étape 5) |
| 5 | **Mission acceptée, ou refusée avec un motif** (elle revient alors au gestionnaire) | Transporteur | ✅ | ✅ |
| 6 | **Chez le producteur**, puis **marchandise chargée** | Transporteur | ✅ | ✅ |
| 7 | **En route** vers l'entrepôt | Transporteur, ou producteur s'il livre lui-même | ✅ | ✅ |
| 8 | **Arrivé à l'entrepôt** | Transporteur ou producteur | ✅ | ✅ |
| 9a/9b | **Réception contrôlée** : code du lot, quantité reçue, état ; un écart exige un motif (réception partielle) ; un surplus doit tenir dans la capacité | Gestionnaire | ✅ | — (étape 5) |
| 9c | **Réception refusée** (motif) : aucun stock, la quantité revient au lot | Gestionnaire | ✅ | — (étape 5) |

**Règles appliquées par le serveur :**
- Les étapes se franchissent **dans l'ordre**, et seulement par la personne indiquée. Toute autre tentative est refusée avec un message clair.
- Le producteur peut **annuler jusqu'au départ**. Le transporteur affecté est alors prévenu. Après le départ, l'annulation est refusée.
- Le transporteur ne peut plus être changé une fois arrivé chez le producteur.
- Un lot peut être déposé **en plusieurs fois**, mais jamais au-delà de sa quantité (décision Q8).
- Chaque étape est **historisée** (qui, quand) et **notifiée** à qui doit le savoir. Le gestionnaire est prévenu à la demande, à l'acceptation ou au refus de la mission, au départ et à l'arrivée. Le producteur est prévenu à l'acceptation de la mission, à l'arrivée du transporteur, au départ et à la réception.

**Une seule définition du circuit** : `backend/app/Support/CircuitDepot.php`.
- Le serveur renvoie avec chaque dépôt ses **actions possibles** pour la personne connectée et **l'étape attendue** (« Transporteur à affecter », « Mission à accepter par le transporteur »…).
- Le site et l'application affichent seulement les boutons que le serveur autorise, avec les mêmes libellés. Ils ne recopient aucune règle.

**Autres ajouts :**
- **Position sur une carte dans le profil** (producteur, entrepôt, acheteur) : un clic ou un appui sur la carte, ou la position actuelle de l'appareil.
- La **mission du transporteur** affiche le lot, le téléphone et l'adresse du producteur. Elle propose un **itinéraire** (Google Maps) vers le producteur, puis vers l'entrepôt après le chargement. Ce lien n'existe pour l'instant que sur le site.
- **Traçabilité** : les étapes datées de l'acheminement apparaissent dans le parcours du lot, et une réception refusée y figure en alerte. Le nom du transporteur n'est montré qu'aux personnes concernées.
- Les menus du site disent « Déposer ma récolte » (producteur) et « Dépôts » (gestionnaire).

## 2. Vérifications réalisées

| Type | Où | Résultat |
|---|---|---|
| Tests automatiques du serveur, SQLite | Local | **169 réussis**, 10 ignorés (tests réservés à PostgreSQL) |
| Tests automatiques du serveur, **PostgreSQL 16**, opérations simultanées comprises | Local | **179 réussis** |
| Cycle des migrations : retour ciblé de 000020, 000019 puis 000018, réapplication, retour complet | Local (PostgreSQL) | Réussi |
| Reprise des réservations existantes (quantité reçue, mode par défaut, mission à accepter) | Test automatique | Réussi |
| Tests du site, compilation de production | Local | **93 réussis**, compilation sans avertissement |
| Tests de l'application, dont la mise en page des nouveaux écrans sur 5 formats et 2 tailles de texte | Local (Flutter 3.47.7) | **240 réussis** ; analyse sans erreur |
| **Parcours réel dans un navigateur, 5 comptes distincts**, serveur local sur PostgreSQL | Chromium | **22 contrôles sur 22 réussis** (détail ci-dessous) |
| Vérifications automatiques GitHub | GitHub Actions | Voir §5 |

**Le parcours navigateur contrôle :**
- la position du producteur posée sur la carte ;
- une demande sans mode de transport bloquée ;
- le gestionnaire notifié de la demande ;
- la charge des transporteurs affichée ;
- le refus de mission du transporteur A, avec son motif visible chez le gestionnaire ;
- la réaffectation à B, son acceptation et ses étapes dans l'ordre ;
- l'itinéraire vers le producteur, puis vers l'entrepôt ;
- l'impossibilité pour le producteur d'annuler une fois la marchandise partie ;
- les notifications du producteur ;
- un mauvais code de lot refusé à la réception ;
- un écart sans motif bloqué, puis une réception partielle (58 kg sur 60) ;
- un second dépôt du même lot refusé au-delà du reste, puis livré par le producteur lui-même ;
- une marchandise refusée à la réception ;
- la traçabilité complète.

**Non vérifié :**
- aucun test sur un téléphone Android réel ;
- aucun test en préproduction Render ;
- aucun test sur la production.

## 3. Base de données

- **Nouvelle migration** : `2025_01_01_000020_create_circuit_depot`.
  - Le statut des réservations passe en texte pour accueillir les nouvelles étapes.
  - Elle ajoute le mode de transport, la date d'acceptation de la mission, la quantité reçue, l'état de la marchandise, ainsi que la latitude et la longitude du producteur et de l'acheteur.
  - Reprise des données de test : mode « transporteur » par défaut. Une réservation confirmée avec transporteur attend l'acceptation de la mission. La quantité reçue est reprise du stock.
- **Retour arrière**, toujours de la plus récente à la plus ancienne : `migrate:rollback --path=database/migrations/2025_01_01_000020_create_circuit_depot.php --force`, puis 000019, puis 000018. Ce cycle est vérifié à chaque exécution de `backend-ci.yml`.

## 4. Limites connues, traitées dans les lots suivants

| Limite | Lot |
|---|---|
| Le GPS ne suit pas encore le trajet vers l'entrepôt : la carte en direct, l'heure d'arrivée estimée et le suivi en arrière-plan viennent avec le GPS fiable. | 4 |
| Les incidents de transport (panne, retard, accès impossible) n'existent pas encore comme tels. Aujourd'hui, le transporteur refuse la mission (avant de l'accepter), ou le gestionnaire retire la mission. | 3 |
| Le gestionnaire n'a pas encore ces actions sur l'application (accepter, affecter, contrôler la réception). | 5 |
| L'itinéraire (lien de navigation) n'existe que sur le site ; sur l'application, l'adresse et le téléphone sont affichés. | 4 |
| Le code du lot se saisit au clavier ; la lecture du QR code viendra avec les fiches. | 5 |

## 5. Vérifications GitHub sur la branche

| Vérification | Commit | Résultat |
|---|---|---|
| Serveur : SQLite, PostgreSQL 16 (concurrence comprise), cycle des migrations 000020 → 000018, garde de démarrage (exécution 39591335062) | `8f4fd57` | **Réussi** |
| Mobile : analyse, tests, garde de signature, APK de test vérifié (exécution 39591335046) | `8f4fd57` | **Réussi** |
| Site : tests et compilation de production (exécution 39613594184) | `cc65428` | **Réussi** |

Après `8f4fd57`, seuls les libellés de menu du site et la documentation ont changé : le code du serveur et de l'application est identique.
