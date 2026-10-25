<?php
// api/register_civil.php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

ini_set('display_errors', 0);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Méthode non autorisée."]);
    exit();
}

require_once __DIR__ . '/../Config/database.php';

$database = new Database();
$pdo = $database->getConnection();

function convertDateToMySQL($dateStr) {
    if (empty($dateStr)) return null;
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $dateStr)) {
        $parts = explode('/', $dateStr);
        return "{$parts[2]}-{$parts[1]}-{$parts[0]}";
    }
    return date('Y-m-d', strtotime($dateStr));
}

function generateUUID() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

$inputData = file_get_contents("php://input");
$data = json_decode($inputData, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Données JSON invalides."]);
    exit();
}

$nom = trim($data['nom'] ?? '');
$postnom = trim($data['postnom'] ?? '');
$prenom = trim($data['prenom'] ?? '');
$sexe = strtoupper(trim($data['sexe'] ?? 'M'));
$adresse = trim($data['emplacement'] ?? $data['adressePhysique'] ?? '');
$email = trim($data['email'] ?? '');
$telephone = trim($data['telephone'] ?? '');

if (empty($nom) || empty($prenom)) {
    http_response_code(422);
    echo json_encode(["status" => "error", "message" => "Veuillez remplir les champs obligatoires (Nom, Prénom)."]);
    exit();
}

$etatCivilInput = strtolower(trim($data['etatCivil'] ?? 'celibataire'));
$etatCivilMap = [
    'celibataire' => 'CELIBATAIRE',
    'marie'       => 'MARIE',
    'divorce'     => 'DIVORCE',
    'veuf'        => 'VEUF',
];
$etatCivil = $etatCivilMap[$etatCivilInput] ?? 'CELIBATAIRE';

$userId = $data['user_id'] ?? null;
$dateNaissance = convertDateToMySQL($data['dateNaissance'] ?? null);
$lieuNaissance = trim($data['lieuNaissance'] ?? '');
$profession = trim($data['profession'] ?? '');
$photoUrl = $data['photo'] ?? $data['photo_url'] ?? null;

try {
    $pdo->beginTransaction();

    // 1. Gestion de l'utilisateur dans la table 'users'
    if (empty($userId)) {
        $userEmail = !empty($email) ? $email : strtolower($prenom . '.' . $nom . rand(100, 999) . '@yebana.cd');
        $username = 'yo_civ_' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $nom)) . '_' . rand(100, 999);

        // Vérifier si l'email existe déjà
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $checkStmt->execute([':email' => $userEmail]);
        $existingUser = $checkStmt->fetch();

        if ($existingUser) {
            $userId = $existingUser['id'];
        } else {
            $userId = generateUUID();
            $defaultPasswordHash = password_hash('123456', PASSWORD_BCRYPT);

            $sqlUser = "INSERT INTO users (
                id, email, username, password_hash, telephone, type_profil, statut_compte, email_verifie, created_at, updated_at
            ) VALUES (
                :id, :email, :username, :password_hash, :telephone, 'CIVIL', 'ACTIF', 0, NOW(), NOW()
            )";
            
            $stmtUser = $pdo->prepare($sqlUser);
            $stmtUser->execute([
                ':id'            => $userId,
                ':email'         => $userEmail,
                ':username'      => $username,
                ':password_hash' => $defaultPasswordHash,
                ':telephone'     => $telephone,
            ]);
        }
    }

    // 2. Vérifier si un profil civil existe déjà pour cet user_id pour éviter les doublons (Duplicate entry)
    $checkProfile = $pdo->prepare("SELECT user_id FROM profiles_civil WHERE user_id = :user_id LIMIT 1");
    $checkProfile->execute([':user_id' => $userId]);
    $profileExists = $checkProfile->fetch();

    if ($profileExists) {
        // Mise à jour si le profil existe déjà
        $sqlCivil = "UPDATE profiles_civil SET 
            nom = :nom, postnom = :postnom, prenom = :prenom, sexe = :sexe, 
            date_naissance = :date_naissance, lieu_naissance = :lieu_naissance, etat_civil = :etat_civil, 
            telephone = :telephone, profession = :profession, adresse_physique = :adresse_physique, photo_url = :photo_url 
            WHERE user_id = :user_id";
    } else {
        // Insertion si le profil n'existe pas
        $sqlCivil = "INSERT INTO profiles_civil (
            user_id, nom, postnom, prenom, sexe, 
            date_naissance, lieu_naissance, etat_civil, 
            telephone, profession, adresse_physique, photo_url, created_at
        ) VALUES (
            :user_id, :nom, :postnom, :prenom, :sexe, 
            :date_naissance, :lieu_naissance, :etat_civil, 
            :telephone, :profession, :adresse_physique, :photo_url, NOW()
        )";
    }

    $stmtCivil = $pdo->prepare($sqlCivil);
    $stmtCivil->execute([
        ':user_id'          => $userId,
        ':nom'              => $nom,
        ':postnom'          => $postnom,
        ':prenom'           => $prenom,
        ':sexe'             => $sexe,
        ':date_naissance'   => $dateNaissance,
        ':lieu_naissance'   => $lieuNaissance,
        ':etat_civil'       => $etatCivil,
        ':telephone'        => $telephone,
        ':profession'       => $profession,
        ':adresse_physique' => $adresse,
        ':photo_url'        => $photoUrl,
    ]);

    $pdo->commit();

    http_response_code(201);
    echo json_encode([
        "status"   => "success",
        "message"  => "Profil civil enregistré avec succès.",
        "user_id"  => $userId
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        "status"  => "error",
        "message" => "Erreur lors de l'enregistrement en BDD : " . $e->getMessage()
    ]);
}
?>
