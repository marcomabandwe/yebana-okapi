<?php

require_once __DIR__ . '/cors.php';
$allowedOrigins = [
    'http://localhost:8081',
    'http://localhost:19006',
    // Ajouter ici l'adresse Netlify après publication
    // 'https://yebana-okapi.netlify.app',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');


header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../Config/database.php';
try { $pdo = (new Database())->getConnection();
  $stmt=$pdo->query("SELECT type_profil, COUNT(*) AS n FROM users GROUP BY type_profil");
  $counts=['CIVIL'=>0,'MILITAIRE'=>0]; foreach($stmt as $row) $counts[$row['type_profil']]=(int)$row['n'];
  $members=(int)$pdo->query('SELECT COUNT(*) FROM family_members')->fetchColumn();
  echo json_encode(['status'=>'success','civils'=>$counts['CIVIL'],'militaires'=>$counts['MILITAIRE'],'habitants'=>array_sum($counts)+$members]);
} catch(Throwable $e) { http_response_code(500); echo json_encode(['status'=>'error','message'=>'Statistiques indisponibles']); }
