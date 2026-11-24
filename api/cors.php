<?php

// ==================================================
// CONFIGURATION CORS - YEBANA-OKAPI
// ==================================================

$allowedOrigins = [

    // Développement local
    'http://localhost:8081',
    'http://localhost:19006',
    'http://127.0.0.1:8081',

    // Domaine officiel
    'https://yebana.marcomabandwe.online',

    // Autres domaines autorisés
    'https://yebana-okapi.online',
    'https://www.yebana-okapi.online',

];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

header('Vary: Origin');

if (in_array($origin, $allowedOrigins, true)) {

    header('Access-Control-Allow-Origin: ' . $origin);

}

header(
    'Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS'
);

header(
    'Access-Control-Allow-Headers: Content-Type, Authorization, Accept, X-Requested-With'
);

header('Access-Control-Max-Age: 86400');

// ==================================================
// TRAITEMENT DES REQUETES OPTIONS
// ==================================================

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {

    http_response_code(204);

    exit;

}
