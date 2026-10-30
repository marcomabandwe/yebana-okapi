<?php
// api/reset_password_request.php
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

    if (!empty($emailOrUser)) {
        $query = "SELECT id, email FROM users WHERE username = :login OR email = :login LIMIT 0,1";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':login', $emailOrUser);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Génération d'un code aléatoire à 6 chiffres
            $resetCode = rand(100000, 999999);
            $expiry = date("Y-m-d H:i:s", strtotime("+15 minutes"));

            // Enregistrement du code en base (Assure-toi d'avoir les colonnes reset_token et reset_expiry dans ta table users)
            $updateQuery = "UPDATE users SET reset_token = :token, reset_expiry = :expiry WHERE id = :id";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->bindParam(':token', $resetCode);
            $updateStmt->bindParam(':expiry', $expiry);
            $updateStmt->bindParam(':id', $row['id']);
            $updateStmt->execute();

            // Simulation d'envoi d'e-mail (ou tu peux intégrer mail() ici)
            // mail($row['email'], "Code de réinitialisation", "Votre code est : $resetCode");

            http_response_code(200);
            echo json_encode([
                "status" => "success",
                "message" => "Code envoyé avec succès.",
                "debug_code" => $resetCode // Utile pour tester sans configurer de serveur mail immédiatement
            ]);
        } else {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Aucun utilisateur trouvé avec cet email."]);
        }
    } else {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Email requis."]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Erreur : " . $e->getMessage()]);
}
?>
