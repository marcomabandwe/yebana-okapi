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

$userId = $data['user_id'] ?? '';
$type   = strtoupper($data['type'] ?? 'CIVIL');

if (empty($userId)) {
    echo json_encode(["status" => "error", "message" => "ID utilisateur manquant."]);
    exit();
}

try {
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
            ':dn'       => $data['date_naissance'] ?: null,
            ':ln'       => $data['lieu_naissance'] ?: null,
            ':ec'       => $data['etat_civil'] ?: 'CELIBATAIRE',
            ':tel'      => $data['telephone'] ?: null,
            ':mat'      => $data['matricule'] ?: null,
            ':grade'    => $data['grade'] ?: null,
            ':unite'    => $data['unite'] ?: null,
            ':garnison' => $data['garnison'] ?: null,
            ':emp'      => $data['emplacement'] ?: null,
            ':tribu'    => $data['origineTribu'] ?: null,
            ':photo'    => $data['photo_url'] ?: null,
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
            ':dn'      => $data['date_naissance'] ?: null,
            ':ln'      => $data['lieu_naissance'] ?: null,
            ':ec'      => $data['etat_civil'] ?: 'CELIBATAIRE',
            ':tel'     => $data['telephone'] ?: null,
            ':prof'    => $data['profession'] ?: null,
            ':adresse' => $data['adresse_physique'] ?: null,
            ':photo'   => $data['photo_url'] ?: null,
        ]);
    }

    // Mise à jour synchrone du téléphone dans la table users si besoin
    if (!empty($data['telephone'])) {
        $stmtUser = $pdo->prepare("UPDATE users SET telephone = :tel WHERE id = :uid");
        $stmtUser->execute([':tel' => $data['telephone'], ':uid' => $userId]);
    }

    echo json_encode(["status" => "success", "message" => "Profil mis à jour avec succès."]);

} catch (PDOException $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
