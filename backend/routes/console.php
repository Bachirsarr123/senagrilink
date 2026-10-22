<?php

use Illuminate\Foundation\Inspiring;
use App\Models\PositionGps;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Lot 4 : positions GPS conservées 30 jours (Q11). Planifiée chaque nuit ; la
// purge est aussi déclenchée de temps en temps à l'envoi des positions, pour
// fonctionner même sans tâche planifiée sur l'hébergeur.
Artisan::command('suivi:purger {--jours=}', function () {
    $n = PositionGps::purger($this->option('jours') !== null ? (int) $this->option('jours') : null);
    $this->info("{$n} position(s) supprimée(s).");
})->purpose('Supprime les positions GPS plus anciennes que la durée de conservation');

Schedule::command('suivi:purger')->dailyAt('03:15');

// Lot 11 : missions sans réponse proposées au transporteur suivant (aussi déclenché au fil des requêtes)
Artisan::command('pilote:relancer', function () {
    $this->info(app(\App\Services\PiloteAutomatique::class)->relancerSansReponse().' mission(s) relancée(s).');
    $this->info(app(\App\Services\Paiements::class)->expirer().' commande(s) non payée(s) annulée(s).');
})->purpose('Propose au transporteur suivant les missions restées sans réponse');

Schedule::command('pilote:relancer')->everyMinute()->withoutOverlapping();
