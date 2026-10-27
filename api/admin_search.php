<?php
// api/admin_search.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../Config/database.php';
$database = new Database();
$pdo = $database->getConnection();

$query = trim($_GET['q'] ?? '');

if (empty($query)) {
    echo json_encode(["status" => "error", "message" => "Terme de recherche manquant."]);
    exit();
}

try {
    $searchTerm = "%{$query}%";
    
    $sql = "
        SELECT DISTINCT u.id, u.email, u.username, u.telephone, u.type_profil, u.statut_compte,
               COALESCE(pc.nom, pm.nom) as nom,
               COALESCE(pc.postnom, pm.postnom) as postnom,
               COALESCE(pc.prenom, pm.prenom) as prenom
        FROM users u
        LEFT JOIN profiles_civil pc ON u.id = pc.user_id
        LEFT JOIN profiles_militaire pm ON u.id = pm.user_id
        LEFT JOIN family_members fm ON u.id = fm.owner_user_id
        WHERE u.username LIKE :q 
           OR u.email LIKE :q 
           OR u.telephone LIKE :q
           OR pc.nom LIKE :q OR pm.nom LIKE :q
           OR pc.postnom LIKE :q OR pm.postnom LIKE :q
           OR pc.prenom LIKE :q OR pm.prenom LIKE :q
           OR fm.nom LIKE :q
           OR fm.prenom LIKE :q
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':q' => $searchTerm]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "count" => count($users),
        "results" => $users
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
