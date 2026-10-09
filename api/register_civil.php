<?php
// api/register_civil.php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Méthode non autorisée."]);
    exit();
}

// Inclusion de la configuration BDD
require_once __DIR__ . '/../Config/database.php';

// --- CORRECTION DU NOM DE LA VARIABLE PDO ---
// On s'assure de récupérer l'objet PDO si nommé différemment dans database.php
if (!isset($pdo)) {
    if (isset($conn)) {
        $pdo = $conn;
    } elseif (isset($db)) {
        $pdo = $db;
    } else {
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "Erreur de connexion : La variable PDO n'a pas été trouvée dans Config/database.php"
        ]);
        exit();
    }
}

// Génération d'un UUID v4 pour user_id / family_member id
function generateUUID() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// Récupération des données reçues
$inputData = file_get_contents("php://input");
$data = json_decode($inputData, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Données JSON invalides."]);
    exit();
}

// Champs obligatoires
$nom = trim($data['nom'] ?? '');
$postnom = trim($data['postnom'] ?? '');
$prenom = trim($data['prenom'] ?? '');
$sexe = strtoupper(trim($data['sexe'] ?? 'M'));
$adresse = trim($data['emplacement'] ?? '');

if (empty($nom) || empty($prenom) || empty($sexe) || empty($adresse)) {
    http_response_code(422);
    echo json_encode(["status" => "error", "message" => "Veuillez remplir les champs obligatoires (Nom, Prénom, Sexe, Emplacement)."]);
    exit();
}

// Harmonisation de l'état civil pour ENUM ('CELIBATAIRE','MARIE','DIVORCE','VEUF')
$etatCivilInput = strtolower(trim($data['etatCivil'] ?? 'celibataire'));
$etatCivilMap = [
    'celibataire' => 'CELIBATAIRE',
    'marie'       => 'MARIE',
    'divorce'     => 'DIVORCE',
    'veuf'        => 'VEUF',
];
$etatCivil = $etatCivilMap[$etatCivilInput] ?? 'CELIBATAIRE';

$userId = $data['user_id'] ?? generateUUID();
$dateNaissance = convertDateToMySQL($data['dateNaissance'] ?? null);
$lieuNaissance = trim($data['lieuNaissance'] ?? '');
$telephone = trim($data['telephone'] ?? '');
$profession = trim($data['profession'] ?? '');
$photoUrl = $data['photo'] ?? null;

try {
    $pdo->beginTransaction();

    // 1. Insertion dans 'profiles_civil'
    $sqlCivil = "INSERT INTO profiles_civil (
                    user_id, nom, postnom, prenom, sexe, 
                    date_naissance, lieu_naissance, etat_civil, 
                    telephone, profession, adresse_physique, photo_url, created_at
                ) VALUES (
                    :user_id, :nom, :postnom, :prenom, :sexe, 
                    :date_naissance, :lieu_naissance, :etat_civil, 
                    :telephone, :profession, :adresse_physique, :photo_url, NOW()
                )";

    $stmtCivil = $pdo->prepare($sqlCivil);
    $stmtCivil->execute([
        ':user_id'          => $userId,
        ':nom'              => $nom,
        ':postnom'          => $postnom,
        ':prenom'           => $prenom,
        ':sexe'             => $sexe,
        ':date_naissance'   => $dateNaissance,
        ':lieu_naissance'   => $lieuNaissance,
        ':etat_civil'       => $etatCivil,
        ':telephone'        => $telephone,
        ':profession'       => $profession,
        ':adresse_physique' => $adresse,
        ':photo_url'        => $photoUrl,
    ]);

    // Requête préparée pour 'family_members'
    $sqlFamily = "INSERT INTO family_members (
                    id, owner_user_id, nom, prenom, 
                    lien_parental, sexe, date_naissance, photo_url, created_at
                ) VALUES (
                    :id, :owner_user_id, :nom, :prenom, 
                    :lien_parental, :sexe, :date_naissance, :photo_url, NOW()
                )";
    $stmtFamily = $pdo->prepare($sqlFamily);

    // 2. Insertion du Conjoint si marié(e)
    if ($etatCivil === 'MARIE' && !empty($data['conjoint']['conjointNom'])) {
        $stmtFamily->execute([
            ':id'             => generateUUID(),
            ':owner_user_id'  => $userId,
            ':nom'            => trim($data['conjoint']['conjointNom']),
            ':prenom'         => '',
            ':lien_parental'  => ($sexe === 'M') ? 'EPOUSE' : 'EPOUX',
            ':sexe'           => ($sexe === 'M') ? 'F' : 'M',
            ':date_naissance' => convertDateToMySQL($data['conjoint']['conjointDateNais'] ?? null),
            ':photo_url'      => null,
        ]);
    }

    // 3. Insertion des Enfants
    if (!empty($data['enfants']) && is_array($data['enfants'])) {
        foreach ($data['enfants'] as $enfant) {
            if (!empty($enfant['nomComplet'])) {
                $stmtFamily->execute([
                    ':id'             => generateUUID(),
                    ':owner_user_id'  => $userId,
                    ':nom'            => trim($enfant['nomComplet']),
                    ':prenom'         => '',
                    ':lien_parental'  => 'ENFANT',
                    ':sexe'           => strtoupper($enfant['sexe'] ?? 'M'),
                    ':date_naissance' => convertDateToMySQL($enfant['dateNaissance'] ?? null),
                    ':photo_url'      => $enfant['photo'] ?? null,
                ]);
            }
        }
    }

    $pdo->commit();

    http_response_code(201);
    echo json_encode([
        "status"   => "success",
        "message"  => "Profil civil et membres de la famille enregistrés avec succès.",
        "user_id"  => $userId
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        "status"  => "error",
        "message" => "Erreur lors de l'enregistrement en BDD : " . $e->getMessage()
    ]);
}
