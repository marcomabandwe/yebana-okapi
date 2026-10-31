<?php
// api/reset_password_request.php

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

$inputData = file_get_contents("php://input");
$data = json_decode($inputData, true);
$emailOrUser = trim($data['email'] ?? '');

if (empty($emailOrUser)) {
    http_response_code(422);
    echo json_encode(["status" => "error", "message" => "Veuillez fournir un e-mail ou un nom d'utilisateur."]);
    exit();
}

try {
    // 1. Vérifier si l'utilisateur existe (par email ou username)
    $stmt = $pdo->prepare("SELECT id, email, username FROM users WHERE email = :login OR username = :login LIMIT 1");
    $stmt->execute([':login' => $emailOrUser]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // Par sécurité, on renvoie un succès générique pour éviter l'énumération des comptes
        http_response_code(200);
        echo json_encode([
            "status"  => "success",
            "message" => "Si le compte existe, les instructions ont été envoyées."
        ]);
        exit();
    }

    $targetEmail = $user['email'];

    // 2. Générer le code de réinitialisation à 6 chiffres et une date d'expiration (15 minutes)
    $resetCode = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiry = date("Y-m-d H:i:s", strtotime("+15 minutes"));

    // 3. Stocker le code et son expiration en base de données
    $stmtUpdate = $pdo->prepare("UPDATE users SET reset_token = :token, reset_expiry = :expiry WHERE id = :id");
    $stmtUpdate->execute([
        ':token'  => $resetCode,
        ':expiry' => $expiry,
        ':id'     => $user['id']
    ]);

    // 4. Envoi de l'e-mail via l'API Brevo (exactement comme send_otp.php)
    $brevoApiKey = getenv('BREVO_API_KEY');

    if (!$brevoApiKey) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Clé API Brevo non configurée sur le serveur."]);
        exit();
    }

    $payload = [
        "sender" => [
            "name"  => "Yebana-Okapi",
            "email" => "mabandwemarco@gmail.com"
        ],
        "to" => [
            [
                "email" => $targetEmail
            ]
        ],
        "subject"     => "Réinitialisation de votre mot de passe - Yebana-Okapi",
        "htmlContent" => "
            <div style='font-family: Arial, sans-serif; padding: 20px; background-color: #f4f6f9;'>
                <div style='max-width: 550px; margin: auto; background: white; padding: 25px; border-radius: 8px;'>
                    <h2 style='color: #1E3A8A; text-align: center;'>Yebana-Okapi - Récupération</h2>
                    <p>Bonjour,</p>
                    <p>Vous avez demandé la réinitialisation de votre mot de passe. Voici votre code de confirmation :</p>
                    
                    <div style='background-color: #EFF6FF; border-left: 4px solid #1E3A8A; padding: 15px; margin: 20px 0; text-align: center;'>
                        <p style='font-size: 32px; font-weight: bold; letter-spacing: 6px; color: #16A34A; margin: 0;'>{$resetCode}</p>
                    </div>

                    <p style='color: #64748B; font-size: 13px; text-align: center;'>
                        Ce code est valide pendant 15 minutes. Si vous n'êtes pas à l'origine de cette demande,ignorez cet e-mail.
                    </p>
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

    if ($httpCode === 200 || $httpCode === 201) {
        http_response_code(200);
        echo json_encode([
            "status"  => "success",
            "message" => "Code de réinitialisation envoyé avec succès par e-mail."
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "status"  => "error",
            "message" => "Erreur Brevo (Code $httpCode) : " . $response
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status"  => "error",
        "message" => "Erreur serveur : " . $e->getMessage()
    ]);
}
?>
