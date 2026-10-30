<?php
// api/reset_password_request.php

// 1. Force l'affichage des erreurs PHP pour le diagnostic
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 2. En-têtes CORS
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 3. Inclusion de la base de données (même chemin que login.php)
require_once __DIR__ . '/../Config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $raw_input = file_get_contents("php://input");
    $data = json_decode($raw_input);

    // On accepte soit "email", soit "login" selon ce que l'écran envoie
    $emailOrUser = !empty($data->email) ? trim($data->email) : (!empty($data->login) ? trim($data->login) : '');

    if (!empty($emailOrUser)) {
        
        $query = "SELECT id, email, username FROM users WHERE username = :login OR email = :login LIMIT 0,1";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':login', $emailOrUser);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            // Tu peux insérer ici ta logique de génération de token ou d'envoi d'e-mail si besoin

            http_response_code(200);
            echo json_encode([
                "status" => "success",
                "message" => "Si le compte existe, les instructions de réinitialisation ont été envoyées."
            ]);
        } else {
            // Par sécurité, on renvoie un succès générique pour éviter l'énumération des utilisateurs
            http_response_code(200);
            echo json_encode([
                "status" => "success",
                "message" => "Si le compte existe, les instructions de réinitialisation ont été envoyées."
            ]);
        }
    } else {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Veuillez fournir un email ou un nom d'utilisateur."]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error", 
        "message" => "Erreur critique PHP : " . $e->getMessage(),
        "file" => $e->getFile(),
        "line" => $e->getLine()
    ]);
}
?>
