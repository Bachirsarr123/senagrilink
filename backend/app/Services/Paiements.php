<?php

namespace App\Services;

use App\Models\AcheteurGros;
use App\Models\Commande;
use App\Models\HistoriqueOperation;
use App\Models\Incident;
use App\Models\Paiement;
use App\Models\Utilisateur;
use App\Models\Versement;
use App\Notifications\OperationModifieeNotification;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Paiements (lot 12). L'acheteur paie à la commande (Wave, Orange Money) ou à
 * la livraison (espèces). Payé en ligne, l'argent est mis de côté jusqu'à la
 * réception (code donné au transporteur), puis versé à chacun ; un litige le
 * garde bloqué jusqu'à la décision de l'administrateur ; une annulation le
 * rembourse. Le fournisseur « simulation » se comporte comme Wave sans argent
 * réel ; le vrai fournisseur prendra sa place (services.paiement.fournisseur).
 */
class Paiements
{
    public const MOYENS_EN_LIGNE = ['wave', 'orange_money'];

    /** Paiement de commandes de l'acheteur. Renvoie le paiement créé. */
    public function initier(AcheteurGros $acheteur, array $commandeIds, string $moyen, ?string $telephone): Paiement
    {
        return DB::transaction(function () use ($acheteur, $commandeIds, $moyen, $telephone) {
            $commandes = Commande::whereIn('id', $commandeIds)->lockForUpdate()->get();
            if ($commandes->count() !== count(array_unique($commandeIds)) || $commandes->contains(fn ($c) => (int) $c->acheteur_id !== (int) $acheteur->id)) {
                $this->refus('Commande introuvable parmi les vôtres.', 404);
            }
            if ($c = $commandes->first(fn ($c) => $c->statut_paiement !== 'a_payer' || in_array($c->statut, ['annulee', 'refusee'], true))) {
                $this->refus("La commande {$c->numero_commande} n'est pas à payer.");
            }

            $especes = $moyen === 'especes';
            $paiement = Paiement::create([
                'reference'   => 'PAY-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
                'acheteur_id' => $acheteur->id,
                'montant'     => $commandes->sum('total_a_payer'),
                'moyen'       => $moyen,
                'fournisseur' => $especes ? 'livraison' : config('services.paiement.fournisseur', 'simulation'),
                'statut'      => 'initie',
                'telephone'   => $telephone ?: $acheteur->utilisateur?->telephone,
            ]);
            foreach ($commandes as $c) {
                $c->update(['paiement_id' => $paiement->id, 'statut_paiement' => $especes ? 'a_la_livraison' : 'a_payer']);
                HistoriqueOperation::consigner('commande', $c->id, 'paiement_initie', $acheteur->utilisateur, null, null, null,
                    ['reference' => $paiement->reference, 'moyen' => $moyen, 'montant' => (float) $c->total_a_payer]);
            }

            return $paiement;
        });
    }

    /** Paiement confirmé par le fournisseur (ou le simulateur) : l'argent est mis de côté. */
    public function reussi(Paiement $p, ?string $referenceExterne = null): void
    {
        $prevenus = DB::transaction(function () use ($p, $referenceExterne) {
            $p = Paiement::whereKey($p->id)->lockForUpdate()->first();
            if ($p->statut !== 'initie') {
                return collect(); // déjà traité (notification reçue deux fois)
            }
            $p->update(['statut' => 'reussi', 'paye_le' => now(), 'reference_externe' => $referenceExterne]);

            return $p->commandes()->with('stock.production.producteur.utilisateur', 'stock.entrepot')->get()->each(function (Commande $c) use ($p) {
                $c->update(['statut_paiement' => 'paye']);
                foreach ($this->parts($c) as [$beneficiaire, $utilisateur, $montant]) {
                    Versement::create(['commande_id' => $c->id, 'paiement_id' => $p->id, 'beneficiaire' => $beneficiaire,
                        'utilisateur_id' => $utilisateur?->id, 'montant' => $montant, 'statut' => 'bloque']);
                }
                HistoriqueOperation::consigner('commande', $c->id, 'paiement_recu', null, null, null, null, ['reference' => $p->reference, 'montant' => (float) $c->total_a_payer]);
            });
        });

        foreach ($prevenus as $c) {
            $c->stock?->production?->producteur?->utilisateur?->notify(new OperationModifieeNotification('paiement_recu', sprintf(
                'Commande %s payée par l\'acheteur. %s FCFA vous seront versés dès la réception de la marchandise.',
                $c->numero_commande, self::fcfa($c->montant_producteur)), ['commande_id' => $c->id]));
        }
    }

    public function echoue(Paiement $p): void
    {
        if ($p->statut === 'initie') {
            $p->update(['statut' => 'echoue']);
            $p->commandes()->update(['paiement_id' => null]);
        }
    }

