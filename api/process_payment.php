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

// Importation de PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

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

// Fonction d'envoi du code de confirmation par Email
function sendConfirmationEmail($toEmail, $code) {
    $mail = new PHPMailer(true);

    try {
        // Configuration SMTP (Remplacez par vos identifiants SMTP)
        $mail->isSMTP();
        $mail->Host       = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('SMTP_USER') ?: 'votre.email@gmail.com';
        $mail->Password   = getenv('SMTP_PASS') ?: 'votre_mot_de_passe_application';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = getenv('SMTP_PORT') ?: 587;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom('no-reply@idcongo.cd', 'ID Congo - Recensement');
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Code de confirmation d\'enregistrement - ID Congo';
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif; padding: 20px; background-color: #f4f6f9;'>
                <div style='max-width: 500px; margin: auto; background: white; padding: 25px; border-radius: 8px;'>
                    <h2 style='color: #1E3A8A; text-align: center;'>Validation de votre inscription</h2>
                    <p>Bonjour,</p>
                    <p>Votre paiement de <strong>5.00 $ USD</strong> a été traité avec succès.</p>
                    <p>Voici votre code de confirmation à saisir dans l'application :</p>
                    <div style='text-align: center; margin: 25px 0;'>
                        <span style='font-size: 32px; font-weight: bold; letter-spacing: 5px; color: #16A34A; background: #ECFDF5; padding: 10px 20px; border-radius: 8px; border: 1px dashed #16A34A;'>
                            {$code}
                        </span>
                    </div>
                    <p style='color: #64748B; font-size: 13px;'>Ce code est valide pendant 15 minutes. Ne le partagez avec personne.</p>
                </div>
            </div>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Erreur envoi e-mail: " . $mail->ErrorInfo);
        return false;
    }
}

// Fonction pour déclencher la demande de paiement FlexPay Mobile Money
function triggerFlexPayPayment($phone, $amount, $provider, $reference) {
    $flexpayToken = getenv('FLEXPAY_TOKEN') ?: 'VOTRE_FLEXPAY_TOKEN_MARCHAND';
    $flexpayMerchant = getenv('FLEXPAY_MERCHANT') ?: 'VOTRE_MERCHANT_CODE';

    // Normalisation du numéro pour la RDC (+243)
    $phoneClean = preg_replace('/[^0-9]/', '', $phone);
    if (strpos($phoneClean, '0') === 0) {
        $phoneClean = '243' . substr($phoneClean, 1);
    }

    $payload = [
        "merchant" => $flexpayMerchant,
        "type"     => "1", // 1 pour Mobile Money
        "phone"    => $phoneClean,
        "reference"=> $reference,
        "amount"   => $amount,
        "currency" => "USD",
        "callbackUrl" => "https://yebana-okapi.onrender.com/api/flexpay_callback.php"
    ];

    $ch = curl_init("https://backend.flexpay.cd/api/rest/v1/paymentService");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer " . $flexpayToken
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

// Récupération du payload JSON
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
$provider = strtoupper(trim($data['provider'] ?? 'ORANGE'));
$montant = 5.00;
$devise = 'USD';

if (empty($email) || empty($phone)) {
    http_response_code(422);
    echo json_encode(["status" => "error", "message" => "Veuillez renseigner un e-mail et un numéro Mobile Money."]);
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
                ':id' => $userId,
                ':email' => $email,
                ':username' => explode('@', $email)[0],
                ':pass' => password_hash('123456', PASSWORD_BCRYPT)
            ]);
        }
    }

    // 2. Génération de la référence de paiement et du code à 6 chiffres
    $refOperateur = strtoupper($provider) . "-" . date("YmdHis") . "-" . rand(1000, 9999);
    $paymentId = generateUUID();
    $codeConfirmation = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

    // 3. Enregistrement du paiement dans 'payments'
    $sqlPayment = "INSERT INTO payments (
                        id, user_id, montant, devise, methode_paiement, 
                        reference_operateur, statut_paiement, created_at
                    ) VALUES (
                        :id, :user_id, :montant, :devise, 'MOBILE_MONEY', 
                        :reference_operateur, 'SUCCES', NOW()
                    )";

    $stmtPayment = $pdo->prepare($sqlPayment);
    $stmtPayment->execute([
        ':id'                  => $paymentId,
        ':user_id'             => $userId,
        ':montant'             => $montant,
        ':devise'              => $devise,
        ':reference_operateur' => $refOperateur,
    ]);

    // 4. Déclenchement FlexPay Mobile Money
    triggerFlexPayPayment($phone, $montant, $provider, $refOperateur);

    // 5. Envoi de l'e-mail avec le code de confirmation
    $emailSent = sendConfirmationEmail($email, $codeConfirmation);

    $pdo->commit();

    http_response_code(201);
    echo json_encode([
        "status"              => "success",
        "message"             => "Demande de paiement envoyée. Veuillez valider sur votre téléphone.",
        "payment_id"          => $paymentId,
        "reference_operateur" => $refOperateur,
        "email"               => $email,
        "code_envoye"         => $emailSent,
        // Optionnel : renvoyer le code pour les tests en développement local
        "code_test"           => $codeConfirmation 
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        "status"  => "error",
        "message" => "Erreur lors du traitement du paiement : " . $e->getMessage()
    ]);
}
