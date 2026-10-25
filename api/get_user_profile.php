<?php
// api/get_user_profile.php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Méthode non autorisée."]);
    exit();
}

require_once __DIR__ . '/../Config/database.php';

$database = new Database();
$pdo = $database->getConnection();

// Récupération de l'ID utilisateur passé en paramètre (ex: get_user_profile.php?user_id=UUID)
$userId = $_GET['user_id'] ?? '';

if (empty($userId)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "ID utilisateur manquant."]);
    exit();
}

try {
    // 1. Récupérer les informations de base de l'utilisateur
    $stmtUser = $pdo->prepare("SELECT id, email, username, telephone, type_profil, statut_compte FROM users WHERE id = :id LIMIT 1");
    $stmtUser->execute([':id' => $userId]);
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Utilisateur introuvable."]);
        exit();
    }

    $typeProfil = strtoupper($user['type_profil']);
    $profileData = [];

    // 2. Récupérer le profil spécifique selon le type (CIVIL ou MILITAIRE)
    if ($typeProfil === 'MILITAIRE') {
        $stmtProfile = $pdo->prepare("SELECT * FROM profiles_militaire WHERE user_id = :user_id LIMIT 1");
        $stmtProfile->execute([':user_id' => $userId]);
        $profileData = $stmtProfile->fetch(PDO::FETCH_ASSOC) ?: [];
        $profileData['type'] = 'Militaire';
    } else {
        $stmtProfile = $pdo->prepare("SELECT * FROM profiles_civil WHERE user_id = :user_id LIMIT 1");
        $stmtProfile->execute([':user_id' => $userId]);
        $profileData = $stmtProfile->fetch(PDO::FETCH_ASSOC) ?: [];
        $profileData['type'] = 'Civil';
    }

    // 3. Récupérer les membres de la famille / ayants droit
    $stmtFamily = $pdo->prepare("SELECT * FROM family_members WHERE owner_user_id = :user_id");
    $stmtFamily->execute([':user_id' => $userId]);
    $familyMembers = $stmtFamily->fetchAll(PDO::FETCH_ASSOC);

    // 4. Fusionner les données pour correspondre exactement à ce que tes écrans attendent
    $formattedUserData = [
        'user_id'       => $user['id'],
        'username'      => $user['username'],
        'email'         => $user['email'],
        'telephone'     => $profileData['telephone'] ?? $user['telephone'],
        'type'          => $profileData['type'] ?? ($typeProfil === 'MILITAIRE' ? 'Militaire' : 'Civil'),
        'nom'           => $profileData['nom'] ?? '',
        'postnom'       => $profileData['postnom'] ?? '',
        'prenom'        => $profileData['prenom'] ?? '',
        'sexe'          => $profileData['sexe'] ?? '',
        'dateNaissance' => $profileData['date_naissance'] ?? '',
        'lieuNaissance' => $profileData['lieu_naissance'] ?? '',
        'etatCivil'     => $profileData['etat_civil'] ?? '',
        'statut'        => $user['statut_compte'] ?? 'ACTIF',
        'dateEmission'  => date('d/m/Y', strtotime($profileData['created_at'] ?? 'now')),
        'photoUri'      => $profileData['photo_url'] ?? null,
        
        // Spécifiques Militaire
        'matricule'     => $profileData['matricule'] ?? '',
        'grade'         => $profileData['grade'] ?? '',
        'unite'         => $profileData['unite'] ?? '',
        'garnison'      => $profileData['garnison'] ?? '',
        'empla'       => $profileData['emplacement'] ?? '',
        'tribu'       => $profileData['origineTribu'] ?? '',

        // Spécifiques Civil
        'profession'    => $profileData['profession'] ?? '',
        'adressePhysique' => $profileData['adresse_physique'] ?? '',
    ];

    http_response_code(200);
    echo json_encode([
        "status"         => "success",
        "userData"       => $formattedUserData,
        "familyMembers"  => $familyMembers
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status"  => "error",
        "message" => "Erreur SQL : " . $e->getMessage()
    ]);
}
?>
