<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\HistoriqueOperation;
use App\Models\Incident;
use App\Models\Livraison;
use App\Models\PositionGps;
use App\Models\Reservation;
use App\Models\Stock;
use App\Models\Utilisateur;
use App\Support\AFaire;
use App\Support\CircuitLivraison;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Écrans par rôle (lot 5) : page « À faire », fiche d'une commande, tableau
 * des exceptions de l'administrateur.
 */
class TableauController extends Controller
{
    /** Libellés des étapes de l'historique (commande, livraison, dépôt). */
    public const LIBELLES_ETAPES = [
        'creation' => 'Création', 'confirmation' => 'Confirmée', 'preparation' => 'Préparation lancée', 'prete' => 'Prête',
        'chargement' => 'Chargée', 'reception' => 'Reçue par l\'acheteur', 'litige' => 'Litige ouvert', 'cloture_litige' => 'Litige clos',
        'refus' => 'Refusée', 'annulation' => 'Annulée', 'annulation_confirmation' => 'Annulée par l\'entrepôt',
        'annulation_acceptee' => 'Annulation acceptée', 'annulation_incident' => 'Annulée suite à un incident', 'retour_entrepot' => 'Revenue à l\'entrepôt',
        'modification' => 'Quantité modifiée', 'acceptation_mission' => 'Mission acceptée', 'refus_mission' => 'Mission refusée',
        'assignation_transporteur' => 'Transporteur affecté', 'reassignation_transporteur' => 'Transporteur changé',
        'chargee' => 'Marchandise chargée', 'en_cours' => 'Départ vers l\'acheteur', 'arrivee' => 'Arrivée chez l\'acheteur',
        'livraison' => 'Remise à l\'acheteur', 'code_errone' => 'Code de réception erroné',
    ];

    /** Seuils du tableau des exceptions. */
    public const ATTENTE_MAX_H = 48;
    public const INCIDENT_MAX_H = 2;
    public const SANS_POSITION_MAX_MIN = 15;

    public function aFaire(Request $request): JsonResponse
    {
        $elements = AFaire::pour($request->user());

        return response()->json([
            'elements' => $elements,
            'total'    => count($elements),
            'en_retard' => count(array_filter($elements, fn ($e) => $e['en_retard'])),
        ]);
    }

