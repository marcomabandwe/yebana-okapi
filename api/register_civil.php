<?php
// api/register_civil.php

// 1. En-têtes CORS universels pour Mobile & Web
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

// 2. Traitement Preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Méthode non autorisée."]);
    exit();
}

// 3. Connexion BDD via la classe Database
require_once __DIR__ . '/../Config/database.php';

$database = new Database();
$pdo = $database->getConnection();

// Conversion de date vers le format MySQL (YYYY-MM-DD)
function convertDateToMySQL($dateStr) {
    if (empty($dateStr)) return null;
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $dateStr)) {
        $parts = explode('/', $dateStr);
        return "{$parts[2]}-{$parts[1]}-{$parts[0]}";
    }
    return date('Y-m-d', strtotime($dateStr));
}

// Génération d'un UUID v4
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

// Récupération du JSON envoyé par React Native
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
$email = trim($data['email'] ?? '');

if (empty($nom) || empty($prenom) || empty($sexe) || empty($adresse)) {
    http_response_code(422);
    echo json_encode(["status" => "error", "message" => "Veuillez remplir les champs obligatoires (Nom, Prénom, Sexe, Emplacement)."]);
    exit();
}

// Mapping de l'état civil pour le type ENUM ('CELIBATAIRE','MARIE','DIVORCE','VEUF')
$etatCivilInput = strtolower(trim($data['etatCivil'] ?? 'celibataire'));
$etatCivilMap = [
    'celibataire' => 'CELIBATAIRE',
    'marie'       => 'MARIE',
    'divorce'     => 'DIVORCE',
    'veuf'        => 'VEUF',
];
$etatCivil = $etatCivilMap[$etatCivilInput] ?? 'CELIBATAIRE';

$userId = $data['user_id'] ?? null;
$dateNaissance = convertDateToMySQL($data['dateNaissance'] ?? null);
$lieuNaissance = trim($data['lieuNaissance'] ?? '');
$telephone = trim($data['telephone'] ?? '');
$profession = trim($data['profession'] ?? '');
$photoUrl = $data['photo'] ?? null;

try {
    $pdo->beginTransaction();

    // 1. Si aucun user_id n'est passé par l'app, création/récupération dans la table 'users'
    if (empty($userId)) {
        $userEmail = !empty($email) ? $email : strtolower($prenom . '.' . $nom . rand(100, 999) . '@yebana.cd');
        $username = strtolower($prenom . rand(100, 999));

        // Vérification de l'existence de l'utilisateur par email
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $checkStmt->execute([':email' => $userEmail]);
        $existingUser = $checkStmt->fetch();

        if ($existingUser) {
            $userId = $existingUser['id'];
        } else {
            $userId = generateUUID();
            $defaultPasswordHash = password_hash('123456', PASSWORD_BCRYPT);

            // Insertion correspondant aux colonnes de la table users
            $sqlUser = "INSERT INTO users (
                            id, email, username, password_hash, type_profil, statut_compte, email_verifie, created_at, updated_at
                        ) VALUES (
                            :id, :email, :username, :password_hash, 'CIVIL', 'EN_ATTENTE', 0, NOW(), NOW()
                        )";
            
            $stmtUser = $pdo->prepare($sqlUser);
            $stmtUser->execute([
                ':id'            => $userId,
                ':email'         => $userEmail,
                ':username'      => $username,
                ':password_hash' => $defaultPasswordHash,
            ]);
        }
    }

    // 2. Insertion dans 'profiles_civil'
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

    // 3. Insertion dans 'family_members'
    $sqlFamily = "INSERT INTO family_members (
                    id, owner_user_id, nom, prenom, 
                    lien_parental, sexe, date_naissance, photo_url, created_at
                ) VALUES (
                    :id, :owner_user_id, :nom, :prenom, 
                    :lien_parental, :sexe, :date_naissance, :photo_url, NOW()
                )";
    $stmtFamily = $pdo->prepare($sqlFamily);

    // Conjoint(e)
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

    // Enfants
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
