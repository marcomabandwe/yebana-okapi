<?php
require_once __DIR__ . '/../Config/database.php';
require_once __DIR__ . '/cors.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function respond($code, $data) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$username = trim($_GET['username'] ?? '');

if (!$id && ($username === '' || strlen($username) > 100)) {
    respond(400, [
        'status' => 'error',
        'message' => 'Identifiant invalide.'
    ]);
}

try {
    $pdo = (new Database())->getConnection();

    // Recherche de l'identité dans les tables existantes.
    // Le nom de colonne username doit correspondre à users.
    $sql = "
        SELECT
            u.id,
            u.type_profil,
            CONCAT_WS(' ',
                COALESCE(pc.nom, pm.nom),
                COALESCE(pc.postnom, pm.postnom),
                COALESCE(pc.prenom, pm.prenom)
            ) AS nom_complet
        FROM users u
        LEFT JOIN profiles_civil pc ON pc.user_id = u.id
        LEFT JOIN profiles_militaire pm ON pm.user_id = u.id
        WHERE " . ($id ? "u.id = :value" : "u.username = :value") . "
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':value' => $id ?: $username]);
    $person = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$person) {
        respond(404, [
            'status' => 'error',
            'message' => 'Titulaire introuvable.'
        ]);
    }

    // Informations publiques minimales.
    // Les données privées nécessitent une autorisation
    // de publication contrôlée côté serveur.
    $person['photo_url'] = null;
    $person['telephone'] = null;
    $person['adresse_physique'] = null;
    $person['emplacement'] = null;
    $person['family_members'] = [];

    respond(200, [
        'status' => 'success',
        'person' => $person
    ]);

} catch (Throwable $e) {
    error_log('public_identity: ' . $e->getMessage());

    respond(500, [
        'status' => 'error',
        'message' => 'Impossible de charger cette identité.'
    ]);
}
