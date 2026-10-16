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

$email = trim($data['email'] ?? '');
$otpCode = trim($data['otpCode'] ?? '');
$password = trim($data['password'] ?? '');
$typeProfil = trim($data['typeProfil'] ?? 'CIVIL');

if (empty($email) || empty($otpCode) || empty($password)) {
    http_response_code(422);
    echo json_encode(["status" => "error", "message" => "Veuillez remplir tous les champs."]);
    exit();
}

try {
    $pdo->beginTransaction();

    // 1. Récupérer l'utilisateur et son code OTP temporaire stocké dans password_hash
    $stmtUser = $pdo->prepare("SELECT id, password_hash FROM users WHERE email = :email LIMIT 1");
    $stmtUser->execute([':email' => $email]);
    $user = $stmtUser->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Utilisateur introuvable."]);
        $pdo->rollBack();
        exit();
    }

    // 2. Vérifier si le code OTP correspond au hash stocké
    if (!password_verify($otpCode, $user['password_hash'])) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Code de confirmation incorrect."]);
        $pdo->rollBack();
        exit();
    }

    $userId = $user['id'];

    // 3. Génération d'un username unique (ex: YO-CIVIL-8492)
    $randomDigits = mt_rand(1000, 9999);
    $prefix = ($typeProfil === 'Militaire') ? 'MIL' : 'CIV';
    $generatedUsername = "YO-{$prefix}-{$randomDigits}";

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

    // 4. Hashage définitif du nouveau mot de passe
    $finalPasswordHash = password_hash($password, PASSWORD_BCRYPT);

    // 5. Mise à jour finale du compte (Activation, email vérifié, username et vrai mot de passe)
    $stmtUpdate = $pdo->prepare("UPDATE users SET username = :username, password_hash = :password_hash, statut_compte = 'ACTIF', email_verifie = 1, updated_at = NOW() WHERE id = :id");
    $stmtUpdate->execute([
        ':username'      => $generatedUsername,
        ':password_hash' => $finalPasswordHash,
        ':id'            => $userId
    ]);

    $pdo->commit();

    http_response_code(200);
    echo json_encode([
        "status"   => "success",
        "message"  => "Compte activé avec succès.",
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
