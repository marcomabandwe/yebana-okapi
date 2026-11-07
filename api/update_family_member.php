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

$id           = $data['id'] ?? '';
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

if (empty($id)) {
    echo json_encode(["status" => "error", "message" => "ID manquant."]);
    exit();
}

try {
    $stmt = $pdo->prepare("UPDATE family_members SET nom = :nom, prenom = :prenom, lien_parental = :lien, sexe = :sexe, date_naissance = :dn, lieu_naissance = :ln, profession = :prof, date_deces = :dd, nom_cimetiere = :cim, date_divorce = :div, photo_url = :photo WHERE id = :id");
    $stmt->execute([
        ':id' => $id, ':nom' => $nom, ':prenom' => $prenom, ':lien' => $lien, ':sexe' => $sexe,
        ':dn' => $dateNais ?: null, ':ln' => $lieuNais ?: null, ':prof' => $prof ?: null,
        ':dd' => $dateDeces ?: null, ':cim' => $cimetiere ?: null, ':div' => $dateDivorce ?: null, ':photo' => $photoUrl ?: null
    ]);
    echo json_encode(["status" => "success", "message" => "Mis à jour avec succès."]);
} catch(PDOException $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
