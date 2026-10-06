<?php
// api/login.php

// 1. En-têtes CORS complets (indispensables pour React Native / Expo)
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// 2. Traitement immédiat des requêtes Preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 3. Inclusion de la base de données avec la casse exacte du dossier (Config avec 'C' majuscule)
require_once __DIR__ . '/../Config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $raw_input = file_get_contents("php://input");
    $data = json_decode($raw_input);

    if (!empty($data->login) && !empty($data->password)) {
        
        $query = "SELECT id, email, username, password_hash, type_profil, statut_compte FROM users 
                  WHERE username = :login OR email = :login LIMIT 0,1";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':login', $data->login);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (password_verify($data->password, $row['password_hash'])) {
                
                $profile = null;
                if ($row['type_profil'] === 'CIVIL') {
                    $q = "SELECT * FROM profiles_civil WHERE user_id = :uid";
                } else {
                    $q = "SELECT * FROM profiles_militaire WHERE user_id = :uid";
                }
                
                $stmtProf = $db->prepare($q);
                $stmtProf->bindParam(':uid', $row['id']);
                $stmtProf->execute();
                $profile = $stmtProf->fetch(PDO::FETCH_ASSOC);

                http_response_code(200);
                echo json_encode([
                    "status" => "success",
                    "user" => [
                        "id" => $row['id'],
                        "email" => $row['email'],
                        "username" => $row['username'],
                        "typeProfil" => $row['type_profil'],
                        "statutCompte" => $row['statut_compte'],
                        "profileDetails" => $profile
                    ]
                ]);
            } else {
                http_response_code(401);
                echo json_encode(["status" => "error", "message" => "Mot de passe incorrect."]);
            }
        } else {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Utilisateur non trouvé."]);
        }
    } else {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Données incomplètes."]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Erreur serveur : " . $e->getMessage()]);
}
