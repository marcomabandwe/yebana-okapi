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

// Fonction d'envoi d'e-mail avec PHPMailer (Projet IDCONGO)
function sendPhysicalPaymentEmail($toEmail, $code) {
    // Vérification de la présence de la classe PHPMailer
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        error_log("PHPMailer introuvable sur le serveur.");
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        // Configuration Serveur SMTP
        $mail->isSMTP();
        $mail->Host       = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('SMTP_USER') ?: 'mabandwemarco@gmail.com'; 
        $mail->Password   = getenv('SMTP_PASS') ?: 'uque ssld gnxs phly'; // Mot de passe d'application Google
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = getenv('SMTP_PORT') ?: 465;
        $mail->CharSet    = 'UTF-8';

        // Destinataire & Expéditeur
        $mail->setFrom('no-reply@idcongo.cd', 'ID Congo - Recensement');
        $mail->addAddress($toEmail);

        // Contenu de l'e-mail
        $mail->isHTML(true);
        $mail->Subject = 'Instruction de paiement et validation de votre profil - ID Congo';
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif; padding: 20px; background-color: #f4f6f9;'>
                <div style='max-width: 550px; margin: auto; background: white; padding: 25px; border-radius: 8px;'>
                    <h2 style='color: #1E3A8A; text-align: center;'>Enregistrement effectué avec succès</h2>
                    <p>Bonjour,</p>
                    <p>Votre demande d'enregistrement civil a bien été enregistrée dans notre système ID Congo.</p>
                    
                    <div style='background-color: #EFF6FF; border-left: 4px solid #1E3A8A; padding: 15px; margin: 20px 0;'>
                        <p style='margin: 0; font-weight: bold; color: #1E3A8A;'>Votre Code de Référence :</p>
                        <p style='font-size: 26px; font-weight: bold; letter-spacing: 4px; color: #16A34A; margin: 10px 0 0 0;'>{$code}</p>
                    </div>

                    <h3 style='color: #B45309;'>Consignes importantes pour le paiement physique :</h3>
                    <p>Pour activer définitivement votre compte et votre profil, vous devez vous présenter physiquement au bureau muni de ce code de référence.</p>
                    
                    <ul style='background-color: #FFFBEB; padding: 15px 20px; border-radius: 6px; color: #92400E; line-height: 1.6;'>
                        <li><strong>Délai de 1 à 10 jours :</strong> Frais normaux de <strong>5.00 $ USD</strong>.</li>
                        <li><strong>Délai de 11 à 20 jours :</strong> Pénalité de retard, frais majorés à <strong>10.00 $ USD</strong>.</li>
                        <li><strong>Au-delà de 20 jours :</strong> Risque d'invalidation du dossier et d'expulsion du camp.</li>
                    </ul>

                    <p style='color: #64748B; font-size: 13px; text-align: center; margin-top: 25px;'>
                        Veuillez conserver cet e-mail et le présenter lors de votre passage au guichet.
                    </p>
                </div>
            </div>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Erreur d'envoi PHPMailer: " . $mail->ErrorInfo);
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

    // 2. Génération des références
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

    // 4. Envoi de l'e-mail PHPMailer
    $emailSent = sendPhysicalPaymentEmail($email, $codeConfirmation);

    $pdo->commit();

    http_response_code(201);
    echo json_encode([
        "status"              => "success",
        "message"             => "Instructions envoyées par e-mail.",
        "payment_id"          => $paymentId,
        "reference_operateur" => $refOperateur,
        "email"               => $email,
        "code_confirmation"   => $codeConfirmation,
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
