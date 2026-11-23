<?php
/**
 * YEBANA-OKAPI
 * API : public_identity.php
 *
 * Recherche d'une identité par UUID ou username.
 * Compatible avec MySQL Aiven et le frontend Expo.
 */

require_once __DIR__ . '/cors.php';

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../Config/database.php';

/**
 * Envoyer une réponse JSON et arrêter l'exécution.
 */
function respond($httpCode, $data)
{
    http_response_code($httpCode);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

// Autoriser uniquement GET.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, OPTIONS');

    respond(405, [
        'status' => 'error',
        'message' => 'Méthode non autorisée.'
    ]);
}

// =====================================================
// 1. RÉCUPÉRATION DES PARAMÈTRES
// =====================================================

$id = trim((string) ($_GET['id'] ?? ''));
$username = trim((string) ($_GET['username'] ?? ''));

$where = '';
$value = '';

// Recherche prioritaire par UUID.
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

} elseif ($username !== '') {

    if (mb_strlen($username) > 100) {
        respond(400, [
            'status' => 'error',
            'message' => 'Nom d’utilisateur trop long.'
        ]);
    }

    $where = 'u.username = :value';
    $value = $username;

} else {

    respond(400, [
        'status' => 'error',
        'message' => 'Identifiant manquant.'
    ]);
}

// =====================================================
// 2. CONNEXION À AIVEN
// =====================================================

try {

    $database = new Database();
    $pdo = $database->getConnection();

    if (!$pdo) {
        throw new RuntimeException(
            'Connexion à la base indisponible.'
        );
    }

    // =================================================
    // 3. RECHERCHE DU TITULAIRE
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
            'message' => 'Aucun titulaire trouvé.'
        ]);
    }

    // =================================================
    // 4. PRÉPARATION DE LA FICHE PUBLIQUE
    // =================================================

    $typeProfil = strtoupper(
        trim((string) ($person['type_profil'] ?? ''))
    );

    $identity = [
        'id' => $person['id'],
        'username' => $person['username'],
        'type_profil' => $typeProfil,
        'nom_complet' => trim(
            (string) ($person['nom_complet'] ?? '')
        ),

        // Champs réservés à une publication autorisée.
        'photo_url' => null,
        'telephone' => null,
        'adresse_physique' => null,
        'emplacement' => null,
        'family_members' => []
    ];

    // =================================================
    // 5. RÉPONSE JSON
    // =================================================

    respond(200, [
        'status' => 'success',
        'person' => $identity
    ]);

} catch (Throwable $e) {

    // Enregistrer les détails uniquement côté serveur.
    error_log(
        'YEBANA-OKAPI public_identity.php : ' .
        $e->getMessage()
    );

    respond(500, [
        'status' => 'error',
        'message' =>
            'Impossible de récupérer la fiche d’identification.'
    ]);
}
