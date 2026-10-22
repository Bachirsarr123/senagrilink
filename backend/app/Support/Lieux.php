<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Lieu exact d'une mission (prise en charge d'un dépôt, livraison d'une commande).
 *
 * Le téléphone ou le navigateur envoie sa position automatiquement ; à défaut, on
 * reprend le lieu habituel enregistré au profil. Le premier lieu connu devient le
 * lieu habituel : personne n'a à saisir sa position.
 */
class Lieux
{
    /** Règles de validation d'un lieu facultatif, préfixé (ex. « collecte »). */
    public static function regles(string $prefixe): array
    {
        return [
            "{$prefixe}_latitude"  => ['nullable', 'numeric', 'between:-90,90', "required_with:{$prefixe}_longitude"],
            "{$prefixe}_longitude" => ['nullable', 'numeric', 'between:-180,180', "required_with:{$prefixe}_latitude"],
            "{$prefixe}_adresse"   => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Lieu retenu : celui envoyé, sinon le lieu habituel du profil.
     * Le premier lieu envoyé est mémorisé comme lieu habituel.
     *
     * @return array{0: ?float, 1: ?float, 2: ?string} latitude, longitude, adresse
     */
    public static function retenir(Request $request, string $prefixe, ?Model $profil, ?string $adresseHabituelle = null): array
    {
        $lat = $request->input("{$prefixe}_latitude");
        $lng = $request->input("{$prefixe}_longitude");
        $adresse = $request->input("{$prefixe}_adresse") ?: $adresseHabituelle;

        if ($lat === null || $lng === null) {
            return [$profil?->latitude !== null ? (float) $profil->latitude : null, $profil?->longitude !== null ? (float) $profil->longitude : null, $adresse];
        }

        if ($profil && $profil->latitude === null) {
            $profil->update(['latitude' => $lat, 'longitude' => $lng]);
        }

        return [(float) $lat, (float) $lng, $adresse];
    }

    /** Lieu envoyé, sinon lieu habituel ; rien n'est mémorisé (devis). @return array{0: ?float, 1: ?float} */
    public static function retenirSansMemoriser(Request $request, string $prefixe, ?Model $profil): array
    {
        $lat = $request->input("{$prefixe}_latitude");
        $lng = $request->input("{$prefixe}_longitude");

        return $lat !== null && $lng !== null ? [(float) $lat, (float) $lng]
            : [$profil?->latitude !== null ? (float) $profil->latitude : null, $profil?->longitude !== null ? (float) $profil->longitude : null];
    }

    /** Distance à vol d'oiseau, en kilomètres. */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }
}
