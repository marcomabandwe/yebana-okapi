<?php
// api/process_payment.php

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

// Fonction d'envoi de l'e-mail unique pour les modalités de paiement des 5$ (SANS code de mot de passe)
function sendPhysicalPaymentEmail($toEmail, $reference) {
    $brevoApiKey = getenv('BREVO_API_KEY');
    
    if (!$brevoApiKey) {
        error_log("Clé API Brevo manquante dans les variables d'environnement.");
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
        "subject"     => "Modalités de paiement des frais d'enregistrement (5$) - Yebana-Okapi",
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

// Lecture des données POST
$inputData = file_get_contents("php://input");
$data = json_decode($inputData, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Données JSON invalides."]);
    exit();
}

$userId = trim($data['user_id'] ?? '');
$email = trim($data['email'] ?? '');
$phone = trim($data['phoneNumber'] ?? '');
$nom = trim($data['nom'] ?? ''); // Récupération propre du nom si transmis
$montant = 5.00;

if (empty($email)) {
    http_response_code(422);
    echo json_encode(["status" => "error", "message" => "Veuillez renseigner votre adresse e-mail."]);
    exit();
}

try {
    $pdo->beginTransaction();

    // 1. Gestion propre de la table users (sans valeurs parasites)
    if (empty($userId)) {
        $stmtUser = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmtUser->execute([':email' => $email]);
        $user = $stmtUser->fetch();

        if ($user) {
            $userId = $user['id'];
            // Mise à jour éventuelle du numéro de téléphone s'il est fourni
            if (!empty($phone)) {
                $stmtUpdatePhone = $pdo->prepare("UPDATE users SET telephone = :telephone WHERE id = :id");
                $stmtUpdatePhone->execute([':telephone' => $phone, ':id' => $userId]);
            }
        } else {
            $userId = generateUUID();
            
            // Génération du username : basé sur le nom ou la partie avant '@' de l'email, suivi de 3 chiffres aléatoires
            $baseName = !empty($nom) ? preg_replace('/[^a-zA-Z0-9]/', '', $nom) : explode('@', $email)[0];
            $uniqueUsername = strtolower($baseName) . '_' . rand(100, 999);

            // Insertion propre avec le champ numéro inclus
            $stmtNewUser = $pdo->prepare("INSERT INTO users (id, email, username, telephone, type_profil, statut_compte, email_verifie, created_at, updated_at) VALUES (:id, :email, :username, :telephone, 'CIVIL', 'EN_ATTENTE', 0, NOW(), NOW())");
            $stmtNewUser->execute([
                ':id'        => $userId,
                ':email'     => $email,
                ':username'  => $uniqueUsername,
                ':telephone' => $phone
            ]);
        }
    }

    // 2. Génération de la référence de paiement
    $refOperateur = "PHYS-" . date("YmdHis") . "-" . rand(1000, 9999);
    $paymentId = generateUUID();

    // 3. Enregistrement transaction
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

    // 4. Envoi de l'e-mail unique contenant uniquement les modalités et la référence
    $emailSent = sendPhysicalPaymentEmail($email, $refOperateur);

    $pdo->commit();

    http_response_code(201);
    echo json_encode([
        "status"              => "success",
        "message"             => "Instructions envoyées par e-mail.",
        "payment_id"          => $paymentId,
        "reference_operateur" => $refOperateur,
        "email"               => $email,
        "code_envoye"         => $emailSent
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
