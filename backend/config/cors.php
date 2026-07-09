<?php

return [
    /*
     * Chemins sur lesquels le middleware CORS s'applique.
     * Couvre toutes les routes API et le cookie Sanctum.
     */
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
     * En développement, on autorise localhost:4200 (Angular) et localhost:8000 (API).
     * En production, FRONTEND_URL pointe vers le domaine réel du frontend déployé.
     */
    'allowed_origins' => array_filter([
        'http://localhost:4200',
        'http://127.0.0.1:4200',
        // Test depuis un autre appareil du réseau local (ex. téléphone) :
        // ajouter ici l'IP LAN affichée par `ipconfig`, ex. http://192.168.1.3:4200
        env('FRONTEND_URL'),
        env('LAN_FRONTEND_URL'),
    ]),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    /*
     * false car on utilise l'authentification par token Bearer (Sanctum API tokens),
     * pas par cookie de session. Mettre à true uniquement pour une SPA avec cookies.
     */
    'supports_credentials' => false,
];
