<?php

namespace App\Http\Requests;

use App\Models\Reservation;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreEntreeStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'production_id' => ['nullable', 'integer', $this->regleProductionReceptionnee()],
            'produit'       => ['required', 'string', 'max:150'],
            'quantite'      => ['required', 'numeric', 'min:0.01'],
            'date_entree'   => ['nullable', 'date'],
            'seuil_alerte'  => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * R1 — une entrée manuelle ne peut citer qu'un lot déjà réceptionné dans
     * CET entrepôt : réservation « enregistree » (circuit validerMarchandise)
     * faite par le producteur propriétaire du lot. Même message que le lot
     * soit inexistant, d'un autre entrepôt ou non réceptionné.
     * Sans profil entrepôt (ex. administrateur), le contrôleur répond 404 comme avant.
     */
    private function regleProductionReceptionnee(): Closure
    {
        return function (string $attribut, mixed $valeur, Closure $echec) {
            $entrepotId = $this->user()?->entrepot?->id;

            if ($entrepotId === null) {
                return;
            }

            $receptionnee = Reservation::where('production_id', $valeur)
                ->where('entrepot_id', $entrepotId)
                ->where('statut', 'enregistree')
                ->whereHas('production', fn ($q) => $q->whereColumn('productions.producteur_id', 'reservations.producteur_id'))
                ->exists();

            if (! $receptionnee) {
                $echec('Ce lot n\'a pas été réceptionné dans votre entrepôt (réservation validée requise).');
            }
        };
    }

    public function messages(): array
    {
        return [
            'produit.required'    => 'Le nom du produit est obligatoire.',
            'quantite.required'   => 'La quantité est obligatoire.',
            'quantite.min'        => 'La quantité doit être supérieure à zéro.',
        ];
    }
}
