
<?php

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../Config/database.php';

header('Content-Type: application/json; charset=UTF-8');


function getIdentity(PDO $pdo, string $userId): ?array
{
    $sql = "
        SELECT
            u.id,
            u.username,
            u.type_profil,

            CASE
                WHEN u.type_profil = 'MILITAIRE'
                THEN CONCAT_WS(' ', pm.nom, pm.postnom, pm.prenom)
                ELSE CONCAT_WS(' ', pc.nom, pc.postnom, pc.prenom)
            END AS nom_complet,

            CASE
                WHEN u.type_profil = 'MILITAIRE'
                THEN pm.photo_url
                ELSE pc.photo_url
            END AS photo_url,

            CASE
                WHEN u.type_profil = 'MILITAIRE'
                THEN pm.telephone
                ELSE pc.telephone
            END AS telephone,

            pc.adresse_physique,
            pm.emplacement

        FROM users u

        LEFT JOIN profiles_civil pc
            ON pc.user_id = u.id

        LEFT JOIN profiles_militaire pm
            ON pm.user_id = u.id

        WHERE u.id = :id

        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $userId]);

    $person = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$person) {
        return null;
    }

    $sqlFamily = "
        SELECT
            id,
            nom,
            prenom,
            CONCAT_WS(' ', nom, prenom) AS nom_complet,
            lien_parental,
            photo_url

        FROM family_members

        WHERE owner_user_id = :id

        ORDER BY id

        LIMIT 2
    ";

    $stmtFamily = $pdo->prepare($sqlFamily);
    $stmtFamily->execute(['id' => $userId]);

    $person['family_members'] =
        $stmtFamily->fetchAll(PDO::FETCH_ASSOC);

    return $person;
}
