<?php
// api/register_civil.php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

ini_set('display_errors', 0);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Méthode non autorisée."]);
    exit();
}

require_once __DIR__ . '/../Config/database.php';

$database = new Database();
$pdo = $database->getConnection();

function convertDateToMySQL($dateStr) {
    if (empty($dateStr)) return null;
    // Si la date est déjà au format AAAA-MM-JJ
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
        return $dateStr;
    }
    // Si la date est au format JJ/MM/AAAA
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $dateStr)) {
        $parts = explode('/', $dateStr);
        return "{$parts[2]}-{$parts[1]}-{$parts[0]}";
    }
    $time = strtotime($dateStr);
    return $time ? date('Y-m-d', $time) : null;
}

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

$inputData = file_get_contents("php://input");
$data = json_decode($inputData, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Données JSON invalides."]);
    exit();
}

$nom = trim($data['nom'] ?? '');
$postnom = trim($data['postnom'] ?? '');
$prenom = trim($data['prenom'] ?? '');
$sexe = strtoupper(trim($data['sexe'] ?? 'M'));
$adresse = trim($data['emplacement'] ?? $data['adressePhysique'] ?? '');
$email = trim($data['email'] ?? '');
$telephone = trim($data['telephone'] ?? '');

if (empty($nom) || empty($prenom)) {
    http_response_code(422);
    echo json_encode(["status" => "error", "message" => "Veuillez remplir les champs obligatoires (Nom, Prénom)."]);
    exit();
}

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
$profession = trim($data['profession'] ?? '');
$photoUrl = $data['photo'] ?? $data['photo_url'] ?? null;

$conjoint = $data['conjoint'] ?? null;
$defunt = $data['defunt'] ?? null;
$divorce = $data['divorce'] ?? null;
$enfants = $data['enfants'] ?? [];

