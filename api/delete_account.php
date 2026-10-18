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

$userId   = $data['user_id'] ?? '';
$password = $data['password'] ?? '';

if (empty($userId) || empty($password)) {
    echo json_encode(["status" => "error", "message" => "Veuillez fournir le mot de passe pour confirmer."]);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT password_hash, type_profil FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        echo json_encode(["status" => "error", "message" => "Mot de passe incorrect."]);
        exit();
    }

    // Supprimer les données dérivées (family_members et profiles)
    $pdo->prepare("DELETE FROM family_members WHERE owner_user_id = :id")->execute([':id' => $userId]);
    if (strtoupper($user['type_profil']) === 'MILITAIRE') {
        $pdo->prepare("DELETE FROM profiles_militaire WHERE user_id = :id")->execute([':id' => $userId]);
    } else {
        $pdo->prepare("DELETE FROM profiles_civil WHERE user_id = :id")->execute([':id' => $userId]);
    }

    // Supprimer le compte principal
    $pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $userId]);

    echo json_encode(["status" => "success", "message" => "Compte supprimé avec succès."]);

} catch (PDOException $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
