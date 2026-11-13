
<?php

require_once __DIR__ . '/cors.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

try {
    require_once __DIR__ . '/../Config/database.php';

    $pdo = (new Database())->getConnection();

    // Nombre de civils et militaires
    $stmt = $pdo->query(
        "SELECT type_profil, COUNT(*) AS n
         FROM users
         GROUP BY type_profil"
    );

    $counts = [
        'CIVIL' => 0,
        'MILITAIRE' => 0
    ];

    foreach ($stmt as $row) {
        $type = strtoupper(trim($row['type_profil'] ?? ''));

        if (array_key_exists($type, $counts)) {
            $counts[$type] = (int) $row['n'];
        }
    }

    // Nombre de membres de famille
    $members = (int) $pdo
        ->query("SELECT COUNT(*) FROM family_members")
        ->fetchColumn();

    // Total des habitants
    $habitants = $counts['CIVIL']
               + $counts['MILITAIRE']
               + $members;

    // Reponse JSON
    echo json_encode([
        'status' => 'success',
        'civils' => $counts['CIVIL'],
        'militaires' => $counts['MILITAIRE'],
        'habitants' => $habitants
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'status' => 'error',
        'message' => 'Statistiques indisponibles'
    ]);
}
