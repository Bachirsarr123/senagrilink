<?php

namespace Tests;

use App\Models\Entrepot;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Pilote automatique (lot 11) : actif par défaut pour les vrais entrepôts,
     * coupé dans les tests des circuits manuels. Un test du pilote le remet à vrai.
     */
    protected bool $piloteAutomatique = false;

    protected function setUp(): void
    {
        parent::setUp();
        Entrepot::flushEventListeners();
        if (! $this->piloteAutomatique) {
            Entrepot::creating(function (Entrepot $e) {
                foreach (['auto_accepter_depots', 'auto_confirmer_commandes', 'auto_affecter_transporteurs'] as $regle) {
                    $e->{$regle} ??= false;
                }
            });
        }
    }
}
