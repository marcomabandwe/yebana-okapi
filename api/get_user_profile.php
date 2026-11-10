<?php
// api/get_user_profile.php
// YEBANA-OKAPI - Profil utilisateur
// Compatible Android et Web

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Les en-tetes CORS sont geres par Apache
header('Content-Type: application/json; charset=utf-8');

function sendJson($code, $data)
{
    http_response_code($code);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

// Preflight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Cette API accepte uniquement GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJson(405, [
        'status' => 'error',
        'message' => 'Méthode non autorisée.'
    ]);
}

// Validation de l'identifiant
$userId = filter_var(
    $_GET['user_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($userId === false || $userId === null) {
    sendJson(400, [
        'status' => 'error',
        'message' => 'ID utilisateur invalide ou manquant.'
    ]);
}

try {
    require_once __DIR__ . '/../Config/database.php';

    $database = new Database();
    $pdo = $database->getConnection();

    if (!$pdo instanceof PDO) {
        throw new RuntimeException(
            'Connexion à la base de données indisponible.'
        );
    }

    // 1. Informations principales de l'utilisateur
    $stmtUser = $pdo->prepare("
        SELECT
            id,
            email,
            username,
            telephone,
            type_profil,
            statut_compte
        FROM users
        WHERE id = :id
        LIMIT 1
    ");

    $stmtUser->execute([
        ':id' => $userId
    ]);

    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        sendJson(404, [
            'status' => 'error',
            'message' => 'Utilisateur introuvable.'
        ]);
    }

    $typeProfil = strtoupper(
        trim((string) ($user['type_profil'] ?? 'CIVIL'))
    );

    $profileData = [];

    // 2. Profil militaire ou civil
    if ($typeProfil === 'MILITAIRE') {

        $stmtProfile = $pdo->prepare("
            SELECT *
            FROM profiles_militaire
            WHERE user_id = :user_id
            LIMIT 1
        ");

        $stmtProfile->execute([
            ':user_id' => $userId
        ]);

        $profileData = $stmtProfile->fetch(PDO::FETCH_ASSOC) ?: [];
        $profileData['type'] = 'Militaire';

    } else {

        $stmtProfile = $pdo->prepare("
            SELECT *
            FROM profiles_civil
            WHERE user_id = :user_id
            LIMIT 1
        ");

        $stmtProfile->execute([
            ':user_id' => $userId
        ]);

        $profileData = $stmtProfile->fetch(PDO::FETCH_ASSOC) ?: [];

        // Conserver le comportement de secours existant
        if (empty($profileData)) {

            $stmtFallback = $pdo->prepare("
                SELECT *
                FROM profiles_civil
                WHERE id = :user_id
                LIMIT 1
            ");

            $stmtFallback->execute([
                ':user_id' => $userId
            ]);

            $profileData = $stmtFallback->fetch(PDO::FETCH_ASSOC) ?: [];
        }

        $profileData['type'] = 'Civil';
    }

    // 3. Membres de la famille
    $stmtFamily = $pdo->prepare("
        SELECT *
        FROM family_members
        WHERE owner_user_id = :user_id
    ");

    $stmtFamily->execute([
        ':user_id' => $userId
    ]);

    $familyMembers = $stmtFamily->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // 4. Préparer les données pour les écrans
    $createdAt = $profileData['created_at'] ?? null;
    $createdTimestamp = $createdAt ? strtotime($createdAt) : false;

    $formattedUserData = [
        'user_id' => $user['id'],
        'username' => $user['username'] ?? '',
        'email' => $user['email'] ?? '',
        'telephone' => $profileData['telephone']
            ?? $user['telephone']
            ?? '',

        'type' => $profileData['type'],
        'nom' => $profileData['nom'] ?? '',
        'postnom' => $profileData['postnom'] ?? '',
        'prenom' => $profileData['prenom'] ?? '',
        'sexe' => $profileData['sexe'] ?? 'M',

        'dateNaissance' => $profileData['date_naissance'] ?? '',
        'lieuNaissance' => $profileData['lieu_naissance'] ?? '',
        'etatCivil' => $profileData['etat_civil'] ?? 'CELIBATAIRE',

        'statut' => $user['statut_compte'] ?? 'ACTIF',

        'dateEmission' => $createdTimestamp !== false
            ? date('d/m/Y', $createdTimestamp)
            : date('d/m/Y'),

        'photoUri' => $profileData['photo_url'] ?? null,
        'photo_url' => $profileData['photo_url'] ?? null,

        // Données militaires
        'matricule' => $profileData['matricule'] ?? '',
        'grade' => $profileData['grade'] ?? '',
        'unite' => $profileData['unite'] ?? '',
        'garnison' => $profileData['garnison'] ?? '',
        'emplacement' => $profileData['emplacement'] ?? '',
        'origineTribu' => $profileData['origineTribu'] ?? '',

        // Données civiles
        'profession' => $profileData['profession'] ?? '',
        'adressePhysique' => $profileData['adresse_physique'] ?? ''
    ];

    sendJson(200, [
        'status' => 'success',
        'userData' => $formattedUserData,
        'familyMembers' => $familyMembers
    ]);

} catch (Throwable $e) {

    // Les détails techniques restent dans les logs Render
    error_log(
        'YEBANA-OKAPI get_user_profile : ' . $e->getMessage()
    );

    sendJson(500, [
        'status' => 'error',
        'message' => 'Erreur interne lors du chargement du profil.'
    ]);
}
