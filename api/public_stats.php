
<?php
require_once __DIR__ . '/cors.php';

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../Config/database.php';

try {
    $pdo = (new Database())->getConnection();

    // Compter les civils et les militaires
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

        if (isset($counts[$type])) {
            $counts[$type] = (int) $row['n'];
        }
    }

    // Compter les membres des familles
    $members = (int) $pdo
        ->query("SELECT COUNT(*) FROM family_members")
        ->fetchColumn();

    // Total des habitants
    $habitants = $counts['CIVIL']
               + $counts['MILITAIRE']
               + $members;

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
