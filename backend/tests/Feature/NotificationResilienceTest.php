<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\Stock;
use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\ConstruitParcours;
use Tests\TestCase;

/**
 * P-01 — une diffusion temps réel (Reverb) indisponible ne doit jamais faire
 * échouer une action déjà enregistrée : la notification reste en base et
 * l'utilisateur reçoit la réponse de succès.
 */
class NotificationResilienceTest extends TestCase
{
    use ConstruitParcours, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Même configuration qu'en production sans serveur Reverb : un port fermé.
        config([
            'broadcasting.default'                     => 'reverb',
            'broadcasting.connections.reverb.key'      => 'cle',
            'broadcasting.connections.reverb.secret'   => 'secret',
            'broadcasting.connections.reverb.app_id'   => 'app',
            'broadcasting.connections.reverb.options'  => ['host' => '127.0.0.1', 'port' => 1, 'scheme' => 'http', 'useTLS' => false],
        ]);
    }

    private function stockEtActeurs(): object
    {
        $p = (object) [
            'producteur'   => Utilisateur::factory()->create(),
            'gestionnaire' => Utilisateur::factory()->gestionnaire()->create(),
            'acheteur'     => Utilisateur::factory()->acheteur()->create(),
        ];
        $p->stock = Stock::create([
            'entrepot_id' => $p->gestionnaire->entrepot->id, 'production_id' => $this->production($p->producteur)->id,
            'produit' => 'riz', 'quantite' => 10, 'statut' => 'disponible',
        ]);

        return $p;
    }

    public function test_commande_creee_et_notifiee_en_base_malgre_la_diffusion_en_echec(): void
    {
        Log::spy();
        $p = $this->stockEtActeurs();

        $this->en($p->acheteur)->postJson('/api/commandes', ['stock_id' => $p->stock->id, 'quantite' => 4])
             ->assertStatus(201);

        $this->assertSame(1, Commande::count());
        // Les deux destinataires sont notifiés : l'échec du premier envoi temps réel n'interrompt pas le second.
        $this->assertSame(1, $p->producteur->fresh()->notifications()->count());
        $this->assertSame(1, $p->gestionnaire->fresh()->notifications()->count());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'Diffusion temps réel'))->atLeast()->once();
    }

    public function test_confirmation_repond_succes_et_debite_une_seule_fois(): void
    {
        $p        = $this->stockEtActeurs();
        $commande = $this->commande($p->acheteur, $p->stock); // 10 kg

        $this->en($p->gestionnaire)->putJson("/api/commandes/{$commande->id}/confirmer")->assertOk();
        $this->assertSame('confirmee', $commande->fresh()->statut);
        $this->assertSame(0.0, (float) $p->stock->fresh()->quantite);
        $this->assertSame(1, $p->acheteur->fresh()->notifications()->count());
    }
}
