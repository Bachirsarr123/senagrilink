# Lot 1 — Fondations du stock et des prix : compte rendu

*Branche `claude/lot1-fondations`, partie de `claude/livraison-preproduction` (lot 0 et sécurisation de la livraison inclus). Rien n'a été fusionné ni déployé, et aucun APK n'a été publié.*

## 1. Ce qui change pour chaque rôle

| Rôle | Avant | Maintenant |
|---|---|---|
| **Producteur** | Aucun prix. Une récolte restait modifiable même après avoir été vendue. Le dépôt en entrepôt pouvait se faire sans lot, et le produit et la quantité étaient à ressaisir. | Il **fixe son prix au kilo** à l'enregistrement de la récolte, et peut le changer plus tard. Une fois le lot en entrepôt, **seul le prix reste modifiable**. La demande de dépôt **exige une récolte** et en reprend automatiquement le produit et la quantité. |
| **Acheteur** | Il proposait lui-même un prix. La quantité affichée ignorait les commandes déjà confirmées. | Il voit **le prix du producteur** et **la quantité réellement disponible**, et le total se calcule seul. Il ne peut plus saisir de prix. Le prix est **figé sur sa commande** : si le producteur le change ensuite, sa commande n'est pas touchée. |
| **Gestionnaire** | Une seule quantité. Entrées et sorties libres, avec saisie d'identifiants numériques. Le stock était retiré dès la confirmation d'une commande. | Trois quantités : **en entrepôt, réservé, disponible**. Une confirmation **réserve** la marchandise ; elle **sort du stock au départ** de la livraison, une seule fois. Les entrées et sorties libres disparaissent : un retrait ou un ajout passe par un **ajustement motivé** (perte, avarie, retrait par le producteur, correction d'inventaire). Il peut modifier le **seuil d'alerte**, et il dispose d'un **journal** de chaque stock. |
| **Transporteur** | Aucun changement visible. | Quand il marque une livraison « livrée », la commande passe enfin à **« Livrée »**. Avant, elle restait « Confirmée » pour toujours. |
| **Administrateur** | Ses blocages et déblocages de comptes n'étaient pas tracés. | Ils sont **tracés** (qui, quand, motif). |

**Pour tous :**
- Chaque étape importante est enregistrée dans l'historique, avec la date et l'auteur : création, confirmation, arrivée, réception, départ, livraison, problème.
- Le site affiche les nombres au **format français** (« 3 000 FCFA », « 12,5 kg »). Avant, il affichait « 3,000 FCFA », que l'on lit « 3 FCFA » en français.

## 2. Règles appliquées par le serveur

1. **Disponible = en entrepôt − réservé.** C'est le seul chiffre proposé aux acheteurs, et le seul utilisé pour les alertes.
2. **Commande passée** : la quantité ne doit pas dépasser le disponible. Le prix au kilo du lot est figé. Rien n'est réservé à ce stade.
3. **Commande confirmée** : la quantité est réservée, sous verrou. Au-delà du disponible, la confirmation est refusée avec un message.
4. **Départ de la livraison** : la quantité réservée sort du stock, **une seule fois**. Un double clic ou un nouvel essai ne déduit rien de plus.
5. **Annulation d'une confirmation** : la réservation est rendue au disponible.
6. **Ajustement** : un retrait ne peut jamais entamer une quantité réservée pour une commande. Seule une correction d'inventaire peut augmenter le stock. L'explication est obligatoire.
7. **Entrée en stock** : uniquement par la réception d'un dépôt validée par le gestionnaire. La quantité réelle de la récolte devient la somme réellement reçue.
8. **Journal** : chaque variation du stock laisse une ligne (type, quantités avant et après, auteur, opération d'origine, motif). La somme du journal est toujours égale au stock.

## 3. Vérifications réalisées

| Type | Où | Résultat |
|---|---|---|
| Tests automatiques du serveur, SQLite | Local | **157 réussis**, 10 ignorés (tests réservés à PostgreSQL) |
| Tests automatiques du serveur, **PostgreSQL 16**, opérations simultanées comprises | Local | **167 réussis** |
| Cycle des migrations : application, retour ciblé de `000019`, réapplication, retour complet | Local (PostgreSQL) | Réussi |
| **Reprise des données existantes** : commande confirmée non partie, partie, livrée | Test automatique (SQLite et PostgreSQL) | Aucune quantité perdue ni déduite deux fois |
| Tests du site | Local | **85 réussis** ; compilation de production réussie |
| Tests de l'application mobile | Local (Flutter 3.47.7) | **215 réussis** ; analyse sans erreur |
| **Parcours réel dans un navigateur, 6 comptes distincts** (producteur, gestionnaire, 2 acheteurs, transporteur, administrateur), serveur local sur PostgreSQL | Chromium | **26 contrôles sur 26 réussis** (détail ci-dessous) |
| Affichage sur une largeur de téléphone (360 px) : stocks, catalogue, productions | Chromium | Aucun débordement horizontal |
| Vérifications automatiques GitHub (serveur, site, mobile) | GitHub Actions | Voir §6 |

**Le parcours navigateur contrôle :**
- le refus d'une récolte sans prix ;
- le préremplissage de la demande de dépôt ;
- la réception (38 kg reçus sur 40 prévus) ;
- le verrouillage du lot ;
- le prix et le disponible au catalogue ;
- deux commandes concurrentes, la seconde refusée faute de disponible ;
- l'autre acheteur, qui voit le disponible réduit ;
- un ajustement refusé, puis un ajustement accepté ;
- le journal ;
- la sortie au départ, sans double déduction à la livraison ;
- la commande « Livrée » chez l'acheteur ;
- un changement de prix qui ne modifie pas la commande déjà passée.

**Non vérifié :**
- aucun test sur un téléphone Android réel ;
- aucun test en préproduction Render ;
- aucun test sur la production.

## 4. Base de données

- **Nouvelle migration** : `2025_01_01_000019_create_fondations_stock`.
  - Elle ajoute des colonnes : prix au kilo sur la récolte et sur la commande, quantité réservée, état du stock de chaque commande.
  - Elle crée la table `mouvements_stock`.
  - Elle reprend les données existantes : un mouvement d'ouverture pour chaque stock ; une commande confirmée mais pas encore partie (déduite selon l'ancienne règle) devient une réservation.
- **Retour arrière** : `php artisan migrate:rollback --path=database/migrations/2025_01_01_000019_create_fondations_stock.php --force`. Il est vérifié à chaque exécution de `backend-ci.yml`. Ne jamais lancer `migrate:rollback` sans `--path`.
- **Données de test actuelles** (décision Q1) : les récoltes existantes n'ont pas de prix. Elles **n'apparaissent pas au catalogue** tant que le producteur ne l'a pas fixé (bouton « Modifier le prix »).

## 5. Limites connues, traitées dans les lots suivants

| Limite | Lot |
|---|---|
| La commande passe à « Livrée » quand le transporteur la déclare livrée. La confirmation par l'acheteur, avec code à 4 chiffres et anomalie, viendra ensuite. | 3 |
| L'état « Problème » d'une livraison reste sans issue. | 3 |
| Le producteur qui livre lui-même reste bloqué, et l'entrée manuelle n'existe plus. Une réservation sans transporteur ne peut pas encore être réceptionnée. | 2 |
| Le gestionnaire n'a toujours qu'un affichage en lecture sur l'application. Les ajustements, le seuil et le journal ne sont disponibles que sur le site. | 5 |
| Le journal indique « Réservé pour une commande » sans le numéro de la commande. | 5 (fiches) |
| Panier de plusieurs produits ; étapes « préparation » et « chargement ». | 3 |

## 6. Vérifications GitHub sur la branche

*(complété après l'exécution, avec le SHA vérifié)*
