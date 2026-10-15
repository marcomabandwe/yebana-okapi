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

// --- INCLUSION DE PHPMAILER (Compatible Composer OU Dossier Manuel) ---
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../PHPMailer/src/PHPMailer.php')) {
    require_once __DIR__ . '/../PHPMailer/src/Exception.php';
    require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/../PHPMailer/src/SMTP.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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

// Fonction d'envoi d'e-mail avec PHPMailer et les variables YE_
function sendPhysicalPaymentEmail($toEmail, $code, $reference) {
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        error_log("PHPMailer introuvable sur le serveur.");
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = getenv('YE_SMTP_HOST') ?: 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('YE_SMTP_USER') ?: 'mabandwemarco@gmail.com'; 
        $mail->Password   = getenv('YE_SMTP_PASS') ?: 'jvwy xert hmtg rxik';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = getenv('YE_SMTP_PORT') ?: 465;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom('mabandwemarco@gmail.com', 'yebana_okapi');
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Modalités de paiement et code de confirmation - Yebana-Okapi';
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif; padding: 20px; background-color: #f4f6f9;'>
                <div style='max-width: 550px; margin: auto; background: white; padding: 25px; border-radius: 8px;'>
                    <h2 style='color: #1E3A8A; text-align: center;'>Yebana-Okapi - Instructions de Paiement</h2>
                    <p>Bonjour,</p>
                    <p>Votre demande d'enregistrement a bien été prise en compte. Voici vos éléments de référence :</p>
                    
                    <div style='background-color: #EFF6FF; border-left: 4px solid #1E3A8A; padding: 15px; margin: 20px 0;'>
                        <p style='margin: 0; font-weight: bold; color: #1E3A8A;'>Référence de paiement :</p>
                        <p style='font-size: 16px; font-weight: bold; color: #0F172A; margin: 5px 0;'>{$reference}</p>
                        
                        <p style='margin: 10px 0 0 0; font-weight: bold; color: #1E3A8A;'>Votre Code de Confirmation :</p>
                        <p style='font-size: 24px; font-weight: bold; letter-spacing: 4px; color: #16A34A; margin: 5px 0 0 0;'>{$code}</p>
                    </div>

                    <h3 style='color: #B45309;'>Modalités et Consignes importantes :</h3>
                    <p>Pour finaliser et activer définitivement votre compte, présentez-vous au bureau muni de votre référence.</p>
                    
                    <ul style='background-color: #FFFBEB; padding: 15px 20px; border-radius: 6px; color: #92400E; line-height: 1.6;'>
                        <li><strong>Délai (1 à 10 jours) :</strong> Frais de <strong>5.00 $ USD</strong>.</li>
                        <li><strong>Délai (11 à 20 jours) :</strong> Pénalité, frais majorés à <strong>10.00 $ USD</strong>.</li>
                        <li><strong>Au-delà de 20 jours :</strong> Risque d'invalidation du dossier.</li>
                    </ul>
                </div>
            </div>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("ERREUR SMTP DETAILLEE : " . $mail->ErrorInfo);
        return false;
    }
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
$montant = 5.00;

if (empty($email)) {
    http_response_code(422);
    echo json_encode(["status" => "error", "message" => "Veuillez renseigner votre adresse e-mail."]);
    exit();
}

try {
    $pdo->beginTransaction();

    // 1. Récupération ou création de l'utilisateur
    if (empty($userId)) {
        $stmtUser = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmtUser->execute([':email' => $email]);
        $user = $stmtUser->fetch();

        if ($user) {
            $userId = $user['id'];
        } else {
            $userId = generateUUID();
            $stmtNewUser = $pdo->prepare("INSERT INTO users (id, email, username, password_hash, type_profil, statut_compte, email_verifie, created_at, updated_at) VALUES (:id, :email, :username, :pass, 'CIVIL', 'EN_ATTENTE', 0, NOW(), NOW())");
            $stmtNewUser->execute([
                ':id'       => $userId,
                ':email'    => $email,
                ':username' => explode('@', $email)[0],
                ':pass'     => password_hash('123456', PASSWORD_BCRYPT)
            ]);
        }
    }

    // 2. Génération des références et du code secret
    $refOperateur = "PHYS-" . date("YmdHis") . "-" . rand(1000, 9999);
    $paymentId = generateUUID();
    $codeConfirmation = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

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

    // 4. Envoi de l'e-mail via PHPMailer (avec les modalités et le code)
    $emailSent = sendPhysicalPaymentEmail($email, $codeConfirmation, $refOperateur);

    $pdo->commit();

    http_response_code(201);
    echo json_encode([
        "status"              => "success",
        "message"             => "Un e-mail contenant les modalités de paiement et le code de confirmation vient de vous être envoyé.",
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
