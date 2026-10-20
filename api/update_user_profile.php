<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

// Empêcher l'affichage d'erreurs HTML brutes pour ne pas casser le JSON React Native
ini_set('display_errors', 0);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../Config/database.php';

try {
    $database = new Database();
    $pdo = $database->getConnection();
    $data = json_decode(file_get_contents("php://input"), true);

    $userId = $data['user_id'] ?? '';
    $type   = strtoupper($data['type'] ?? 'CIVIL');

    if (empty($userId)) {
        echo json_encode(["status" => "error", "message" => "ID utilisateur manquant."]);
        exit();
    }

    if ($type === 'MILITAIRE') {
        $stmt = $pdo->prepare("UPDATE profiles_militaire SET 
            nom = :nom, 
            postnom = :postnom, 
            prenom = :prenom, 
            sexe = :sexe, 
            date_naissance = :dn, 
            lieu_naissance = :ln, 
            etat_civil = :ec, 
            telephone = :tel, 
            matricule = :mat, 
            grade = :grade, 
            unite = :unite, 
            garnison = :garnison, 
            emplacement = :emp, 
            origineTribu = :tribu, 
            photo_url = :photo 
            WHERE user_id = :uid");

        $stmt->execute([
            ':uid'      => $userId,
            ':nom'      => $data['nom'] ?? '',
            ':postnom'  => $data['postnom'] ?? '',
            ':prenom'   => $data['prenom'] ?? '',
            ':sexe'     => $data['sexe'] ?? 'M',
            ':dn'       => !empty($data['date_naissance']) ? $data['date_naissance'] : null,
            ':ln'       => !empty($data['lieu_naissance']) ? $data['lieu_naissance'] : null,
            ':ec'       => !empty($data['etat_civil']) ? $data['etat_civil'] : 'CELIBATAIRE',
            ':tel'      => !empty($data['telephone']) ? $data['telephone'] : null,
            ':mat'      => !empty($data['matricule']) ? $data['matricule'] : null,
            ':grade'    => !empty($data['grade']) ? $data['grade'] : null,
            ':unite'    => !empty($data['unite']) ? $data['unite'] : null,
            ':garnison' => !empty($data['garnison']) ? $data['garnison'] : null,
            ':emp'      => !empty($data['emplacement']) ? $data['emplacement'] : null,
            ':tribu'    => !empty($data['origineTribu']) ? $data['origineTribu'] : null,
            ':photo'    => !empty($data['photo_url']) ? $data['photo_url'] : null,
        ]);
    } else {
        $stmt = $pdo->prepare("UPDATE profiles_civil SET 
            nom = :nom, 
            postnom = :postnom, 
            prenom = :prenom, 
            sexe = :sexe, 
            date_naissance = :dn, 
            lieu_naissance = :ln, 
            etat_civil = :ec, 
            telephone = :tel, 
            profession = :prof, 
            adresse_physique = :adresse, 
            photo_url = :photo 
            WHERE user_id = :uid");

        $stmt->execute([
            ':uid'     => $userId,
            ':nom'     => $data['nom'] ?? '',
            ':postnom' => $data['postnom'] ?? '',
            ':prenom'  => $data['prenom'] ?? '',
            ':sexe'    => $data['sexe'] ?? 'M',
            ':dn'      => !empty($data['date_naissance']) ? $data['date_naissance'] : null,
            ':ln'      => !empty($data['lieu_naissance']) ? $data['lieu_naissance'] : null,
            ':ec'      => !empty($data['etat_civil']) ? $data['etat_civil'] : 'CELIBATAIRE',
            ':tel'     => !empty($data['telephone']) ? $data['telephone'] : null,
            ':prof'    => !empty($data['profession']) ? $data['profession'] : null,
            ':adresse' => !empty($data['adresse_physique']) ? $data['adresse_physique'] : null,
            ':photo'   => !empty($data['photo_url']) ? $data['photo_url'] : null,
        ]);
    }

    // Mise à jour synchrone du numéro de téléphone principal
    if (!empty($data['telephone'])) {
        $stmtUser = $pdo->prepare("UPDATE users SET telephone = :tel WHERE id = :uid");
        $stmtUser->execute([':tel' => $data['telephone'], ':uid' => $userId]);
    }

    echo json_encode(["status" => "success", "message" => "Profil mis à jour avec succès."]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Erreur SQL : " . $e->getMessage()]);
}
?>
