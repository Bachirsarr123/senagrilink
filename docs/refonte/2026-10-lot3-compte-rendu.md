# Lot 3 — Circuit « entrepôt → acheteur » et incidents : compte rendu

*Branche `claude/lot3-circuit-livraison`, partie de `claude/lot2-circuit-depot`. Rien n'a été fusionné ni déployé, et aucun APK n'a été publié.*

## 1. Le circuit tel qu'il fonctionne maintenant

| # | Étape | Qui agit | Site | Application |
|---|---|---|---|---|
| 1 | **Commande**, d'un produit ou d'un **panier** de plusieurs produits (tout ou rien) | Acheteur | ✅ | ✅ |
| 2 | Commande confirmée (quantité réservée dans le stock) ou refusée (motif) | Gestionnaire | ✅ | — (lot 5) |
| 3 | **Préparation lancée**, puis **commande prête** | Gestionnaire | ✅ | — (lot 5) |
| 4 | **Livraison organisée** : transporteur obligatoire, **les moins occupés proposés d'abord** ; départ et adresse repris de l'entrepôt et du profil de l'acheteur | Gestionnaire | ✅ | — (lot 5) |
| 5 | **Mission acceptée, ou refusée avec un motif** (elle revient au gestionnaire) | Transporteur | ✅ | ✅ |
| 6 | **Marchandise chargée** : la quantité sort du stock, l'acheteur reçoit son **code de réception à 4 chiffres** | Transporteur | ✅ | ✅ |
| 7 | **Départ** vers l'acheteur (il est prévenu ; le GPS de l'application partage la position) | Transporteur | ✅ | ✅ |
| 8 | **Arrivée** chez l'acheteur (il est prévenu) | Transporteur | ✅ | ✅ |
| 9 | **Remise** : le transporteur saisit le code de l'acheteur, **ou** l'acheteur confirme lui-même la réception | Transporteur / acheteur | ✅ | ✅ |
| 9b | **Problème à la réception** (quantité manquante, produit abîmé, mauvais produit) : la commande passe **en litige** | Acheteur | ✅ | ✅ |

**Règles appliquées par le serveur :**
- Les étapes se franchissent **dans l'ordre**, et seulement par la personne indiquée.
- Le code de réception n'est montré **qu'à l'acheteur**. Le nombre d'essais de code est limité (5 par minute) ; un code erroné est historisé.
- **Annulation** :
  - l'acheteur annule lui-même tant que la commande est en attente ;
  - ensuite, il **demande l'annulation** et l'entrepôt décide ;
  - l'entrepôt peut annuler jusqu'au chargement : la réservation est libérée et le transporteur prévenu ;
  - **après le chargement**, l'annulation passe par un incident (la marchandise revient au stock).
- Le transporteur ne peut plus être changé **une fois la marchandise chargée**, sauf par décision sur un incident.
- Chaque étape est **historisée** (qui, quand) et **notifiée** :
  - **l'acheteur** à la préparation, à la commande prête, au chargement (avec son code), au départ, à l'arrivée et à la fin ;
  - **le gestionnaire** à l'acceptation ou au refus de la mission, et à la fin ;
  - **le producteur du lot** à la fin.

**Une seule définition du circuit** : `backend/app/Support/CircuitLivraison.php`. Comme pour les dépôts, le serveur renvoie avec chaque commande et chaque livraison ses **actions possibles** et **l'étape attendue**. Le site et l'application n'affichent que ces boutons.

## 2. Incidents (les deux circuits)

| Qui déclare | Sur quoi | Types |
|---|---|---|
| Transporteur | Livraison, de l'acceptation à la remise | Panne, retard, accès impossible, acheteur absent, marchandise abîmée, autre |
| Transporteur, ou producteur qui livre lui-même | Acheminement vers l'entrepôt | Panne, retard, accès impossible, marchandise abîmée, autre |
| Acheteur | Commande | Demande d'annulation ; à la réception : quantité manquante, produit abîmé, livraison incorrecte |

- **Panne, accès impossible, acheteur absent et marchandise abîmée arrêtent le transport** jusqu'à la décision : les boutons d'étape disparaissent et le serveur refuse les étapes. Un retard n'arrête rien.
- **Le gestionnaire de l'entrepôt concerné décide**, sur la nouvelle page **Incidents** du site. Le serveur ne propose que les décisions possibles à ce moment-là :

| Sujet | Décisions |
|---|---|
| Livraison | La livraison reprend ; un autre transporteur prend le relais ; **retour à l'entrepôt** (la marchandise rentre en stock, de nouveau réservée, et la commande redevient prête pour une nouvelle livraison) ; annuler la commande (la marchandise rentre en stock) |
| Acheminement | Reprendre ; changer de transporteur ; annuler le dépôt |
| Demande d'annulation | Accepter (seulement si la commande n'est pas encore chargée) ; refuser (motif) |
| Litige à la réception | Clore le litige (motif, par exemple un avoir convenu) ; reprendre la marchandise et annuler la commande |

