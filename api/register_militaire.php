<?php
// api/register_militaire.php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

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

// Lecture des données reçues en JSON
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
$sexe = trim($data['sexe'] ?? '');
$grade = trim($data['grade'] ?? '');
$matricule = trim($data['matricule'] ?? '');
$unite = trim($data['unite'] ?? '');
$emplacement = trim($data['emplacement'] ?? '');
$email = trim($data['email'] ?? '');
$phone = trim($data['telephone'] ?? '');

if (empty($nom) || empty($prenom) || empty($grade) || empty($matricule)) {
    http_response_code(422);
    echo json_encode(["status" => "error", "message" => "Veuillez remplir les champs obligatoires (Nom, Prénom, Grade, Matricule)."]);
    exit();
}

try {
    $pdo->beginTransaction();

    // 1. Vérification si un utilisateur avec ce matricule ou cet e-mail existe déjà
    if (!empty($email)) {
        $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmtCheck->execute([':email' => $email]);
        if ($stmtCheck->fetch()) {
            http_response_code(409);
            echo json_encode(["status" => "error", "message" => "Un compte existe déjà avec cette adresse e-mail."]);
            $pdo->rollBack();
            exit();
        }
    }

    $userId = generateUUID();
    $baseName = !empty($nom) ? preg_replace('/[^a-zA-Z0-9]/', '', $nom) : 'militaire';
    $uniqueUsername = 'yo_mil_' . strtolower($baseName) . '_' . rand(100, 999);

    // 2. Création de l'utilisateur avec type_profil = 'MILITAIRE'
    $stmtUser = $pdo->prepare("INSERT INTO users (
        id, email, username, telephone, type_profil, statut_compte, email_verifie, created_at, updated_at
    ) VALUES (
        :id, :email, :username, :telephone, 'MILITAIRE', 'EN_ATTENTE', 0, NOW(), NOW()
    )");

    $stmtUser->execute([
        ':id'        => $userId,
        ':email'     => $email,
        ':username'  => $uniqueUsername,
        ':telephone' => $phone
    ]);

    // 3. Enregistrement des détails spécifiques dans la table 'militaires' (si existante)
    // Astuce : s'il existe une table spécifique 'militaires_details'
    $stmtDetails = $pdo->prepare("INSERT INTO militaires_details (
        user_id, nom, postnom, prenom, sexe, grade, matricule, unite, emplacement, created_at
    ) VALUES (
        :user_id, :nom, :postnom, :prenom, :sexe, :grade, :matricule, :unite, :emplacement, NOW()
    )");

    $stmtDetails->execute([
        ':user_id'     => $userId,
        ':nom'          => $nom,
        ':postnom'      => $postnom,
        ':prenom'       => $prenom,
        ':sexe'         => $sexe,
        ':grade'        => $grade,
        ':matricule'    => $matricule,
        ':unite'        => $unite,
        ':emplacement'  => $emplacement
    ]);

    $pdo->commit();

    http_response_code(201);
    echo json_encode([
        "status"   => "success",
        "message"  => "Profil militaire enregistré avec succès.",
        "user_id"  => $userId,
        "username" => $uniqueUsername
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        "status"  => "error",
        "message" => "Erreur lors de l'enregistrement : " . $e->getMessage()
    ]);
}
?>
