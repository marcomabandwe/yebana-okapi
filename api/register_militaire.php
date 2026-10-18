<?php
// api/register_militaire.php

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

require_once __DIR__ . '/../Config/database.php';

$database = new Database();
$pdo = $database->getConnection();

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

function safe_trim($value) {
    return isset($value) && $value !== null ? trim((string)$value) : '';
}

$inputData = file_get_contents("php://input");
$data = json_decode($inputData, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Données JSON invalides."]);
    exit();
}

// Récupération des données du formulaire
$nom = safe_trim($data['nom'] ?? '');
$postnom = safe_trim($data['postnom'] ?? '');
$prenom = safe_trim($data['prenom'] ?? '');
$sexe = safe_trim($data['sexe'] ?? '');
$dateNaissance = isset($data['dateNaissance']) ? safe_trim($data['dateNaissance']) : null;
$lieuNaissance = safe_trim($data['lieuNaissance'] ?? '');
$grade = safe_trim($data['grade'] ?? '');
$matricule = safe_trim($data['matricule'] ?? '');
$unite = safe_trim($data['unite'] ?? '');
$garnison = safe_trim($data['garnison'] ?? '');
$origineTribu = safe_trim($data['origineTribu'] ?? '');
$emplacement = safe_trim($data['emplacement'] ?? '');
$etatCivil = safe_trim($data['etatCivil'] ?? 'celibataire');
$email = safe_trim($data['email'] ?? '');
$telephone = safe_trim($data['telephone'] ?? '');
$photoUrl = safe_trim($data['photo'] ?? null);

if (empty($nom) || empty($prenom) || empty($grade) || empty($matricule) || empty($email)) {
    http_response_code(422);
    echo json_encode(["status" => "error", "message" => "Veuillez remplir les champs obligatoires (Nom, Prénom, Grade, Matricule, Email)."]);
    exit();
}

try {
    $pdo->beginTransaction();

    // 1. Vérifier si l'utilisateur existe déjà
    $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $stmtCheck->execute([':email' => $email]);
    $existingUser = $stmtCheck->fetch();

    $userId = '';
    if ($existingUser) {
        $userId = $existingUser['id'];
        // Mettre à jour le type en MILITAIRE
        $stmtUp = $pdo->prepare("UPDATE users SET type_profil = 'MILITAIRE', telephone = :telephone WHERE id = :id");
        $stmtUp->execute([':telephone' => $telephone, ':id' => $userId]);
    } else {
        $userId = generateUUID();
        $baseName = preg_replace('/[^a-zA-Z0-9]/', '', $nom);
        $uniqueUsername = 'yo_mil_' . strtolower($baseName) . '_' . rand(100, 999);

        $stmtNewUser = $pdo->prepare("INSERT INTO users (
            id, email, username, telephone, type_profil, statut_compte, email_verifie, created_at, updated_at
        ) VALUES (
            :id, :email, :username, :telephone, 'MILITAIRE', 'EN_ATTENTE', 0, NOW(), NOW()
        )");
        $stmtNewUser->execute([
            ':id'        => $userId,
            ':email'     => $email,
            ':username'  => $uniqueUsername,
            ':telephone' => $telephone
        ]);
    }

    // 2. Enregistrement dans profiles_militaire
    $stmtCheckMil = $pdo->prepare("SELECT id FROM profiles_militaire WHERE user_id = :user_id LIMIT 1");
    $stmtCheckMil->execute([':user_id' => $userId]);

    if (!$stmtCheckMil->fetch()) {
        $stmtProfile = $pdo->prepare("INSERT INTO profiles_militaire (
            user_id, nom, postnom, prenom, sexe, date_naissance, lieu_naissance, 
            etat_civil, telephone, matricule, grade, unite, garnison, photo_url, created_at
        ) VALUES (
            :user_id, :nom, :postnom, :prenom, :sexe, :date_naissance, :lieu_naissance, 
            :etat_civil, :telephone, :matricule, :grade, :unite, :garnison, :photo_url, NOW()
        )");

        $stmtProfile->execute([
            ':user_id'        => $userId,
            ':nom'            => $nom,
            ':postnom'        => $postnom,
            ':prenom'         => $prenom,
            ':sexe'           => $sexe,
            ':date_naissance' => !empty($dateNaissance) ? $dateNaissance : null,
            ':lieu_naissance' => $lieuNaissance,
            ':etat_civil'     => $etatCivil,
            ':telephone'      => $telephone,
            ':matricule'      => $matricule,
            ':grade'          => $grade,
            ':unite'          => $unite,
            ':garnison'       => $garnison,
            ':photo_url'      => $photoUrl
        ]);
    }

    // 3. Enregistrement de la famille dans family_members
    $conjoint = $data['conjoint'] ?? null;
    $defunt = $data['defunt'] ?? null;
    $divorce = $data['divorce'] ?? null;
    $enfants = $data['enfants'] ?? [];

    $stmtFamily = $pdo->prepare("INSERT INTO family_members (
        owner_user_id, nom, prenom, lien_parental, sexe, date_naissance, photo_url, created_at
    ) VALUES (
        :owner_user_id, :nom, :prenom, :lien_parental, :sexe, :date_naissance, :photo_url, NOW()
    )");

    if ($etatCivil === 'marie' && !empty($conjoint['conjointNom'])) {
        $stmtFamily->execute([
            ':owner_user_id' => $userId,
            ':nom'           => safe_trim($conjoint['conjointNom']),
            ':prenom'        => '',
            ':lien_parental' => 'CONJOINT',
            ':sexe'          => null,
            ':date_naissance'=> isset($conjoint['conjointDateNais']) ? safe_trim($conjoint['conjointDateNais']) : null,
            ':photo_url'     => null
        ]);
    }

    if ($etatCivil === 'veuf' && !empty($defunt['defuntNom'])) {
        $stmtFamily->execute([
            ':owner_user_id' => $userId,
            ':nom'           => safe_trim($defunt['defuntNom']),
            ':prenom'        => '',
            ':lien_parental' => 'DEFUNT',
            ':sexe'          => null,
            ':date_naissance'=> null,
            ':photo_url'     => null
        ]);
    }

    if ($etatCivil === 'divorce' && !empty($divorce['exConjointNom'])) {
        $stmtFamily->execute([
            ':owner_user_id' => $userId,
            ':nom'           => safe_trim($divorce['exConjointNom']),
            ':prenom'        => '',
            ':lien_parental' => 'DIVORCE',
            ':sexe'          => null,
            ':date_naissance'=> null,
            ':photo_url'     => null
        ]);
    }

 if (!empty($enfants) && is_array($enfants)) {
            foreach ($enfants as $enfant) {
                if (!empty($enfant['nomComplet'])) {
                    // On récupère le sexe en s'assurant qu'il prend bien 'M' ou 'F', avec 'M' par défaut si vide
                    $sexeEnfant = !empty($enfant['sexe']) ? trim($enfant['sexe']) : 'M';

                    $stmtFamily->execute([
                        ':owner_user_id' => $userId,
                        ':nom'           => $enfant['nomComplet'],
                        ':prenom'        => '',
                        ':lien_parental' => 'ENFANT',
                        ':sexe'          => $sexeEnfant,
                        ':date_naissance'=> $enfant['dateNaissance'] ?? null,
                        ':photo_url'     => $enfant['photo'] ?? null
                    ]);
                }
            }
        }

    $pdo->commit();

    http_response_code(201);
    echo json_encode([
        "status"  => "success",
        "message" => "Profil militaire et famille enregistrés avec succès.",
        "user_id" => $userId
    ]);

} catch (PDOException $e) {
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
