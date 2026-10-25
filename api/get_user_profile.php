<?php
// api/get_user_profile.php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

ini_set('display_errors', 0);
error_reporting(E_ALL);

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

    // Nettoyage rigoureux du type de profil
    $typeProfil = trim(strtoupper($user['type_profil'] ?? 'CIVIL'));
    $profileData = [];

    // 2. Récupérer le profil spécifique selon le type
    if ($typeProfil === 'MILITAIRE') {
        $stmtProfile = $pdo->prepare("SELECT * FROM profiles_militaire WHERE user_id = :user_id LIMIT 1");
        $stmtProfile->execute([':user_id' => $userId]);
        $profileData = $stmtProfile->fetch(PDO::FETCH_ASSOC) ?: [];
        $profileData['type'] = 'Militaire';
    } else {
        // Par défaut, on cherche dans civil
        $stmtProfile = $pdo->prepare("SELECT * FROM profiles_civil WHERE user_id = :user_id LIMIT 1");
        $stmtProfile->execute([':user_id' => $userId]);
        $profileData = $stmtProfile->fetch(PDO::FETCH_ASSOC) ?: [];
        $profileData['type'] = 'Civil';
    }

    // 3. Récupérer les membres de la famille
    $stmtFamily = $pdo->prepare("SELECT * FROM family_members WHERE owner_user_id = :user_id");
    $stmtFamily->execute([':user_id' => $userId]);
    $familyMembers = $stmtFamily->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // 4. Fusion sécurisée (si la table du profil est vide, on prend les infos de la table users)
    $formattedUserData = [
        'user_id'         => $user['id'],
        'username'        => $user['username'] ?? '',
        'email'           => $user['email'] ?? '',
        'telephone'       => $profileData['telephone'] ?? $user['telephone'] ?? '',
        'type'            => $profileData['type'] ?? ($typeProfil === 'MILITAIRE' ? 'Militaire' : 'Civil'),
        'nom'             => $profileData['nom'] ?? '',
        'postnom'         => $profileData['postnom'] ?? '',
        'prenom'          => $profileData['prenom'] ?? '',
        'sexe'            => $profileData['sexe'] ?? 'M',
        'dateNaissance'   => $profileData['date_naissance'] ?? '',
        'lieuNaissance'   => $profileData['lieu_naissance'] ?? '',
        'etatCivil'       => $profileData['etat_civil'] ?? 'CELIBATAIRE',
        'statut'          => $user['statut_compte'] ?? 'ACTIF',
        'dateEmission'    => date('d/m/Y', strtotime($profileData['created_at'] ?? 'now')),
        'photoUri'        => $profileData['photo_url'] ?? null,
        'photo_url'       => $profileData['photo_url'] ?? null,
        
        // Spécifiques Militaire
        'matricule'       => $profileData['matricule'] ?? '',
        'grade'           => $profileData['grade'] ?? '',
        'unite'           => $profileData['unite'] ?? '',
        'garnison'        => $profileData['garnison'] ?? '',
        'emplacement'     => $profileData['emplacement'] ?? '',
        'origineTribu'    => $profileData['origineTribu'] ?? '',

        // Spécifiques Civil
        'profession'      => $profileData['profession'] ?? '',
        'adressePhysique' => $profileData['adresse_physique'] ?? '',
    ];

    http_response_code(200);
    echo json_encode([
        "status"        => "success",
        "userData"      => $formattedUserData,
        "familyMembers" => $familyMembers
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status"  => "error",
        "message" => "Erreur SQL : " . $e->getMessage()
    ]);
}
?>