    /**
     * Tableau des exceptions (administrateur) : ce qui est bloqué, en retard
     * ou anormal sur toute la plateforme, du plus grave au moins grave.
     */
    public function exceptions(): JsonResponse
    {
        $e = collect();
        $ajouter = fn (string $gravite, string $categorie, string $numero, string $message, $depuis, array $lien) => $e->push([
            'gravite' => $gravite, 'categorie' => $categorie, 'numero' => $numero, 'message' => $message,
            'depuis' => $depuis ? \Carbon\Carbon::parse($depuis)->toIso8601String() : null, 'lien' => $lien,
        ]);

        foreach (Incident::ouverts()->orderBy('created_at')->get() as $i) {
            $vieux = $i->created_at && $i->created_at->diffInHours(now(), true) >= self::INCIDENT_MAX_H;
            $ajouter($i->estBloquant() || $vieux ? 'haute' : 'moyenne', 'incident', $i->sujet_type.' #'.$i->sujet_id,
                sprintf('Incident « %s » non décidé%s : %s', Incident::libelle($i->type), $vieux ? ' depuis plus de '.self::INCIDENT_MAX_H.' h' : '', $i->description),
                $i->created_at, ['sujet_type' => $i->sujet_type, 'sujet_id' => $i->sujet_id]);
        }

        foreach (Livraison::where('statut', 'en_cours')->get() as $l) {
            $derniere = PositionGps::where('sujet_type', 'livraison')->where('sujet_id', $l->id)->max('timestamp');
            $depuis   = $derniere ?? $l->parti_le;
            if ($depuis && \Carbon\Carbon::parse($depuis)->diffInMinutes(now(), true) >= self::SANS_POSITION_MAX_MIN) {
                $ajouter('haute', 'gps', $l->numero_livraison, $derniere ? 'Aucune position reçue depuis plus de '.self::SANS_POSITION_MAX_MIN.' min pendant la livraison' : 'Livraison partie sans aucune position reçue', $depuis, ['sujet_type' => 'livraison', 'sujet_id' => $l->id]);
            }
        }

        foreach (Reservation::where('statut', 'en_route')->get() as $r) {
            $derniere = PositionGps::where('sujet_type', 'reservation')->where('sujet_id', $r->id)->max('timestamp');
            $depuis   = $derniere ?? $r->parti_le;
            if ($depuis && \Carbon\Carbon::parse($depuis)->diffInMinutes(now(), true) >= self::SANS_POSITION_MAX_MIN) {
                $ajouter('moyenne', 'gps', $r->numero_reservation, 'Acheminement en route sans position récente', $depuis, ['sujet_type' => 'reservation', 'sujet_id' => $r->id]);
            }
        }

        $limite = now()->subHours(self::ATTENTE_MAX_H);
        foreach (Commande::where('statut', 'en_attente')->where('date_commande', '<', $limite)->get() as $c) {
            $ajouter('moyenne', 'commande', $c->numero_commande, 'Commande en attente de confirmation depuis plus de '.self::ATTENTE_MAX_H.' h', $c->date_commande, ['sujet_type' => 'commande', 'sujet_id' => $c->id]);
        }
        foreach (Commande::where('statut', 'litige')->get() as $c) {
            $ajouter('haute', 'commande', $c->numero_commande, 'Commande en litige', null, ['sujet_type' => 'commande', 'sujet_id' => $c->id]);
        }
        foreach (Reservation::where('statut', 'en_attente')->where('date_reservation', '<', $limite)->get() as $r) {
            $ajouter('moyenne', 'depot', $r->numero_reservation, 'Demande de dépôt sans réponse depuis plus de '.self::ATTENTE_MAX_H.' h', $r->date_reservation, ['sujet_type' => 'reservation', 'sujet_id' => $r->id]);
        }
        foreach (Livraison::where('statut', 'en_attente')->get() as $l) {
            $depuis = HistoriqueOperation::where('sujet_type', 'livraison')->where('sujet_id', $l->id)->max('created_at');
            if (! $l->transporteur_id) {
                $ajouter('moyenne', 'livraison', $l->numero_livraison, 'Livraison sans transporteur (mission refusée)', $depuis, ['sujet_type' => 'livraison', 'sujet_id' => $l->id]);
            } elseif ($depuis && \Carbon\Carbon::parse($depuis)->lessThan(now()->subDay())) {
                $ajouter('moyenne', 'livraison', $l->numero_livraison, 'Mission non acceptée depuis plus de 24 h', $depuis, ['sujet_type' => 'livraison', 'sujet_id' => $l->id]);
            }
        }
        foreach (Livraison::where('statut', 'arrivee')->where('arrive_le', '<', now()->subDay())->get() as $l) {
            $ajouter('moyenne', 'livraison', $l->numero_livraison, 'Arrivée chez l\'acheteur mais remise non confirmée depuis plus de 24 h', $l->arrive_le, ['sujet_type' => 'livraison', 'sujet_id' => $l->id]);
        }
        foreach (Stock::whereNotNull('seuil_alerte')->whereRaw('(quantite - quantite_reservee) <= seuil_alerte')->where('statut', '!=', 'epuise')->with('entrepot:id,nom_entrepot')->get() as $s) {
            $ajouter('basse', 'stock', $s->produit, sprintf('Stock sous le seuil à %s : %s kg disponibles', $s->entrepot?->nom_entrepot, (float) $s->quantite_disponible), null, ['sujet_type' => 'stock', 'sujet_id' => $s->id]);
        }

        foreach (Utilisateur::where('statut', Utilisateur::EN_ATTENTE)->orderBy('id')->get() as $u) {
            $ajouter('moyenne', 'compte', trim($u->prenom.' '.$u->nom), sprintf('Compte %s à valider', $u->role === 'transporteur' ? 'transporteur' : 'gestionnaire d\'entrepôt'),
                HistoriqueOperation::where('sujet_type', 'utilisateur')->where('sujet_id', $u->id)->where('action', 'inscription')->value('created_at'),
                ['sujet_type' => 'utilisateur', 'sujet_id' => $u->id]);
        }

        $ordre = ['haute' => 0, 'moyenne' => 1, 'basse' => 2];
        $liste = $e->sortBy([fn ($a, $b) => $ordre[$a['gravite']] <=> $ordre[$b['gravite']], fn ($a, $b) => ($a['depuis'] ?? 'z') <=> ($b['depuis'] ?? 'z')])->values();

        return response()->json([
            'exceptions' => $liste,
            'par_gravite' => ['haute' => $liste->where('gravite', 'haute')->count(), 'moyenne' => $liste->where('gravite', 'moyenne')->count(), 'basse' => $liste->where('gravite', 'basse')->count()],
        ]);
    }

