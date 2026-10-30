<?php
// api/update_account_status.php

// Forcer les en-têtes CORS pour accepter toutes les origines Web (notamment Expo Snack)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Max-Age: 3600");
header("Content-Type: application/json; charset=UTF-8");

// Réponse immédiate pour la requête de pré-contrôle du navigateur (OPTIONS)
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

    // Mise à jour du statut dans la base de données
    $stmtUpdate = $pdo->prepare("UPDATE users SET statut_compte = :statut WHERE id = :id");
    $stmtUpdate->execute([
        ':statut' => $nouveauStatut,
        ':id' => $userId
    ]);

    echo json_encode([
        "status" => "success",
        "message" => "Compte rendu opérationnel (OK) avec succès."
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Erreur serveur : " . $e->getMessage()
    ]);
}
?>
