
<?php

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../Config/database.php';

header('Content-Type: application/json; charset=UTF-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =====================================================
// REPONSE JSON
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
// METHODE HTTP
// =====================================================

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(405, [
        'status' => 'error',
        'message' => 'Méthode non autorisée.'
    ]);
}

// =====================================================
// IDENTIFIANT OU USERNAME
// =====================================================

$id = trim((string) ($_GET['id'] ?? ''));
$username = trim((string) ($_GET['username'] ?? ''));

$uuidPattern =
    '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i';

$value = $id !== '' ? $id : $username;

if ($value === '' || strlen($value) > 100) {
    respond(400, [
        'status' => 'error',
        'message' => 'Identifiant invalide.'
    ]);
}

if (preg_match($uuidPattern, $value)) {
    $where = 'u.id = :value';
} else {
    $where = 'u.username = :value';
}

// =====================================================
// CONNEXION A AIVEN
// =====================================================

try {

    $pdo = (new Database())->getConnection();

    if (!$pdo) {
        throw new RuntimeException(
            'Connexion indisponible.'
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

            CASE
                WHEN u.type_profil = 'MILITAIRE'
                THEN CONCAT_WS(
                    ' ',
                    pm.nom,
                    pm.postnom,
                    pm.prenom
                )
                ELSE CONCAT_WS(
                    ' ',
                    pc.nom,
                    pc.postnom,
                    pc.prenom
                )
            END AS nom_complet

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
            'message' => 'Utilisateur introuvable.'
        ]);
    }

    // =================================================
    // VERIFICATION DU TITULAIRE AUTHENTIFIE
    // =================================================

    $sessionUserId = (string) (
        $_SESSION['user_id'] ?? ''
    );

    $isOwner = $sessionUserId !== ''
        && $sessionUserId === (string) $person['id'];

    if (!$isOwner) {
        respond(200, [
            'status' => 'success',
            'person' => $person
        ]);
    }

    // =================================================
    // RECUPERATION DU PROFIL COMPLET
    // =================================================

    $isMilitaire =
        strtoupper($person['type_profil']) === 'MILITAIRE';

    if ($isMilitaire) {

        $sqlProfile = "
            SELECT
                photo_url,
                telephone,
                emplacement

            FROM profiles_militaire

            WHERE user_id = :id

            LIMIT 1
        ";

    } else {

        $sqlProfile = "
            SELECT
                photo_url,
                telephone,
                adresse_physique

            FROM profiles_civil

            WHERE user_id = :id

            LIMIT 1
        ";
    }

    $stmtProfile = $pdo->prepare($sqlProfile);

    $stmtProfile->execute([
        ':id' => $person['id']
    ]);

    $profile = $stmtProfile->fetch(PDO::FETCH_ASSOC);

    if (!$profile) {
        $profile = [];
    }

    $person['photo_url'] =
        $profile['photo_url'] ?? null;

    $person['telephone'] =
        $profile['telephone'] ?? null;

    $person['adresse_physique'] =
        $profile['adresse_physique'] ?? null;

    $person['emplacement'] =
        $profile['emplacement'] ?? null;

    // =================================================
    // RECUPERATION DES DEUX MEMBRES DE FAMILLE
    // =================================================

    $sqlFamily = "
        SELECT
            id,
            nom,
            prenom,

            CONCAT_WS(
                ' ',
                nom,
                prenom
            ) AS nom_complet,

            lien_parental,
            photo_url

        FROM family_members

        WHERE owner_user_id = :id

        ORDER BY id

        LIMIT 2
    ";

    $stmtFamily = $pdo->prepare($sqlFamily);

    $stmtFamily->execute([
        ':id' => $person['id']
    ]);

    $person['family_members'] =
        $stmtFamily->fetchAll(PDO::FETCH_ASSOC);

    // =================================================
    // RESULTAT FINAL
    // =================================================

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
        'message' => 'Erreur de récupération du profil.'
    ]);
}
