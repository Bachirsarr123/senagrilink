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
     * En production, remplacer par les domaines réels.
     */
    'allowed_origins' => [
        'http://localhost:4200',
        'http://127.0.0.1:4200',
    ],

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
