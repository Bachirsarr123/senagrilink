<?php

namespace Tests\Feature;

use App\Models\Appareil;
use App\Models\HistoriqueOperation;
use App\Models\Utilisateur;
use App\Notifications\OperationModifieeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConstruitParcours;
use Tests\TestCase;

/**
 * Lot 6 — validation des comptes (Q9), journal d'audit, appareils et
 * notifications sur téléphone par Firebase (Q15), liste des notifications.
 */
class ComptesEtNotificationsTest extends TestCase
{
    use ConstruitParcours, RefreshDatabase;

    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Utilisateur::factory()->administrateur()->create();
    }

    private function inscrire(string $role, string $email, array $extra = [])
    {
        return $this->postJson('/api/register', [
            'nom' => 'Sarr', 'prenom' => 'Binta', 'email' => $email, 'telephone' => '770000000',
            'mot_de_passe' => 'motdepasse1', 'mot_de_passe_confirmation' => 'motdepasse1', 'role' => $role, ...$extra,
        ]);
    }

    private function connecter(string $email)
    {
        $this->app['auth']->forgetGuards();

        return $this->withoutToken()->postJson('/api/login', ['email' => $email, 'mot_de_passe' => 'motdepasse1']);
    }

    public function test_transporteur_attend_la_validation_puis_se_connecte(): void
    {
        $this->inscrire('transporteur', 't@lot6.sn', ['type_vehicule' => 'Camion', 'capacite_charge' => 5, 'zone' => 'Dakar'])
             ->assertStatus(201)->assertJsonPath('en_attente_validation', true)->assertJsonMissingPath('access_token');
        $t = Utilisateur::where('email', 't@lot6.sn')->sole();
        $this->assertSame(Utilisateur::EN_ATTENTE, $t->statut);
        $this->assertContains('compte_a_valider', $this->admin->notifications->pluck('data.type')->all());

        $this->connecter('t@lot6.sn')->assertStatus(403)->assertJsonPath('code', 'compte_en_attente');
        // Même avec un jeton créé à la main, aucune action n'est possible
        $this->withToken($t->createToken('x')->plainTextToken)->getJson('/api/me')->assertStatus(403)->assertJsonPath('code', 'compte_en_attente');

        $this->en(Utilisateur::factory()->gestionnaire()->create())->putJson("/api/admin/utilisateurs/{$t->id}/valider")->assertStatus(403);
        $this->en($this->admin)->putJson("/api/admin/utilisateurs/{$t->id}/valider")->assertOk();
        $this->en($this->admin)->putJson("/api/admin/utilisateurs/{$t->id}/valider")->assertStatus(422);

        $this->connecter('t@lot6.sn')->assertOk()->assertJsonStructure(['access_token']);
        $this->assertSame([$this->admin->id, 'compte_valide'], [$t->fresh()->valide_par, $t->fresh()->notifications->first()->data['type']]);
    }

    public function test_demande_refusee_avec_motif_et_roles_sans_validation(): void
    {
        $this->inscrire('gestionnaire_entrepot', 'g@lot6.sn', ['nom_entrepot' => 'Nord', 'capacite' => 100, 'localisation' => 'Thiès'])->assertStatus(201);
        $g = Utilisateur::where('email', 'g@lot6.sn')->sole();

        $this->en($this->admin)->putJson("/api/admin/utilisateurs/{$g->id}/refuser")->assertStatus(422)->assertJsonValidationErrors('motif');
        $this->en($this->admin)->putJson("/api/admin/utilisateurs/{$g->id}/refuser", ['motif' => 'Entrepôt inconnu'])->assertOk();
        $this->connecter('g@lot6.sn')->assertStatus(403)->assertJsonPath('code', 'compte_refuse')
             ->assertJsonPath('message', 'Votre demande de compte a été refusée. Motif : Entrepôt inconnu');

        // Producteur et acheteur : actifs dès l'inscription (Q9)
        $this->inscrire('acheteur_gros', 'a@lot6.sn', ['type_activite' => 'Grossiste', 'volume_achat_mensuel' => 10])
             ->assertStatus(201)->assertJsonStructure(['access_token']);
        $this->assertSame(Utilisateur::ACTIF, Utilisateur::where('email', 'a@lot6.sn')->sole()->statut);

        $this->en($this->admin)->getJson('/api/admin/utilisateurs?statut=refuse')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_journal_dauthentification_et_dadministration(): void
    {
        $this->inscrire('acheteur_gros', 'j@lot6.sn', ['type_activite' => 'Grossiste', 'volume_achat_mensuel' => 10])->assertStatus(201);
        $u = Utilisateur::where('email', 'j@lot6.sn')->sole();
        $this->withoutToken()->postJson('/api/login', ['email' => 'j@lot6.sn', 'mot_de_passe' => 'mauvais'])->assertStatus(401);
        $jeton = $this->connecter('j@lot6.sn')->json('access_token');
        $this->app['auth']->forgetGuards();
        $this->withToken($jeton)->putJson('/api/profile', ['telephone' => '771111111'])->assertOk();

        $actions = HistoriqueOperation::where('sujet_type', 'utilisateur')->where('sujet_id', $u->id)->orderBy('id')->pluck('action')->all();
        $this->assertSame(['inscription', 'echec_connexion', 'connexion', 'modification_profil'], $actions);
        $profil = HistoriqueOperation::where('action', 'modification_profil')->sole();
        $this->assertSame(['telephone'], $profil->details['champs']);
        $this->assertSame('127.0.0.1', $profil->adresse_ip);

        $journal = $this->en($this->admin)->getJson("/api/admin/journal?sujet_type=utilisateur&action=connexion")->assertOk()->json();
        $this->assertSame(['connexion'], array_column($journal['entrees'], 'action'));
        $this->assertSame($u->id, $journal['entrees'][0]['auteur']['id']);
        $this->en($u)->getJson('/api/admin/journal')->assertStatus(403);
    }

    public function test_appareils_et_notifications_push_firebase(): void
    {
        $u = Utilisateur::factory()->acheteur()->create();
        $autre = Utilisateur::factory()->acheteur()->create();

        // Sans compte de service : enregistrement possible, aucun envoi
        $this->en($u)->postJson('/api/appareils', ['jeton' => 'jeton-tel-1'])->assertOk()->assertJsonPath('push_actif', false);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'acces-test', 'expires_in' => 3600]),
            'fcm.googleapis.com/*'    => Http::sequence()->push(['name' => 'ok'])->push(['error' => ['status' => 'NOT_FOUND', 'message' => 'UNREGISTERED']], 404),
        ]);
        $u->notify(new OperationModifieeNotification('compte_valide', 'Votre compte est activé.'));
        Http::assertNothingSent();

        // Le même téléphone passe à un autre compte : un seul destinataire
        $this->en($autre)->postJson('/api/appareils', ['jeton' => 'jeton-tel-1'])->assertOk();
        $this->assertSame($autre->id, Appareil::sole()->utilisateur_id);
        $this->en($u)->postJson('/api/appareils', ['jeton' => 'jeton-tel-2'])->assertOk();

        // Compte de service (clé RSA de test) : la notification part sur le téléphone
        $cle = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($cle, $pem);
        config(['services.firebase.credentials_base64' => base64_encode(json_encode([
            'project_id' => 'senagrilink-test', 'client_email' => 'push@senagrilink-test.iam.gserviceaccount.com', 'private_key' => $pem,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]))]);

        $u->notify(new OperationModifieeNotification('commande_chargee', 'Votre commande est chargée. Code : 0427', ['commande_id' => 8]));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'projects/senagrilink-test/messages:send')
            && $r['message']['token'] === 'jeton-tel-2'
            && $r['message']['notification']['title'] === 'Commande chargée'
            && $r['message']['data']['commande_id'] === '8'
            && $r->hasHeader('Authorization', 'Bearer acces-test'));
        $this->assertSame(['jeton-tel-1', 'jeton-tel-2'], Appareil::orderBy('id')->pluck('jeton')->all());

        // Application désinstallée : Firebase répond UNREGISTERED, l'appareil est oublié
        $u->notify(new OperationModifieeNotification('incident', 'Incident déclaré'));
        $this->assertSame(['jeton-tel-1'], Appareil::pluck('jeton')->all());

        // Déconnexion avec le jeton de l'appareil : il ne reçoit plus rien
        $this->en($autre)->postJson('/api/logout', ['jeton_appareil' => 'jeton-tel-1'])->assertOk();
        $this->assertSame(0, Appareil::count());
    }

    public function test_tout_marquer_lu_et_compte_a_valider_dans_les_exceptions(): void
    {
        $u = Utilisateur::factory()->acheteur()->create();
        $u->notify(new OperationModifieeNotification('a', 'Un'));
        $u->notify(new OperationModifieeNotification('b', 'Deux'));
        $this->en($u)->getJson('/api/notifications')->assertJsonPath('non_lues', 2);
        $this->en($u)->putJson('/api/notifications/lues')->assertOk();
        $this->en($u)->getJson('/api/notifications')->assertJsonPath('non_lues', 0);

        $this->inscrire('transporteur', 'x@lot6.sn', ['type_vehicule' => 'Moto', 'capacite_charge' => 1, 'zone' => 'Thiès'])->assertStatus(201);
        $ex = collect($this->en($this->admin)->getJson('/api/admin/exceptions')->json('exceptions'))->firstWhere('categorie', 'compte');
        $this->assertSame('Compte transporteur à valider', $ex['message']);
    }
}
