<?php
// api/process_payment.php

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

// Fonction d'envoi de l'e-mail des modalités via Brevo API v3
function sendPhysicalPaymentEmail($toEmail, $reference) {
    $brevoApiKey = getenv('BREVO_API_KEY');

    if (!$brevoApiKey) {
        error_log("Clé API Brevo non configurée dans les variables d'environnement.");
        return false;
    }

    $payload = [
        "sender" => [
            "name"  => "Yebana-Okapi",
            "email" => "mabandwemarco@gmail.com"
        ],
        "to" => [
            [
                "email" => $toEmail
            ]
        ],
        "subject"   => "Modalités de paiement des frais d'enregistrement (5$) - Yebana-Okapi",
        "htmlContent" => "
            <div style='font-family: Arial, sans-serif; padding: 20px; background-color: #f4f6f9;'>
                <div style='max-width: 550px; margin: auto; background: white; padding: 25px; border-radius: 8px;'>
                    <h2 style='color: #1E3A8A; text-align: center;'>Yebana-Okapi - Instructions de Paiement</h2>
                    <p>Bonjour,</p>
                    <p>Votre demande d'enregistrement a bien été prise en compte. Voici votre référence de paiement :</p>

                    <div style='background-color: #EFF6FF; border-left: 4px solid #1E3A8A; padding: 15px; margin: 20px 0;'>
                        <p style='margin: 0; font-weight: bold; color: #1E3A8A;'>Référence de paiement :</p>
                        <p style='font-size: 18px; font-weight: bold; color: #0F172A; margin: 5px 0;'>{$reference}</p>
                    </div>

                    <h3 style='color: #B45309;'>Modalités et Consignes importantes :</h3>
                    <p>Pour finaliser et activer définitivement votre compte, présentez-vous au bureau muni de cette référence.</p>

                    <ul style='background-color: #FFFBEB; padding: 15px 20px; border-radius: 6px; color: #92400E; line-height: 1.6;'>
                        <li><strong>Délai (1 à 10 jours) :</strong> Frais de <strong>5.00 $ USD</strong>.</li>
                        <li><strong>Délai (11 à 20 jours) :</strong> Pénalité, frais majorés à <strong>10.00 $ USD</strong>.</li>
                        <li><strong>Au-delà de 20 jours :</strong> Risque d'invalidation du dossier.</li>
                    </ul>
                </div>
            </div>
        "
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'api-key: ' . $brevoApiKey,
        'Content-Type: application/json',
        'Accept: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($httpCode === 200 || $httpCode === 201);
}

$inputData = file_get_contents("php://input");
$data = json_decode($inputData, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Données JSON invalides."]);
    exit();
}

$userId = safe_trim($data['user_id'] ?? '');
$email = strtolower(safe_trim($data['email'] ?? ''));
$phone = safe_trim($data['phoneNumber'] ?? '');
$nom = safe_trim($data['nom'] ?? '');
$rawType = safe_trim($data['type'] ?? 'Civil');
$typeProfil = (strcasecmp($rawType, 'Militaire') === 0) ? 'MILITAIRE' : 'CIVIL';

$montant = 5.00;

if (empty($email) && empty($userId)) {
    http_response_code(422);
    echo json_encode(["status" => "error", "message" => "Identifiant ou e-mail manquant pour le paiement."]);
    exit();
}

try {
    $pdo->beginTransaction();

    // Recherche stricte de l'utilisateur existant par ID ou par Email pour NE JAMAIS créer de doublon
    $user = null;
    if (!empty($userId)) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
    }

    if (!$user && !empty($email)) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
    }

    if ($user) {
        // L'utilisateur existe déjà, on récupère son ID officiel et on met à jour ses infos
        $userId = $user['id'];
        $stmtUpdateUser = $pdo->prepare("UPDATE users SET telephone = :telephone, type_profil = :type_profil WHERE id = :id");
        $stmtUpdateUser.execute([
            ':telephone'   => $phone,
            ':type_profil' => $typeProfil,
            ':id'          => $userId
        ]);
    } else {
        // S'il n'existe vraiment nulle part, on le crée (cas rare)
        $userId = generateUUID();
        $baseName = !empty($nom) ? preg_replace('/[^a-zA-Z0-9]/', '', $nom) : 'user';
        $prefixCode = ($typeProfil === 'MILITAIRE') ? 'mil' : 'civ';
        $uniqueUsername = 'yo_' . $prefixCode . '_' . strtolower($baseName) . '_' . rand(100, 999);

        $stmtNewUser = $pdo->prepare("INSERT INTO users (id, email, username, telephone, type_profil, statut_compte, email_verifie, created_at, updated_at) VALUES (:id, :email, :username, :telephone, :type_profil, 'EN_ATTENTE', 0, NOW(), NOW())");
        $stmtNewUser->execute([
            ':id'          => $userId,
            ':email'       => $email,
            ':username'    => $uniqueUsername,
            ':telephone'   => $phone,
            ':type_profil' => $typeProfil
        ]);
    }

    // Enregistrement de la transaction de paiement
    $refOperateur = "PHYS-" . date("YmdHis") . "-" . rand(1000, 9999);
    $paymentId = generateUUID();

    $sqlPayment = "INSERT INTO payments (
                        id, user_id, montant, devise, methode_paiement, 
                        reference_operateur, statut_paiement, created_at
                    ) VALUES (
                        id, :user_id, :montant, 'USD', 'MOBILE_MONEY', 
                        :reference_operateur, 'EN_COURS', NOW()
                    )";

    // Correction mineure de syntaxe SQL pour l'ID du paiement :
    $sqlPayment = "INSERT INTO payments (
                        id, user_id, montant, devise, methode_paiement, 
                        reference_operateur, statut_paiement, created_at
                    ) VALUES (
                        :id, :user_id, :montant, 'USD', 'MOBILE_MONEY', 
                        :reference_operateur, 'EN_COURS', NOW()
                    )";

    $stmtPayment = $pdo->prepare($sqlPayment);
    $stmtPayment->execute([
        ':id'                  => $paymentId,
        ':user_id'             => $userId,
        ':montant'             => $montant,
        ':reference_operateur' => $refOperateur,
    ]);

    // Envoi de l'e-mail des modalités via Brevo
    if (!empty($email)) {
        sendPhysicalPaymentEmail($email, $refOperateur);
    }

    $pdo->commit();

    http_response_code(201);
    echo json_encode([
        "status"              => "success",
        "message"             => "Instructions envoyées et paiement enregistré avec succès.",
        "user_id"             => $userId,
        "payment_id"          => $paymentId,
        "reference_operateur" => $refOperateur,
        "email"               => $email
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        "status"  => "error",
        "message" => "Erreur lors du traitement : " . $e->getMessage()
    ]);
}
?>