try {
    $pdo->beginTransaction();

    // 1. Gestion de l'utilisateur dans la table 'users' (Évite les doublons si l'email existe)
    if (empty($userId)) {
        $userEmail = !empty($email) ? $email : strtolower($prenom . '.' . $nom . rand(100, 999) . '@yebana.cd');
        
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $checkStmt->execute([':email' => $userEmail]);
        $existingUser = $checkStmt->fetch();

        if ($existingUser) {
            $userId = $existingUser['id'];
        } else {
            $userId = generateUUID();
            $username = 'yo_civ_' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $nom)) . '_' . rand(100, 999);
            $defaultPasswordHash = password_hash('123456', PASSWORD_BCRYPT);

            $sqlUser = "INSERT INTO users (
                id, email, username, password_hash, telephone, type_profil, statut_compte, email_verifie, created_at, updated_at
            ) VALUES (
                :id, :email, :username, :password_hash, :telephone, 'CIVIL', 'ACTIF', 0, NOW(), NOW()
            )";
            
            $stmtUser = $pdo->prepare($sqlUser);
            $stmtUser->execute([
                ':id'            => $userId,
                ':email'         => $userEmail,
                ':username'      => $username,
                ':password_hash' => $defaultPasswordHash,
                ':telephone'     => $telephone,
            ]);
        }
    }

    // 2. Insertion ou Mise à jour du profil dans 'profiles_civil'
    $checkProfile = $pdo->prepare("SELECT user_id FROM profiles_civil WHERE user_id = :user_id LIMIT 1");
    $checkProfile->execute([':user_id' => $userId]);
    $profileExists = $checkProfile->fetch();

    if ($profileExists) {
        $sqlCivil = "UPDATE profiles_civil SET 
            nom = :nom, postnom = :postnom, prenom = :prenom, sexe = :sexe, 
            date_naissance = :date_naissance, lieu_naissance = :lieu_naissance, etat_civil = :etat_civil, 
            telephone = :telephone, profession = :profession, adresse_physique = :adresse_physique, photo_url = :photo_url 
            WHERE user_id = :user_id";
    } else {
        $sqlCivil = "INSERT INTO profiles_civil (
            user_id, nom, postnom, prenom, sexe, 
            date_naissance, lieu_naissance, etat_civil, 
            telephone, profession, adresse_physique, photo_url, created_at
        ) VALUES (
            :user_id, :nom, :postnom, :prenom, :sexe, 
            :date_naissance, :lieu_naissance, :etat_civil, 
            :telephone, :profession, :adresse_physique, :photo_url, NOW()
        )";
    }

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

    // 3. Nettoyer les anciens membres de la famille pour éviter les doublons en cas de modification
    $stmtDeleteFamily = $pdo->prepare("DELETE FROM family_members WHERE owner_user_id = :user_id");
    $stmtDeleteFamily->execute([':user_id' => $userId]);

    // 4. Insertion des membres de la famille
    $stmtFamily = $pdo->prepare("INSERT INTO family_members (
        id, owner_user_id, nom, prenom, lien_parental, sexe, date_naissance, lieu_naissance, profession, date_deces, nom_cimetiere, date_divorce, photo_url, created_at
    ) VALUES (
        :id, :owner_user_id, :nom, :prenom, :lien_parental, :sexe, :date_naissance, :lieu_naissance, :profession, :date_deces, :nom_cimetiere, :date_divorce, :photo_url, NOW()
    )");

    // Conjoint
    if ($etatCivil === 'MARIE' && !empty($conjoint['conjointNom'])) {
        $stmtFamily->execute([
            ':id'            => generateUUID(),
            ':owner_user_id' => $userId,
            ':nom'           => trim($conjoint['conjointNom']),
            ':prenom'        => '',
            ':lien_parental' => 'CONJOINT',
            ':sexe'          => ($sexe === 'M') ? 'F' : 'M',
            ':date_naissance'=> convertDateToMySQL($conjoint['conjointDateNais'] ?? null),
            ':lieu_naissance'=> trim($conjoint['conjointLieuNais'] ?? ''),
            ':profession'    => null,
            ':date_deces'    => null,
            ':nom_cimetiere' => null,
            ':date_divorce'  => null,
            ':photo_url'     => null
        ]);
    }

    // Défunt
    if ($etatCivil === 'VEUF' && !empty($defunt['defuntNom'])) {
        $stmtFamily->execute([
            ':id'            => generateUUID(),
            ':owner_user_id' => $userId,
            ':nom'           => trim($defunt['defuntNom']),
            ':prenom'        => '',
            ':lien_parental' => 'DEFUNT',
            ':sexe'          => ($sexe === 'M') ? 'F' : 'M',
            ':date_naissance'=> null,
            ':lieu_naissance'=> null,
            ':profession'    => null,
            ':date_deces'    => convertDateToMySQL($defunt['defuntDateDeces'] ?? null),
            ':nom_cimetiere' => trim($defunt['cimetiereNom'] ?? ''),
            ':date_divorce'  => null,
            ':photo_url'     => null
        ]);
    }

    // Divorcé
    if ($etatCivil === 'DIVORCE' && !empty($divorce['exConjointNom'])) {
        $stmtFamily->execute([
            ':id'            => generateUUID(),
            ':owner_user_id' => $userId,
            ':nom'           => trim($divorce['exConjointNom']),
            ':prenom'        => '',
            ':lien_parental' => 'DIVORCE',
            ':sexe'          => ($sexe === 'M') ? 'F' : 'M',
            ':date_naissance'=> null,
            ':lieu_naissance'=> null,
            ':profession'    => null,
            ':date_deces'    => null,
            ':nom_cimetiere' => null,
            ':date_divorce'  => convertDateToMySQL($divorce['dateDivorce'] ?? null),
            ':photo_url'     => null
        ]);
    }

    // Enfants
    if (!empty($enfants) && is_array($enfants)) {
        foreach ($enfants as $enfant) {
            $enfantNom = trim($enfant['nomComplet'] ?? '');
            if (!empty($enfantNom)) {
                $sexeEnfant = strtoupper(trim($enfant['sexe'] ?? 'M'));
                if (!in_array($sexeEnfant, ['M', 'F'])) {
                    $sexeEnfant = 'M';
                }

                $stmtFamily->execute([
                    ':id'            => generateUUID(),
                    ':owner_user_id' => $userId,
                    ':nom'           => $enfantNom,
                    ':prenom'        => '',
                    ':lien_parental' => 'ENFANT',
                    ':sexe'          => $sexeEnfant,
                    ':date_naissance'=> convertDateToMySQL($enfant['dateNaissance'] ?? null),
                    ':lieu_naissance'=> trim($enfant['lieuNaissance'] ?? ''),
                    ':profession'    => trim($enfant['profession'] ?? ''),
                    ':date_deces'    => null,
                    ':nom_cimetiere' => null,
                    ':date_divorce'  => null,
                    ':photo_url'     => $enfant['photo'] ?? null
                ]);
            }
        }
    }

    $pdo->commit();

    http_response_code(201);
    echo json_encode([
        "status"   => "success",
        "message"  => "Profil civil et famille enregistrés avec succès.",
        "user_id"  => $userId
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        "status"  => "error",
        "message" => "Erreur SQL : " . $e->getMessage()
    ]);
}
?>
