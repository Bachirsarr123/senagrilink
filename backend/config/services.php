<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],


    /*
     * Suivi GPS (lot 4). Fond de carte : un fournisseur autorisé pour la
     * production (ex. MapTiler, Stadia, Thunderforest) se règle par variables
     * d'environnement ; à défaut, les tuiles publiques d'OpenStreetMap
     * (usage limité, à remplacer avant l'ouverture au public).
     * Trajet routier : service compatible OSRM (/route/v1/driving/...) ; à
     * défaut, distance estimée (vol d'oiseau × 1,3) et vitesse moyenne.
     */
    'carte' => [
        'tuiles_url'  => env('CARTE_TUILES_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
        'attribution' => env('CARTE_ATTRIBUTION', '© les contributeurs d\'OpenStreetMap'),
    ],

    // Lot 9 : itinéraire guidé. OpenRouteService (clé ROUTAGE_CLE, secrète,
    // saisie dans Render) ou service compatible OSRM (ROUTAGE_URL).
    'routage' => [
        'fournisseur'  => env('ROUTAGE_FOURNISSEUR'),
        'cle'          => env('ROUTAGE_CLE'),
        'url'          => env('ROUTAGE_URL'),
        'delai_s'      => (int) env('ROUTAGE_DELAI_S', 4),
    ],

    // Lot 6 (Q15) : notifications sur téléphone. Fichier JSON du compte de
    // service Firebase encodé en base64 ; absent = pas d'envoi (secret Render).
    'firebase' => [
        'credentials_base64' => env('FIREBASE_CREDENTIALS_BASE64'),
    ],

    'suivi' => [
        'conservation_jours' => (int) env('SUIVI_CONSERVATION_JOURS', 30),
        'vitesse_defaut_kmh' => (float) env('SUIVI_VITESSE_DEFAUT_KMH', 40),
    ],

];
