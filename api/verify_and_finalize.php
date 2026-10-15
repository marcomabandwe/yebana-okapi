<?php
// api/verify_and_finalize.php

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

$inputData = file_get_contents("php://input");
$data = json_decode($inputData, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Données JSON invalides."]);
    exit();
}

$email = trim($data['email'] ?? '');
$otpCode = trim($data['otpCode'] ?? '');
$password = trim($data['password'] ?? '');
$typeProfil = trim($data['typeProfil'] ?? 'CIVIL'); // Ex: CIVIL ou Militaire

if (empty($email) || empty($otpCode) || empty($password)) {
    http_response_code(422);
    echo json_encode(["status" => "error", "message" => "Tous les champs obligatoires doivent être remplis."]);
    exit();
}

try {
    $pdo->beginTransaction();

    // 1. Vérifier si l'utilisateur existe avec cet e-mail
    $stmtUser = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $stmtUser->execute([':email' => $email]);
    $user = $stmtUser->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Utilisateur introuvable avec cet e-mail."]);
        $pdo->rollBack();
        exit();
    }

    $userId = $user['id'];

    // 2. Génération du username unique (ex: YO-CIVIL-8492)
    $randomDigits = mt_rand(1000, 9999);
    $prefix = ($typeProfil === 'Militaire') ? 'MIL' : 'CIV';
    $generatedUsername = "YO-{$prefix}-{$randomDigits}";

    // Vérifier l'unicité du username au cas où
    $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE username = :username LIMIT 1");
    do {
        $stmtCheck->execute([':username' => $generatedUsername]);
        if ($stmtCheck->fetch()) {
            $randomDigits = mt_rand(1000, 9999);
            $generatedUsername = "YO-{$prefix}-{$randomDigits}";
        } else {
            break;
        }
    } while (true);

    // 3. Hashage du mot de passe
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);

    // 4. Mise à jour de l'utilisateur dans la base de données
    $stmtUpdate = $pdo->prepare("UPDATE users SET username = :username, password_hash = :password_hash, statut_compte = 'ACTIF', email_verifie = 1, updated_at = NOW() WHERE id = :id");
    $stmtUpdate->execute([
        ':username'     => $generatedUsername,
        ':password_hash' => $passwordHash,
        ':id'           => $userId
    ]);

    $pdo->commit();

    http_response_code(200);
    echo json_encode([
        "status"   => "success",
        "message"  => "Compte finalisé avec succès.",
        "username" => $generatedUsername
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        "status"  => "error",
        "message" => "Erreur serveur : " . $e->getMessage()
    ]);
}
?>
