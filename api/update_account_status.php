<?php
// api/update_account_status.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    require_once __DIR__ . '/../Config/database.php';
    $database = new Database();
    $pdo = $database->getConnection();

    $inputData = json_decode(file_get_contents("php://input"), true);
    $userId = $inputData['user_id'] ?? '';
    $nouveauStatut = strtoupper($inputData['statut'] ?? 'OK');

    if (empty($userId)) {
        echo json_encode(["status" => "error", "message" => "ID utilisateur manquant."]);
        exit();
    }

    $stmtUpdate = $pdo->prepare("UPDATE users SET statut_compte = :statut WHERE id = :id");
    $stmtUpdate->execute([
        ':statut' => $nouveauStatut,
        ':id' => $userId
    ]);

    echo json_encode([
        "status" => "success",
        "message" => "Compte rendu opérationnel avec succès."
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Erreur serveur : " . $e->getMessage()
    ]);
}
?>