    /**
     * Réception confirmée : l'argent mis de côté est versé à chacun. Payé à la
     * livraison : le transporteur a encaissé ; il garde la livraison et remet le reste.
     */
    public function liberer(Commande $c, bool $forcer = false): void
    {
        $c = $c->fresh(['livraison.transporteur.utilisateur', 'stock.production.producteur.utilisateur', 'stock.entrepot']);
        if (! $forcer && Incident::ouverts()->where('sujet_type', 'commande')->where('sujet_id', $c->id)->exists()) {
            $c->update(['statut_paiement' => 'litige']);

            return;
        }

        if (in_array($c->statut_paiement, ['paye', 'litige'], true)) {
            $versements = Versement::where('commande_id', $c->id)->where('statut', 'bloque')->get();
            foreach ($versements as $v) {
                $u = $v->beneficiaire === 'transporteur' ? $c->livraison?->transporteur?->utilisateur : $v->utilisateur;
                $v->update(['utilisateur_id' => $u?->id, 'statut' => 'verse', 'verse_le' => now(), 'moyen' => $u?->moyen_paiement ?? 'wave',
                    'telephone' => $u?->numero_paiement ?: $u?->telephone, 'reference_externe' => 'SIM-'.strtoupper(Str::random(8))]);
                if ($u && $v->montant > 0) {
                    $u->notify(new OperationModifieeNotification('argent_verse', sprintf('%s FCFA versés sur votre %s %s pour la commande %s.',
                        self::fcfa($v->montant), self::moyen($v->moyen), $v->telephone ?? '', $c->numero_commande), ['commande_id' => $c->id]));
                }
            }
            $c->update(['statut_paiement' => 'libere']);
            HistoriqueOperation::consigner('commande', $c->id, 'argent_verse', null, null, null, null, ['forcee' => $forcer]);
        } elseif ($c->statut_paiement === 'a_la_livraison') {
            // Espèces : le transporteur garde la livraison, remet le reste à chacun
            foreach ($this->parts($c) as [$beneficiaire, $u, $montant]) {
                Versement::create(['commande_id' => $c->id, 'paiement_id' => $c->paiement_id, 'beneficiaire' => $beneficiaire,
                    'utilisateur_id' => $u?->id, 'montant' => $montant, 'moyen' => 'especes',
                    'statut' => $beneficiaire === 'transporteur' ? 'regle' : 'a_regler', 'verse_le' => $beneficiaire === 'transporteur' ? now() : null]);
            }
            $c->paiement?->update(['statut' => 'reussi', 'paye_le' => now()]);
            $c->update(['statut_paiement' => 'encaisse']);
            $c->stock?->production?->producteur?->utilisateur?->notify(new OperationModifieeNotification('argent_a_recevoir', sprintf(
                'Commande %s payée en espèces au transporteur : %s FCFA vous reviennent.', $c->numero_commande, self::fcfa($c->montant_producteur)), ['commande_id' => $c->id]));
        }
    }

    /** Commande annulée : remboursement de l'acheteur (sa part de paiement). */
    public function rembourser(Commande $c): void
    {
        $c = $c->fresh(['paiement', 'acheteur.utilisateur']);
        if (! in_array($c->statut_paiement, ['paye', 'litige'], true)) {
            if ($c->statut_paiement === 'a_la_livraison') {
                $c->update(['statut_paiement' => 'annule']);
            }

            return;
        }
        Versement::where('commande_id', $c->id)->whereIn('statut', ['bloque'])->update(['statut' => 'annule']);
        $c->update(['statut_paiement' => 'rembourse']);
        if ($c->paiement && $c->paiement->commandes()->where('statut_paiement', '!=', 'rembourse')->doesntExist()) {
            $c->paiement->update(['statut' => 'rembourse', 'rembourse_le' => now()]);
        }
        HistoriqueOperation::consigner('commande', $c->id, 'remboursement', null, null, null, null, ['montant' => (float) $c->total_a_payer]);
        $c->acheteur?->utilisateur?->notify(new OperationModifieeNotification('remboursement', sprintf(
            '%s FCFA remboursés sur votre %s pour la commande %s.', self::fcfa($c->total_a_payer), self::moyen($c->paiement?->moyen), $c->numero_commande), ['commande_id' => $c->id]));
    }

    /** Parts de la commande : [bénéficiaire, utilisateur, montant]. */
    private function parts(Commande $c): array
    {
        return array_values(array_filter([
            ['producteur', $c->stock?->production?->producteur?->utilisateur, (float) $c->montant_producteur],
            ['entrepot', $c->stock?->entrepot ? Utilisateur::find($c->stock->entrepot->utilisateur_id) : null, (float) $c->frais_stockage],
            ['transporteur', $c->livraison?->transporteur?->utilisateur, (float) $c->frais_livraison],
            ['plateforme', null, (float) $c->commission],
        ], fn ($p) => $p[2] > 0));
    }

    public static function fcfa(float|int|string|null $v): string
    {
        return number_format((float) $v, 0, ',', ' ');
    }

    public static function moyen(?string $m): string
    {
        return ['wave' => 'Wave', 'orange_money' => 'Orange Money', 'especes' => 'paiement en espèces'][$m] ?? 'compte';
    }

    private function refus(string $message, int $statut = 422): never
    {
        throw new HttpResponseException(response()->json(['message' => $message], $statut));
    }

    /** Paiements de l'acheteur, pour son historique. */
    public static function deLAcheteur(AcheteurGros $a): Collection
    {
        return Paiement::with('commandes:id,paiement_id,numero_commande,produit,total_a_payer,statut_paiement')->where('acheteur_id', $a->id)->orderByDesc('id')->get();
    }
}
