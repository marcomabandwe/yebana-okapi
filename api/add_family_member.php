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

$userId       = $data['owner_user_id'] ?? '';
$nom          = $data['nom'] ?? '';
$prenom       = $data['prenom'] ?? '';
$lien         = $data['lien_parental'] ?? 'ENFANT';
$sexe         = $data['sexe'] ?? 'M';
$dateNais     = $data['date_naissance'] ?? null;
$lieuNais     = $data['lieu_naissance'] ?? null;
$prof         = $data['profession'] ?? null;
$dateDeces    = $data['date_deces'] ?? null;
$cimetiere    = $data['nom_cimetiere'] ?? null;
$dateDivorce  = $data['date_divorce'] ?? null;
$photoUrl     = $data['photo_url'] ?? null;

if (empty($userId) || empty($nom)) {
    echo json_encode(["status" => "error", "message" => "Utilisateur ou nom manquant."]);
    exit();
}

try {
    $stmt = $pdo->prepare("INSERT INTO family_members (id, owner_user_id, nom, prenom, lien_parental, sexe, date_naissance, lieu_naissance, profession, date_deces, nom_cimetiere, date_divorce, photo_url) VALUES (UUID(), :uid, :nom, :prenom, :lien, :sexe, :dn, :ln, :prof, :dd, :cim, :div, :photo)");
    $stmt->execute([
        ':uid' => $userId, ':nom' => $nom, ':prenom' => $prenom, ':lien' => $lien, ':sexe' => $sexe,
        ':dn' => $dateNais ?: null, ':ln' => $lieuNais ?: null, ':prof' => $prof ?: null,
        ':dd' => $dateDeces ?: null, ':cim' => $cimetiere ?: null, ':div' => $dateDivorce ?: null, ':photo' => $photoUrl ?: null
    ]);
    echo json_encode(["status" => "success", "message" => "Membre ajouté avec succès."]);
} catch(PDOException $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
