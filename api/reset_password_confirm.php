<?php
// api/reset_password_confirm.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../Config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $data = json_decode(file_get_contents("php://input"));
    
    $emailOrUser = !empty($data->email) ? trim($data->email) : '';
    $code = !empty($data->code) ? trim($data->code) : '';
    $newPassword = !empty($data->new_password) ? trim($data->new_password) : '';

    if (!empty($emailOrUser) && !empty($code) && !empty($newPassword)) {
        // Vérification du code et de sa validité temporelle
        $query = "SELECT id FROM users WHERE (email = :login OR username = :login) AND reset_token = :token AND reset_expiry >= NOW() LIMIT 0,1";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':login', $emailOrUser);
        $stmt->bindParam(':token', $code);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Hashage sécurisé du nouveau mot de passe
            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);

            // Mise à jour du mot de passe et suppression du token utilisé
            $updateQuery = "UPDATE users SET password_hash = :hash, reset_token = NULL, reset_expiry = NULL WHERE id = :id";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->bindParam(':hash', $newPasswordHash);
            $updateStmt->bindParam(':id', $row['id']);
            $updateStmt->execute();

            http_response_code(200);
            echo json_encode([
                "status" => "success",
                "message" => "Mot de passe mis à jour avec succès."
            ]);
        } else {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Code incorrect ou expiré."]);
        }
    } else {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Données incomplètes."]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Erreur : " . $e->getMessage()]);
}
?>
