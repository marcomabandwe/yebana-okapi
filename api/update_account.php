<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../Config/database.php';
$database = new Database();
$pdo = $database->getConnection();
$data = json_decode(file_get_contents("php://input"), true);

$userId          = $data['user_id'] ?? '';
$email           = $data['email'] ?? '';
$telephone       = $data['telephone'] ?? '';
$currentPassword = $data['current_password'] ?? '';
$newPassword     = $data['new_password'] ?? '';

if (empty($userId)) {
    echo json_encode(["status" => "error", "message" => "ID utilisateur manquant."]);
    exit();
}

try {
    // 1. Récupérer le mot de passe actuel de l'utilisateur
    $stmtUser = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id LIMIT 1");
    $stmtUser->execute([':id' => $userId]);
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(["status" => "error", "message" => "Utilisateur introuvable."]);
        exit();
    }

    // 2. Si le changement de mot de passe est demandé
    if (!empty($newPassword)) {
        if (empty($currentPassword) || !password_verify($currentPassword, $user['password_hash'])) {
            echo json_encode(["status" => "error", "message" => "Le mot de passe actuel est incorrect."]);
            exit();
        }
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmtUpdatePass = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
        $stmtUpdatePass->execute([':hash' => $newHash, ':id' => $userId]);
    }

    // 3. Mettre à jour l'e-mail et le téléphone
    if (!empty($email) || !empty($telephone)) {
        $stmtUpdateInfo = $pdo->prepare("UPDATE users SET email = COALESCE(NULLIF(:email, ''), email), telephone = COALESCE(NULLIF(:tel, ''), telephone) WHERE id = :id");
        $stmtUpdateInfo->execute([':email' => $email, ':tel' => $telephone, ':id' => $userId]);
    }

    echo json_encode(["status" => "success", "message" => "Compte mis à jour avec succès."]);

} catch (PDOException $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
