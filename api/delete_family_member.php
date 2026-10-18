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

$id = $data['id'] ?? '';
if (empty($id)) {
    echo json_encode(["status" => "error", "message" => "ID manquant."]);
    exit();
}

try {
    $stmt = $pdo->prepare("DELETE FROM family_members WHERE id = :id");
    $stmt->execute([':id' => $id]);
    echo json_encode(["status" => "success", "message" => "Membre supprimé."]);
} catch(PDOException $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
