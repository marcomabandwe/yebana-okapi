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

// Lecture des données JSON transmises par l'application
$inputData = file_get_contents("php://input");
$data = json_decode($inputData, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Données JSON invalides."]);
    exit();
}

// Récupération des données du formulaire Militaire
$nom = trim($data['nom'] ?? '');
$postnom = trim($data['postnom'] ?? '');
$prenom = trim($data['prenom'] ?? '');
$sexe = trim($data['sexe'] ?? '');
$dateNaissance = trim($data['dateNaissance'] ?? null);
$lieuNaissance = trim($data['lieuNaissance'] ?? '');
$grade = trim($data['grade'] ?? '');
$matricule = trim($data['matricule'] ?? '');
$unite = trim($data['unite'] ?? '');
$garnison = trim($data['garnison'] ?? ''); // Optionnel si présent
$origineTribu = trim($data['origineTribu'] ?? '');
$emplacement = trim($data['emplacement'] ?? '');
$etatCivil = trim($data['etatCivil'] ?? 'celibataire');
$email = trim($data['email'] ?? '');
$telephone = trim($data['telephone'] ?? '');
$photoUrl = trim($data['photo'] ?? null);

// Famille
$conjoint = $data['conjoint'] ?? null;
$defunt = $data['defunt'] ?? null;
$divorce = $data['divorce'] ?? null;
$enfants = $data['enfants'] ?? [];

if (empty($nom) || empty($prenom) || empty($grade) || empty($matricule)) {
    http_response_code(422);
    echo json_encode(["status" => "error", "message" => "Veuillez remplir les champs obligatoires (Nom, Prénom, Grade, Matricule)."]);
    exit();
}

try {
    $pdo->beginTransaction();

    // 1. Gestion de l'utilisateur dans la table 'users'
    $userId = null;
    if (!empty($email)) {
        $stmtUserCheck = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmtUserCheck->execute([':email' => $email]);
        $existingUser = $stmtUserCheck->fetch();

        if ($existingUser) {
            $userId = $existingUser['id'];
        }
    }

    if (!$userId) {
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

    // 2. Enregistrement dans la table exacte 'profiles_militaire'
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

    // 3. Enregistrement des membres de la famille dans la table exacte 'family_members'
    $stmtFamily = $pdo->prepare("INSERT INTO family_members (
        owner_user_id, nom, prenom, lien_parental, sexe, date_naissance, photo_url, created_at
    ) VALUES (
        :owner_user_id, :nom, :prenom, :lien_parental, :sexe, :date_naissance, :photo_url, NOW()
    )");

    // Insertion du Conjoint si marié
    if ($etatCivil === 'marie' && !empty($conjoint['conjointNom'])) {
        $stmtFamily->execute([
            ':owner_user_id' => $userId,
            ':nom'           => $conjoint['conjointNom'],
            ':prenom'        => '',
            ':lien_parental' => 'CONJOINT',
            ':sexe'          => null,
            ':date_naissance'=> $conjoint['conjointDateNais'] ?? null,
            ':photo_url'     => null
        ]);
    }

    // Insertion du Défunt si veuf/veuve
    if ($etatCivil === 'veuf' && !empty($defunt['defuntNom'])) {
        $stmtFamily->execute([
            ':owner_user_id' => $userId,
            ':nom'           => $defunt['defuntNom'],
            ':prenom'        => '',
            ':lien_parental' => 'DEFUNT',
            ':sexe'          => null,
            ':date_naissance'=> null,
            ':photo_url'     => null
        ]);
    }

    // Insertion de l'ex-conjoint si divorcé
    if ($etatCivil === 'divorce' && !empty($divorce['exConjointNom'])) {
        $stmtFamily->execute([
            ':owner_user_id' => $userId,
            ':nom'           => $divorce['exConjointNom'],
            ':prenom'        => '',
            ':lien_parental' => 'DIVORCE',
            ':sexe'          => null,
            ':date_naissance'=> null,
            ':photo_url'     => null
        ]);
    }

    // Insertion des enfants
    if (!empty($enfants) && is_array($enfants)) {
        foreach ($enfants as $enfant) {
            if (!empty($enfant['nomComplet'])) {
                $stmtFamily->execute([
                    ':owner_user_id' => $userId,
                    ':nom'           => $enfant['nomComplet'],
                    ':prenom'        => '',
                    ':lien_parental' => 'ENFANT',
                    ':sexe'          => $enfant['sexe'] ?? null,
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
