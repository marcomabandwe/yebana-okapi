<?php
// api/login.php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$data = json_decode(file_get_contents("php://input"));

if (!empty($data->login) && !empty($data->password)) { // login = username ou email
    
    $query = "SELECT id, email, username, password_hash, type_profil, statut_compte FROM users 
              WHERE username = :login OR email = :login LIMIT 0,1";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':login', $data->login);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (password_verify($data->password, $row['password_hash'])) {
            
            // Récupération du profil détaillé
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