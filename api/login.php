<?php
// API de connexion YEBANA-OKAPI
// Compatible Android et Web

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// CORS est gere par Apache dans le Dockerfile
header('Content-Type: application/json; charset=utf-8');

// Reponse JSON
function sendResponse($statusCode, $data)
{
    http_response_code($statusCode);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

// Gestion des requetes OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Autoriser uniquement POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(405, [
        'status' => 'error',
        'message' => 'Methode non autorisee.'
    ]);
}

try {
    require_once __DIR__ . '/../Config/database.php';

    // Lire les donnees JSON
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    if (!is_array($data)) {
        sendResponse(400, [
            'status' => 'error',
            'message' => 'Donnees JSON invalides.'
        ]);
    }

    $login = trim((string) ($data['login'] ?? ''));
    $password = (string) ($data['password'] ?? '');

    if ($login === '' || $password === '') {
        sendResponse(400, [
            'status' => 'error',
            'message' => 'Veuillez saisir votre identifiant et votre mot de passe.'
        ]);
    }

    // Connexion MySQL
    $database = new Database();
    $db = $database->getConnection();

    if (!$db instanceof PDO) {
        throw new RuntimeException(
            'Connexion a la base de donnees indisponible.'
        );
    }

    // Rechercher l'utilisateur
    $query = "
        SELECT
            id,
            email,
            username,
            password_hash,
            type_profil,
            statut_compte
        FROM users
        WHERE username = :username
           OR email = :email
        LIMIT 1
    ";

    $stmt = $db->prepare($query);

    $stmt->execute([
        ':username' => $login,
        ':email' => $login
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Verification de l'identifiant et du mot de passe
    if (
        !$user ||
        !password_verify($password, $user['password_hash'])
    ) {
        sendResponse(401, [
            'status' => 'error',
            'message' => 'Identifiant ou mot de passe incorrect.'
        ]);
    }

    // Recuperer le profil
    $profile = null;

    if ($user['type_profil'] === 'CIVIL') {
        $profileTable = 'profiles_civil';
    } elseif ($user['type_profil'] === 'MILITAIRE') {
        $profileTable = 'profiles_militaire';
    } else {
        $profileTable = null;
    }

    if ($profileTable !== null) {
        $profileQuery = "
            SELECT *
            FROM {$profileTable}
            WHERE user_id = :user_id
            LIMIT 1
        ";

        $profileStmt = $db->prepare($profileQuery);

        $profileStmt->execute([
            ':user_id' => $user['id']
        ]);

        $profile = $profileStmt->fetch(PDO::FETCH_ASSOC);

        if ($profile === false) {
            $profile = null;
        }
    }

    // Reponse de connexion reussie
    sendResponse(200, [
        'status' => 'success',
        'message' => 'Connexion reussie.',
        'user_id' => $user['id'],
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'username' => $user['username'],
            'typeProfil' => $user['type_profil'],
            'statutCompte' => $user['statut_compte'],
            'profileDetails' => $profile
        ]
    ]);

} catch (Throwable $e) {

    // Enregistrer les details dans les logs Render
    error_log('Erreur login YEBANA-OKAPI : ' . $e->getMessage());

    // Ne pas exposer les details techniques aux utilisateurs
    sendResponse(500, [
        'status' => 'error',
        'message' => 'Erreur interne du serveur. Veuillez reessayer.'
    ]);
}
