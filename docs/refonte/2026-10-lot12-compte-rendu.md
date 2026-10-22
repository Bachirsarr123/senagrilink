# Lot 12 — Paiements (simulés comme des vrais) : compte rendu

*Branche `claude/lot12-paiements`, partie de `claude/lot11-pilote-automatique`. Rien n'a été fusionné, et aucun APK n'a été publié. **Aucun argent réel ne circule** : le fournisseur de paiement est un simulateur, en attendant votre compte Wave Business.*

## 1. Qui paie quoi, qui reçoit quoi

| Montant (exemple : 10 kg de riz à 150 FCFA, livré à 5 km) | Payé par | Reçu par |
|---|---|---|
| Marchandise : 10 × 150 = **1 500 FCFA** | Acheteur | Producteur, moins les frais ci-dessous |
| Livraison : 500 + 100 × 7 km par la route = **1 200 FCFA** | Acheteur | Transporteur |
| Frais d'entrepôt : 5 FCFA × 10 kg = **50 FCFA** | Retenus sur la vente | Entrepôt |
| Commission SenAgriLink : 2 % = **30 FCFA** | Retenue sur la vente | SenAgriLink |
| **L'acheteur paie 2 700 FCFA** | | **Le producteur reçoit 1 420 FCFA** |

**Où se règlent ces tarifs :**
- les frais d'entrepôt, par chaque gestionnaire, sur « Ma journée » ;
- la commission et la livraison, côté serveur : `PAIEMENT_COMMISSION_POURCENT`, `LIVRAISON_FORFAIT_FCFA` et `LIVRAISON_PAR_KM_FCFA`.

Les montants sont **figés au moment de la commande**.

## 2. Le parcours de l'argent

1. **Avant de commander**, l'acheteur voit « À payer : 2 700 FCFA (dont livraison 1 200 FCFA, 5 km) ».
2. **Il paie** depuis « Mes commandes » : **Wave**, **Orange Money**, ou **en espèces à la livraison**. Il peut payer tout un panier d'un coup.
3. **Wave ou Orange Money** : une page de paiement s'ouvre, simulée pour l'instant, marquée « Simulation : aucun argent réel n'est débité ». L'acheteur confirme.
4. L'argent est **mis de côté**. Le producteur est prévenu : « Commande payée… 1 420 FCFA vous seront versés dès la réception de la marchandise. »
5. **À la réception** (code donné au transporteur, ou réception confirmée par l'acheteur), l'argent est **versé automatiquement** à chacun, sur son numéro Wave ou Orange Money. Chacun reçoit une notification : « 1 420 FCFA versés sur votre Wave 771112233 pour la commande CMD-… ».
6. **En espèces** : le transporteur encaisse, garde la livraison et doit remettre le reste. Le producteur voit « À recevoir en main propre ».
7. **Commande annulée** (avant le chargement) : l'acheteur est **remboursé**, et il en est prévenu.
8. **Anomalie déclarée** à la réception : l'argent reste **bloqué (litige)**. L'**administrateur** tranche dans « Paiements » : verser au producteur, ou rembourser l'acheteur.
9. **Une commande payée ne se modifie plus.** Pour changer la quantité, on l'annule (remboursée), puis on recommande.

## 3. Ce que chacun voit

| Qui | Où | Quoi |
|---|---|---|
| **Producteur** | « Mon argent » (menu du site, onglet de l'application) | Mis de côté, déjà versé, à recevoir en main propre, avec le détail de chaque vente : « 1 500 − entrepôt 50 − commission 30 = 1 420 » ; son numéro Wave ou Orange Money de réception |
| **Gestionnaire** | « Mon argent » (menu, ou icône de « Ma journée ») | Ses frais d'entrepôt ; il les règle sur « Ma journée » |
| **Transporteur** | « Mon argent » | Ce qu'il gagne par livraison |
| **Acheteur** | « Mes commandes » | Le total, l'état du paiement, les boutons pour payer |
| **Administrateur** | « Paiements » | Les paiements récents, et les litiges à trancher |
| **Fiche de commande** | Tous les acteurs | Le détail des montants ; le transporteur ne voit que sa part |

## 4. Passer au vrai Wave Business, le moment venu

Le serveur passe par un **fournisseur de paiement** interchangeable (`PAIEMENT_FOURNISSEUR`, aujourd'hui `simulation`).

Quand votre compte Wave Business sera ouvert, il faudra :
1. me donner les **informations de l'API Wave** : documentation et type de clés. **Vous collerez vous-même les clés secrètes dans Render.**
2. me laisser écrire le fournisseur `wave` :
   - création du paiement ;
   - redirection vers Wave ;
   - réception de la confirmation de Wave, avec vérification de sa signature ;
   - versements par l'API de paiement de Wave.

Les écrans, les montants et les règles ne changent pas. Seule la page simulée est remplacée par la vraie page Wave.

**Rappel :** l'argent reste chez Wave, qui est agréé, jusqu'aux versements. SenAgriLink ne détient pas les fonds.

## 5. Vérifications réalisées

| Type | Où | Résultat |
|---|---|---|
| Tests du serveur, SQLite | Local | **230 réussis**, 10 ignorés (réservés à PostgreSQL). **6 nouveaux tests** couvrent : montants et devis, paiement mis de côté puis versé à chacun, remboursement après annulation, espèces à la livraison, litige tranché par l'administrateur, paiement échoué et contrôles d'accès. |
| Tests du serveur, PostgreSQL 16 | Local | **240 réussis** |
| Cycle des migrations, de 000026 à 000018 | Local, même script que la CI | Réussi |
| Tests du site, compilation | Local | **121 réussis**, compilation sans avertissement |
| Tests de l'application, dont « Mon argent » sur 5 formats et 2 tailles de texte | Local | **364 réussis** ; analyse sans erreur |
| Parcours réel dans un navigateur | Chromium | **4 contrôles sur 4 réussis** (voir ci-dessous) |

Le parcours dans le navigateur vérifie :
- le total avec la livraison avant de commander ;
- le paiement Wave simulé ;
- la mention « Payé — mis de côté » ;
- la vente mise de côté dans « Mon argent » du producteur.

**Non vérifié :**
- **aucun paiement réel** (simulation) ;
- aucun essai sur un téléphone ;
- le parcours complet jusqu'au versement n'a été vérifié que par les tests du serveur, pas dans un navigateur.

## 6. Base de données

- **Nouvelle migration** `2025_01_01_000026_create_paiements` :
  - tables `paiements` (paiements de l'acheteur) et `versements` (part de chaque acteur) ;
  - montants et état du paiement sur les commandes ;
  - frais d'entrepôt par kg ;
  - moyen et numéro de réception de l'argent pour chaque utilisateur.
- **Reprise** : les commandes existantes ont des montants à zéro et « À payer ». Elles n'affichent pas de bouton « Payer », car leur total est nul.
- **Retour arrière** : `migrate:rollback --path=database/migrations/2025_01_01_000026_create_paiements.php --force`. Ce retour est vérifié à chaque exécution de `backend-ci.yml`.

## 7. Vérifications GitHub

*À compléter.*
