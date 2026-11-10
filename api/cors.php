
<?php

// Apache gere les en-tetes CORS.
// Ce fichier traite uniquement les requetes OPTIONS.

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
