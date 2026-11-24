
<?php

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../Config/database.php';

header('Content-Type: application/json; charset=UTF-8');

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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(405, [
        'status' => 'error',
        'message' => 'Méthode non autorisée.'
    ]);
}

$id = trim((string) ($_GET['id'] ?? ''));
$username = trim((string) ($_GET['username'] ?? ''));

if ($id !== '') {
    if (!preg_match(
        '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i',
        $id
    )) {
        respond(400, [
            'status' => 'error',
            'message' => 'UUID invalide.'
        ]);
    }

    $where = 'u.id = :value';
    $value = $id;

} elseif ($username !== '' && strlen($username) <= 50) {

    $where = 'u.username = :value';
    $value = $username;

} else {

    respond(400, [
        'status' => 'error',
        'message' => 'Identifiant manquant.'
    ]);
}

try {

    $pdo = (new Database())->getConnection();

    $sql = "
        SELECT
            u.id,
            u.type_profil,

            CONCAT_WS(
                ' ',
                COALESCE(pc.nom, pm.nom),
                COALESCE(pc.postnom, pm.postnom),
                COALESCE(pc.prenom, pm.prenom)
            ) AS nom_complet,

            pp.photo_url,
            pp.telephone,
            pp.adresse_physique,
            pp.emplacement,
            pp.family_members

        FROM users u

        LEFT JOIN profiles_civil pc
            ON pc.user_id = u.id

        LEFT JOIN profiles_militaire pm
            ON pm.user_id = u.id

        LEFT JOIN public_profiles pp
            ON pp.user_id = u.id

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

    $family = json_decode(
        $person['family_members'] ?? '[]',
        true
    );

    $person['family_members'] = is_array($family)
        ? array_slice($family, 0, 2)
        : [];

    respond(200, [
        'status' => 'success',
        'person' => $person
    ]);

} catch (Throwable $e) {

    error_log(
        'public_identity.php : ' . $e->getMessage()
    );

    respond(500, [
        'status' => 'error',
        'message' => 'Impossible de récupérer la fiche.'
    ]);
}