- Après chargement, un **changement de transporteur** vaut **transbordement** : le nouveau transporteur reprend la marchandise et repart directement, sans nouvelle acceptation.
- Le déclarant est prévenu de la décision, l'acheteur est prévenu des incidents qui touchent sa livraison, et les transporteurs concernés sont prévenus.
- L'ancien bouton « Signaler un problème » crée désormais un incident « autre ».

## 3. Vérifications réalisées

| Type | Où | Résultat |
|---|---|---|
| Tests automatiques du serveur, SQLite | Local | **188 réussis**, 10 ignorés (tests réservés à PostgreSQL) |
| Tests automatiques du serveur, **PostgreSQL 16**, opérations simultanées comprises | Local | **198 réussis** |
| Cycle des migrations : retour ciblé de 000021, 000020, 000019 puis 000018, réapplication, retour complet | Local (PostgreSQL), même script que la CI | Réussi |
| Reprise des commandes et livraisons existantes, puis retour arrière | Test automatique | Réussi |
| Tests du site, compilation de production | Local | **96 réussis**, compilation sans avertissement |
| Tests de l'application, dont la mise en page des nouveaux états sur 5 formats et 2 tailles de texte | Local (Flutter 3.47.7) | **254 réussis** ; analyse sans erreur |
| **Parcours réel dans un navigateur, 4 comptes distincts**, serveur local sur PostgreSQL | Chromium | **18 contrôles sur 18 réussis** (détail ci-dessous) |
| Vérifications automatiques GitHub | GitHub Actions | Voir §6 |

**Le parcours navigateur contrôle :**
- un panier de deux produits, avec son total, commandé en une fois ;
- une commande qui ne peut pas être livrée avant d'être préparée, puis un transporteur obligatoire ;
- une demande d'annulation acceptée par l'entrepôt ;
- le refus de mission du transporteur A, vu par le gestionnaire, puis la réaffectation à B ;
- les itinéraires vers l'entrepôt, puis vers l'acheteur, et le contact de l'acheteur ;
- la sortie du stock au chargement (80 → 60 kg) et le code vu par l'acheteur ;
- l'incident « acheteur absent », qui bloque la remise, puis le retour à l'entrepôt (stock rétabli et réservé) et une nouvelle livraison ;
- un mauvais code refusé, puis la remise avec le bon code ;
- la commande reçue et notifiée chez l'acheteur, et les deux incidents traités avec leur décision.

**Non vérifié :**
- aucun test sur un téléphone Android réel ;
- aucun test en préproduction Render ;
- aucun test sur la production.

## 4. Base de données

- **Nouvelle migration** : `2025_01_01_000021_create_circuit_livraison`.
  - Le statut des commandes et des livraisons passe en texte.
  - Elle ajoute la référence de panier, le code de réception, ainsi que les heures d'acceptation, de départ et d'arrivée.
  - Elle crée la table `incidents`.
- **Reprise des données de test :**
  - commande « livrée » → « reçue » ;
  - commande confirmée avec une livraison en attente → « prête » (mission à accepter) ;
  - commande confirmée avec une livraison partie → « en livraison » ;
  - livraison « problème » → « en cours », avec un incident ouvert.
- **Retour arrière**, toujours de la plus récente à la plus ancienne : `migrate:rollback --path=database/migrations/2025_01_01_000021_create_circuit_livraison.php --force`, puis 000020, 000019 et 000018. Ce cycle est vérifié à chaque exécution de `backend-ci.yml`.

## 5. Limites connues

| Limite | Suite |
|---|---|
| Une commande = une livraison ; le panier regroupe les commandes mais chaque produit est livré séparément | À regrouper si le besoin se confirme |
| Pas de clôture automatique d'une livraison non confirmée (pas de tâche planifiée) : le transporteur saisit le code, ou l'acheteur confirme | Lot 4 ou 6 |
| Le gestionnaire n'a pas encore ces actions sur l'application (préparer, organiser, décider d'un incident) | Lot 5 |
| L'itinéraire (lien de navigation) n'existe que sur le site ; l'application affiche adresses et téléphones | Lot 4 |
| Le panier n'est pas conservé si l'acheteur quitte la page ou l'écran | Amélioration possible |
| Aucun remboursement n'est géré (paiement hors plateforme) | Hors périmètre actuel |

## 6. Vérifications GitHub sur la branche

À compléter après l'exécution des vérifications sur la branche poussée.
