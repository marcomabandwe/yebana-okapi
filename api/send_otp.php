<?php
// api/send_otp.php (Version API Brevo)

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
$email = trim($data['email'] ?? '');

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(["status" => "error", "message" => "Adresse e-mail invalide."]);
    exit();
}

try {
    // 1. Vérifier si l'utilisateur existe
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Aucun compte trouvé avec cet e-mail."]);
        exit();
    }

    // 2. Générer le code OTP à 6 chiffres
    $otpCode = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

    // 3. Stocker temporairement le hash de l'OTP
    $stmtOtp = $pdo->prepare("UPDATE users SET password_hash = :otp WHERE email = :email");
    $stmtOtp->execute([
        ':otp'   => password_hash($otpCode, PASSWORD_BCRYPT),
        ':email' => $email
    ]);

    // 4. Envoi via l'API Brevo
    $brevoApiKey = getenv('BREVO_API_KEY');

    if (!$brevoApiKey) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Clé API Brevo non configurée sur le serveur."]);
        exit();
    }

    $payload = [
        "sender" => [
            "name"  => "Yebana-Okapi",
            "email" => "mabandwemarco@gmail.com" // Mettez ici l'e-mail validé sur votre compte Brevo
        ],
        "to" => [
            [
                "email" => $email
            ]
        ],
        "subject"     => "Code de confirmation - Yebana-Okapi",
        "htmlContent" => "
            <div style='font-family: Arial, sans-serif; padding: 20px; background-color: #f4f6f9;'>
                <div style='max-width: 550px; margin: auto; background: white; padding: 25px; border-radius: 8px;'>
                    <h2 style='color: #1E3A8A; text-align: center;'>Yebana-Okapi - Code de Confirmation</h2>
                    <p>Bonjour,</p>
                    <p>Voici votre code de confirmation pour finaliser la création de votre mot de passe :</p>
                    
                    <div style='background-color: #EFF6FF; border-left: 4px solid #1E3A8A; padding: 15px; margin: 20px 0; text-align: center;'>
                        <p style='font-size: 32px; font-weight: bold; letter-spacing: 6px; color: #16A34A; margin: 0;'>{$otpCode}</p>
                    </div>

                    <p style='color: #64748B; font-size: 13px; text-align: center;'>
                        Ce code est strictement personnel.
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
        echo json_encode([
            "status"  => "success",
            "message" => "Code de confirmation envoyé avec succès par e-mail via Brevo."
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