    /**
     * Fiche d'une commande : la même page pour l'acheteur, l'entrepôt, le
     * transporteur, le producteur du lot et l'administrateur, chacun avec ses
     * boutons et sans les informations qui ne le regardent pas.
     */
    public function ficheCommande(Request $request, int $id): JsonResponse
    {
        $c = Commande::with([
            'stock.entrepot.utilisateur:id,telephone', 'stock.production:id,code_tracabilite,producteur_id',
            'acheteur.utilisateur:id,nom,prenom,telephone,adresse', 'livraison.transporteur.utilisateur:id,nom,prenom,telephone',
            'incidents',
        ])->find($id);

        if (! $c) {
            return response()->json(['message' => 'Commande introuvable.'], 404);
        }

        $u    = $request->user();
        $role = $this->roleSur($c, $u);

        if (! $role) {
            return response()->json(['message' => 'Accès non autorisé à cette commande.'], 403);
        }

        $l           = $c->livraison;
        $livraisons  = Livraison::where('commande_id', $c->id)->pluck('id');
        $historique  = HistoriqueOperation::with('utilisateur:id,nom,prenom,role')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('sujet_type', 'commande')->where('sujet_id', $c->id))
                ->orWhere(fn ($q) => $q->where('sujet_type', 'livraison')->whereIn('sujet_id', $livraisons)))
            ->orderBy('created_at')->orderBy('id')->get();
        $incidents   = Incident::with('declarant:id,nom,prenom,role')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('sujet_type', 'commande')->where('sujet_id', $c->id))
                ->orWhere(fn ($q) => $q->where('sujet_type', 'livraison')->whereIn('sujet_id', $livraisons)))
            ->orderBy('id')->get();
        $voitPrix    = $role !== 'transporteur';

        return response()->json([
            'role'            => $role,
            'commande'        => [
                'id' => $c->id, 'numero_commande' => $c->numero_commande, 'produit' => $c->produit, 'quantite' => (float) $c->quantite,
                'statut' => $c->statut, 'date_commande' => $c->date_commande, 'panier' => $c->panier,
                'prix_unitaire' => $voitPrix ? $c->prix_unitaire : null, 'prix' => $voitPrix ? $c->prix : null,
            ],
            'actions'         => CircuitLivraison::actionsCommande($c, $u, $c->incidents->contains(fn ($i) => $i->statut === 'ouvert' && $i->type === 'demande_annulation')),
            'actions_livraison' => $l ? CircuitLivraison::actionsLivraison($l, $u, $incidents->contains(fn ($i) => $i->sujet_type === 'livraison' && $i->estBloquant())) : [],
            'prochaine_etape' => CircuitLivraison::prochaineEtape($c),
            'code_reception'  => $role === 'acheteur' && $c->statut === 'en_livraison' ? $c->code_reception : null,
            'lot'             => ['code_tracabilite' => $c->stock?->production?->code_tracabilite, 'entrepot' => $c->stock?->entrepot?->nom_entrepot,
                'telephone_entrepot' => in_array($role, ['acheteur', 'transporteur', 'administrateur'], true) ? $c->stock?->entrepot?->utilisateur?->telephone : null],
            'acheteur'        => in_array($role, ['gestionnaire', 'transporteur', 'administrateur', 'acheteur'], true) ? [
                'nom' => trim(($c->acheteur?->utilisateur?->prenom ?? '').' '.($c->acheteur?->utilisateur?->nom ?? '')),
                'telephone' => $c->acheteur?->utilisateur?->telephone, 'adresse' => $c->acheteur?->utilisateur?->adresse,
            ] : null,
            'livraison'       => $l ? [
                'id' => $l->id, 'numero_livraison' => $l->numero_livraison, 'statut' => $l->statut, 'destination' => $l->destination,
                'parti_le' => $l->parti_le, 'arrive_le' => $l->arrive_le, 'date_livraison' => $l->date_livraison,
                'arrivee_estimee_le' => $l->statut === 'en_cours' ? $l->arrivee_estimee_le : null,
                'suivi_disponible' => $l->statut === 'en_cours',
                'transporteur' => $l->transporteur?->utilisateur ? [
                    'nom' => trim($l->transporteur->utilisateur->prenom.' '.$l->transporteur->utilisateur->nom),
                    'telephone' => $l->transporteur->utilisateur->telephone, 'type_vehicule' => $l->transporteur->type_vehicule,
                ] : null,
            ] : null,
            'etapes'          => $historique->map(fn (HistoriqueOperation $h) => [
                'date'    => $h->created_at?->toIso8601String(),
                'sujet'   => $h->sujet_type,
                'action'  => $h->action,
                'libelle' => self::LIBELLES_ETAPES[$h->action] ?? (str_starts_with($h->action, 'incident_') ? 'Incident déclaré' : (str_starts_with($h->action, 'decision_') ? 'Décision : '.Incident::libelle(substr($h->action, 9)) : $h->action)),
                'auteur'  => $h->utilisateur ? trim($h->utilisateur->prenom.' '.$h->utilisateur->nom) : 'Système',
                'role'    => $h->utilisateur?->role,
                'motif'   => $h->motif,
            ])->values(),
            'incidents'       => $incidents->map(fn (Incident $i) => [
                'id' => $i->id, 'sujet' => $i->sujet_type, 'type' => $i->type, 'libelle' => Incident::libelle($i->type), 'description' => $i->description,
                'statut' => $i->statut, 'decision' => $i->decision ? Incident::libelle($i->decision) : null, 'motif_decision' => $i->motif_decision,
                'declare_le' => $i->created_at?->toIso8601String(), 'declarant' => $i->declarant ? trim($i->declarant->prenom.' '.$i->declarant->nom) : null,
            ])->values(),
        ]);
    }

    /** Rôle de l'utilisateur sur cette commande, ou null s'il n'y est pour rien. */
    private function roleSur(Commande $c, Utilisateur $u): ?string
    {
        return match (true) {
            $u->role === 'administrateur'                                                                         => 'administrateur',
            $u->acheteurGros !== null && (int) $u->acheteurGros->id === (int) $c->acheteur_id                     => 'acheteur',
            $u->entrepot !== null && $c->estGereePar($u)                                                           => 'gestionnaire',
            $u->transporteur !== null && Livraison::where('commande_id', $c->id)->where('transporteur_id', $u->transporteur->id)->exists() => 'transporteur',
            $u->producteur !== null && (int) $c->stock?->production?->producteur_id === (int) $u->producteur->id   => 'producteur',
            default                                                                                               => null,
        };
    }
}
