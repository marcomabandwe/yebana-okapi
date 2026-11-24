<?php

require_once __DIR__ . '/cors.php';

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../Config/database.php';

// =====================================================
// CONFIGURATION
// =====================================================

define('DEMO_MODE', false);

// =====================================================
// RÉPONSE JSON
// =====================================================

function respond($code, $data)
{
    http_response_code($code);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

// =====================================================
// MÉTHODE HTTP
// =====================================================

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(405, [
        'status' => 'error',
        'message' => 'Méthode non autorisée.'
    ]);
}

// =====================================================
// FICHE DE DÉMONSTRATION
// =====================================================

if (DEMO_MODE) {
    respond(200, [
        'status' => 'success',
        'person' => [
            'id' => 'demo',
            'username' => 'demo',
            'type_profil' => 'CIVIL',
            'nom_complet' => 'TITULAIRE DE DEMONSTRATION',
            'photo_url' => null,
            'telephone' => '0000000000',
            'adresse_physique' => 'Adresse fictive',
            'emplacement' => null,
            'family_members' => [
                [
                    'id' => 'demo-1',
                    'nom_complet' => 'MEMBRE FICTIF 1',
                    'lien_parental' => 'Frère',
                    'photo_url' => null
                ],
                [
                    'id' => 'demo-2',
                    'nom_complet' => 'MEMBRE FICTIF 2',
                    'lien_parental' => 'Sœur',
                    'photo_url' => null
                ]
            ]
        ]
    ]);
}

// =====================================================
// IDENTIFIANT DU TITULAIRE
// =====================================================

$id = trim((string) ($_GET['id'] ?? ''));
$username = trim((string) ($_GET['username'] ?? ''));

if ($id !== '') {

    $uuidPattern =
        '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i';

    if (!preg_match($uuidPattern, $id)) {
        respond(400, [
            'status' => 'error',
            'message' => 'Identifiant UUID invalide.'
        ]);
    }

    $where = 'u.id = :value';
    $value = $id;

} elseif ($username !== '' && strlen($username) <= 100) {

    $where = 'u.username = :value';
    $value = $username;

} else {

    respond(400, [
        'status' => 'error',
        'message' => 'Identifiant manquant ou invalide.'
    ]);
}

// =====================================================
// CONNEXION À AIVEN
// =====================================================

try {

    $pdo = (new Database())->getConnection();

    if (!$pdo) {
        throw new RuntimeException(
            'Connexion à Aiven indisponible.'
        );
    }

    // =================================================
    // RECHERCHE DU TITULAIRE
    // =================================================

    $sql = "
        SELECT
            u.id,
            u.username,
            u.type_profil,

            CONCAT_WS(
                ' ',
                COALESCE(pc.nom, pm.nom),
                COALESCE(pc.postnom, pm.postnom),
                COALESCE(pc.prenom, pm.prenom)
            ) AS nom_complet

        FROM users u

        LEFT JOIN profiles_civil pc
            ON pc.user_id = u.id

        LEFT JOIN profiles_militaire pm
            ON pm.user_id = u.id

        WHERE {$where}

        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ':value' => $value
    ]);

    $person = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$person) {
        respond(404, [
            'status' => 'error',
            'message' => 'Titulaire introuvable.'
        ]);
    }

    // =================================================
    // STRUCTURE DE LA FICHE PUBLIQUE
    // =================================================

    $identity = [
        'id' => $person['id'],
        'username' => $person['username'],
        'type_profil' => strtoupper(
            $person['type_profil']
        ),
        'nom_complet' => trim(
            $person['nom_complet']
        ),
        'photo_url' => null,
        'telephone' => null,
        'adresse_physique' => null,
        'emplacement' => null,
        'family_members' => []
    ];

    // =================================================
    // ENVOI DU RÉSULTAT
    // =================================================

    respond(200, [
        'status' => 'success',
        'person' => $identity
    ]);

} catch (Throwable $e) {

    error_log(
        'public_identity.php : ' . $e->getMessage()
    );

    respond(500, [
        'status' => 'error',
        'message' =>
            'Impossible de récupérer la fiche.'
    ]);
}
