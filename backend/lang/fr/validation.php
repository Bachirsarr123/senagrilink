<?php

/*
 * Messages de validation en français (lot 5 : « vrais messages d'erreur »).
 * Les messages propres à un formulaire restent dans ses classes Request.
 */
return [
    'accepted'         => 'Le champ :attribute doit être accepté.',
    'array'            => 'Le champ :attribute doit être une liste.',
    'between'          => [
        'numeric' => 'Le champ :attribute doit être compris entre :min et :max.',
        'string'  => 'Le champ :attribute doit contenir entre :min et :max caractères.',
        'array'   => 'Le champ :attribute doit contenir entre :min et :max éléments.',
    ],
    'boolean'          => 'Le champ :attribute doit être vrai ou faux.',
    'confirmed'        => 'La confirmation du champ :attribute ne correspond pas.',
    'date'             => 'Le champ :attribute n\'est pas une date valide.',
    'distinct'         => 'Le champ :attribute contient une valeur en double.',
    'email'            => 'Le champ :attribute doit être une adresse e-mail valide.',
    'exists'           => 'La valeur choisie pour :attribute est introuvable.',
    'in'               => 'La valeur choisie pour :attribute n\'est pas valide.',
    'integer'          => 'Le champ :attribute doit être un nombre entier.',
    'json'             => 'Le champ :attribute doit être un texte JSON valide.',
    'max'              => [
        'numeric' => 'Le champ :attribute ne peut pas dépasser :max.',
        'string'  => 'Le champ :attribute ne peut pas dépasser :max caractères.',
        'array'   => 'Le champ :attribute ne peut pas contenir plus de :max éléments.',
        'file'    => 'Le fichier :attribute ne peut pas dépasser :max kilo-octets.',
    ],
    'min'              => [
        'numeric' => 'Le champ :attribute doit être au moins :min.',
        'string'  => 'Le champ :attribute doit contenir au moins :min caractères.',
        'array'   => 'Le champ :attribute doit contenir au moins :min éléments.',
        'file'    => 'Le fichier :attribute doit faire au moins :min kilo-octets.',
    ],
    'numeric'          => 'Le champ :attribute doit être un nombre.',
    'prohibited'       => 'Le champ :attribute ne doit pas être renseigné.',
    'regex'            => 'Le format du champ :attribute n\'est pas valide.',
    'required'         => 'Le champ :attribute est obligatoire.',
    'required_if'      => 'Le champ :attribute est obligatoire dans ce cas.',
    'required_with'    => 'Le champ :attribute est obligatoire avec :values.',
    'required_without' => 'Le champ :attribute est obligatoire.',
    'same'             => 'Les champs :attribute et :other doivent être identiques.',
    'size'             => [
        'numeric' => 'Le champ :attribute doit valoir :size.',
        'string'  => 'Le champ :attribute doit contenir :size caractères.',
        'array'   => 'Le champ :attribute doit contenir :size éléments.',
    ],
    'string'           => 'Le champ :attribute doit être un texte.',
    'unique'           => 'Cette valeur de :attribute est déjà utilisée.',
    'url'              => 'Le champ :attribute doit être une adresse web valide.',
    'after_or_equal'   => 'Le champ :attribute doit être une date égale ou postérieure au :date.',
    'before_or_equal'  => 'Le champ :attribute doit être une date égale ou antérieure au :date.',

    'attributes' => [
        'email' => 'adresse e-mail', 'mot_de_passe' => 'mot de passe', 'nom' => 'nom', 'prenom' => 'prénom',
        'telephone' => 'téléphone', 'adresse' => 'adresse', 'role' => 'rôle', 'motif' => 'motif',
        'quantite' => 'quantité', 'quantite_reservee' => 'quantité', 'quantite_reelle' => 'quantité reçue',
        'quantite_estimee' => 'quantité estimée', 'prix_unitaire' => 'prix au kilo', 'type_culture' => 'produit',
        'date_recolte' => 'date de récolte', 'date_debut' => 'date de début', 'date_fin' => 'date de fin',
        'entrepot_id' => 'entrepôt', 'production_id' => 'récolte', 'transporteur_id' => 'transporteur', 'stock_id' => 'produit',
        'commande_id' => 'commande', 'code' => 'code de réception', 'code_lot' => 'code du lot', 'description' => 'description',
        'type' => 'type', 'decision' => 'décision', 'latitude' => 'latitude', 'longitude' => 'longitude', 'positions' => 'positions',
        'etape' => 'étape', 'mode_transport' => 'mode de transport', 'etat_marchandise' => 'état de la marchandise',
        'seuil_alerte' => 'seuil d\'alerte', 'lignes' => 'panier', 'origine' => 'départ', 'destination' => 'destination',
    ],
];
