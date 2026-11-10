<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../Config/database.php';

require_once __DIR__ . '/cors.php';
$q=trim($_GET['q']??''); if(mb_strlen($q)<3 || mb_strlen($q)>80) {http_response_code(400);echo json_encode(['status'=>'error','message'=>'Recherche de 3 à 80 caractères requise']);exit;}
try { $pdo=(new Database())->getConnection(); $term='%'.$q.'%';
$sql="SELECT DISTINCT u.id,u.type_profil, CONCAT_WS(' ',COALESCE(pc.nom,pm.nom),COALESCE(pc.postnom,pm.postnom),COALESCE(pc.prenom,pm.prenom)) AS nom_complet FROM users u LEFT JOIN profiles_civil pc ON pc.user_id=u.id LEFT JOIN profiles_militaire pm ON pm.user_id=u.id LEFT JOIN family_members fm ON fm.owner_user_id=u.id WHERE pc.nom LIKE :a OR pc.postnom LIKE :b OR pc.prenom LIKE :c OR pc.adresse_physique LIKE :d OR pm.nom LIKE :e OR pm.postnom LIKE :f OR pm.prenom LIKE :g OR pm.emplacement LIKE :h OR pm.matricule LIKE :i OR fm.nom LIKE :j OR fm.prenom LIKE :k LIMIT 20";
$st=$pdo->prepare($sql); $st->execute(array_combine(array_map(fn($x)=>':'.$x,range('a','k')),array_fill(0,11,$term)));
echo json_encode(['status'=>'success','results'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
} catch(Throwable $e) {http_response_code(500);echo json_encode(['status'=>'error','message'=>'Recherche indisponible']);}
?>
