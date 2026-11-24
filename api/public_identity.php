<?php

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../Config/database.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');


// =====================================================
// REPONSE JSON
// =====================================================

function respond($code, $data)
{
    http_response_code($code);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

// =====================================================
// VERIFICATION DE LA METHODE HTTP
// =====================================================

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!in_array($method, ['GET', 'POST'], true)) {
    respond(405, [
        'status' => 'error',
        'message' => 'Méthode non autorisée.'
    ]);
}

// =====================================================
// RECUPERATION DES PARAMETRES
// =====================================================

if ($method === 'POST') {

    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($input)) {
        respond(400, [
            'status' => 'error',
            'message' => 'Données JSON invalides.'
        ]);
    }

} else {

    $input = $_GET;
}

$id = trim((string) ($input['id'] ?? ''));

$username = trim((string) (
    $input['username'] ?? ''
));

$password = (string) (
    $input['password'] ?? ''
);

// =====================================================
// VERIFICATION DE L'IDENTIFIANT
// =====================================================

$value = $id !== '' ? $id : $username;

if ($value === '' || strlen($value) > 100) {

    respond(400, [
        'status' => 'error',
        'message' => 'Identifiant manquant ou invalide.'
    ]);
}

$uuidPattern =
    '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i';

if (preg_match($uuidPattern, $value)) {

    $where = 'u.id = :value';

} else {

    $where = 'u.username = :value';
}

// =====================================================
// CONNEXION A LA BASE DE DONNEES AIVEN
// =====================================================

try {

    $pdo = (new Database())->getConnection();

    if (!$pdo) {

        throw new RuntimeException(
            'Connexion à la base indisponible.'
        );
    }

    $pdo->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

    // =================================================
    // RECHERCHE DE L'UTILISATEUR
    // =================================================

    $sql = "
        SELECT
            u.id,
            u.username,
            u.type_profil,
            u.password_hash,

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

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {

        respond(404, [
            'status' => 'error',
            'message' => 'Utilisateur introuvable.'
        ]);
    }

    // =================================================
    // INFORMATIONS DE BASE
    // =================================================

    $person = [
        'id' => $user['id'],
        'username' => $user['username'],
        'nom_complet' => trim($user['nom_complet']),
        'type_profil' => strtoupper($user['type_profil'])
    ];

    // =================================================
    // GET : DEMANDE DU MOT DE PASSE
    // =================================================

    if ($method === 'GET') {

        respond(200, [
            'status' => 'success',
            'requires_password' => true,
            'message' => 'Veuillez saisir le mot de passe du titulaire.',
            'person' => $person
        ]);
    }

    // =================================================
    // POST : VERIFICATION DU MOT DE PASSE
    // =================================================

    if ($password === '') {

        respond(400, [
            'status' => 'error',
            'message' => 'Veuillez saisir le mot de passe.'
        ]);
    }

    if (strlen($password) > 1024) {

        respond(400, [
            'status' => 'error',
            'message' => 'Mot de passe invalide.'
        ]);
    }

    // =================================================
    // LIMITATION LOCALE DES TENTATIVES
    // =================================================

    $rateFile = sys_get_temp_dir()
        . '/yebana_identity_'
        . hash('sha256', (string) $user['id'])
        . '.json';

    $handle = fopen($rateFile, 'c+');

    if (!$handle || !flock($handle, LOCK_EX)) {

        if ($handle) {
            fclose($handle);
        }

        respond(503, [
            'status' => 'error',
            'message' => 'Vérification temporairement indisponible.'
        ]);
    }

    $attempts = json_decode(
        stream_get_contents($handle),
        true
    );

    $now = time();

    if (
        !is_array($attempts) ||
        ($attempts['expires'] ?? 0) <= $now
    ) {
        $attempts = [
            'count' => 0,
            'expires' => $now + 900
        ];
    }

    if ($attempts['count'] >= 5) {

        header(
            'Retry-After: '
            . max(1, $attempts['expires'] - $now)
        );

        flock($handle, LOCK_UN);
        fclose($handle);

        respond(429, [
            'status' => 'error',
            'message' => 'Trop de tentatives. Réessayez plus tard.'
        ]);
    }

    $validPassword = password_verify(
        $password,
        (string) $user['password_hash']
    );

    if (!$validPassword) {

        $attempts['count']++;

    } else {

        $attempts = [
            'count' => 0,
            'expires' => $now + 900
        ];
    }

    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($attempts));
    fflush($handle);

    flock($handle, LOCK_UN);
    fclose($handle);

    if (!$validPassword) {

        respond(401, [
            'status' => 'error',
            'message' => 'Mot de passe incorrect.'
        ]);
    }

    unset($password, $user['password_hash']);

    // =================================================
    // DETERMINATION DU TYPE DE PROFIL
    // =================================================

    $isMilitaire =
        $person['type_profil'] === 'MILITAIRE';

    // =================================================
    // RECUPERATION DU PROFIL CIVIL OU MILITAIRE
    // =================================================

    if ($isMilitaire) {

        $sqlProfile = "
            SELECT
                nom,
                postnom,
                prenom,
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
                nom,
                postnom,
                prenom,
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

    $profile = $stmtProfile->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$profile) {

        respond(404, [
            'status' => 'error',
            'message' => 'Profil du titulaire introuvable.'
        ]);
    }

    // =================================================
    // INFORMATIONS COMPLETES DU TITULAIRE
    // =================================================

    $person['nom'] =
        $profile['nom'] ?? '';

    $person['postnom'] =
        $profile['postnom'] ?? '';

    $person['prenom'] =
        $profile['prenom'] ?? '';

    $person['photo_url'] =
        $profile['photo_url'] ?? null;

    $person['telephone'] =
        $profile['telephone'] ?? null;

    $person['adresse_physique'] =
        $isMilitaire
            ? null
            : ($profile['adresse_physique'] ?? null);

    $person['emplacement'] =
        $isMilitaire
            ? ($profile['emplacement'] ?? null)
            : null;

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

        ORDER BY id ASC

        LIMIT 2
    ";

    $stmtFamily = $pdo->prepare($sqlFamily);

    $stmtFamily->execute([
        ':id' => $person['id']
    ]);

    $familyMembers = $stmtFamily->fetchAll(
        PDO::FETCH_ASSOC
    );

    // =================================================
    // AJOUT DES MEMBRES DE FAMILLE
    // =================================================

    $person['family_members'] = [];

    foreach ($familyMembers as $member) {

        $person['family_members'][] = [
            'id' => $member['id'],
            'nom' => $member['nom'],
            'prenom' => $member['prenom'],
            'nom_complet' => $member['nom_complet'],
            'lien_parental' => $member['lien_parental'],
            'relation' => $member['lien_parental'],
            'photo_url' => $member['photo_url']
        ];
    }

    // =================================================
    // ENVOI DE LA FICHE COMPLETE
    // =================================================

    respond(200, [
        'status' => 'success',
        'requires_password' => false,
        'message' => 'Identité récupérée avec succès.',
        'person' => $person
    ]);

} catch (Throwable $e) {

    error_log(
        'public_identity.php : '
        . $e->getMessage()
    );

    respond(500, [
        'status' => 'error',
        'message' => 'Impossible de récupérer la fiche.'
    ]);
}
