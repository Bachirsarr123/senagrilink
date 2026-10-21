<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

// La factory s'appelle UserFactory (et non UtilisateurFactory) : on la relie explicitement.
#[UseFactory(UserFactory::class)]
class Utilisateur extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'utilisateurs';

    protected $fillable = [
        'nom',
        'prenom',
        'email',
        'telephone',
        'adresse',
        'mot_de_passe',
        'photo',
        'role',
        'statut',
        'valide_par',
        'valide_le',
        'motif_refus',
    ];

    /** Statuts du compte (lot 6). Seul « actif » peut se connecter. */
    public const ACTIF = 'actif';
    public const BLOQUE = 'bloque';
    public const EN_ATTENTE = 'en_attente_validation';
    public const REFUSE = 'refuse';

    /** Rôles dont le compte est validé par l'administrateur avant activation (Q9). */
    public const ROLES_A_VALIDER = ['gestionnaire_entrepot', 'transporteur'];

    protected $hidden = [
        'mot_de_passe',
    ];

    protected function casts(): array
    {
        return [
            'valide_le' => 'datetime',
            'mot_de_passe' => 'hashed',
        ];
    }

    // Laravel utilise "password" par défaut — on redirige vers mot_de_passe
    public function getAuthPasswordName(): string
    {
        return 'mot_de_passe';
    }

    // Relations vers les profils spécifiques
    public function appareils()
    {
        return $this->hasMany(Appareil::class, 'utilisateur_id');
    }

    /** Message et code renvoyés quand le compte ne peut pas se connecter, ou null s'il est actif. */
    public function refusConnexion(): ?array
    {
        return match ($this->statut) {
            self::BLOQUE     => ['message' => 'Votre compte a été bloqué. Contactez l\'administrateur.', 'code' => 'compte_bloque'],
            self::EN_ATTENTE => ['message' => 'Votre compte est en attente de validation par l\'administrateur. Vous serez prévenu dès son activation.', 'code' => 'compte_en_attente'],
            self::REFUSE     => ['message' => 'Votre demande de compte a été refusée.'.($this->motif_refus ? ' Motif : '.$this->motif_refus : ''), 'code' => 'compte_refuse'],
            default          => null,
        };
    }

    public function producteur()
    {
        return $this->hasOne(Producteur::class, 'utilisateur_id');
    }

    public function entrepot()
    {
        return $this->hasOne(Entrepot::class, 'utilisateur_id');
    }

    public function acheteurGros()
    {
        return $this->hasOne(AcheteurGros::class, 'utilisateur_id');
    }

    public function transporteur()
    {
        return $this->hasOne(Transporteur::class, 'utilisateur_id');
    }

    // Canal privé sur lequel cet utilisateur reçoit ses notifications broadcast
    // (voir routes/channels.php), plus lisible que le nom de classe par défaut.
    public function receivesBroadcastNotificationsOn(): string
    {
        return 'utilisateur.' . $this->id;
    }
}
