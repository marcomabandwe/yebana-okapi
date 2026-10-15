<?php
// api/send_otp.php

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
$pdo =$database->getConnection();

$inputData = file_get_contents("php://input");
$data = json_decode($inputData, true);

$email = trim($data['email'] ?? '');

if (empty($email) \vert{}\vert{} !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(["status" => "error", "message" => "Adresse e-mail invalide."]);
    exit();
}

try {
    // 1. Vérifier si l'utilisateur existe
    $stmt =$pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' =>$email]);
    $user =$stmt->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Aucun compte trouvé avec cet e-mail."]);
        exit();
    }

    // 2. Générer un code OTP à 6 chiffres
    $otpCode = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

    // 3. Stocker l'OTP dans la base de données (assurez-vous d'avoir une colonne otp_code ou mettez-la à jour)
    // Si la colonne n'existe pas encore dans votre table users, vous pouvez l'ajouter ou utiliser un champ existant.
    // Alternativement, on peut stocker le code directement via une mise à jour :
    $stmtUpdate =$pdo->prepare("UPDATE users SET password_hash = :otp WHERE email = :email");
    // Stockage temporaire sécurisé du code ou de son hash (pour l'exemple, on met l'OTP dans password_hash temporairement ou on le valide)
    // Astuce simple : stockons-le directement en clair ou hashé dans un champ dédié si vous en avez un, sinon on met à jour le champ de vérification.
    // Supposons qu'on stocke l'OTP en clair ou qu'on l'envoie simplement par e-mail :
    
    // Envoi de l'e-mail via Resend API
    $resendApiKey = getenv('YE_RESEND_API_KEY');$payload = [
        "from"    => "yebana_okapi <onboarding@resend.dev>",
        "to"      => [$email],
        "subject" => "Code de confirmation de votre compte - Yebana-Okapi",
        "html"    => "
            <div style='font-family: Arial, sans-serif; padding: 20px; background-color: #f4f6f9;'>
                <div style='max-width: 550px; margin: auto; background: white; padding: 25px; border-radius: 8px;'>
                    <h2 style='color: #1E3A8A; text-align: center;'>Yebana-Okapi - Code de Confirmation</h2>
                    <p>Bonjour,</p>
                    <p>Voici votre code de confirmation pour finaliser la création de votre mot de passe :</p>
                    
                    <div style='background-color: #EFF6FF; border-left: 4px solid #1E3A8A; padding: 15px; margin: 20px 0; text-align: center;'>
                        <p style='font-size: 32px; font-weight: bold; letter-spacing: 6px; color: #16A34A; margin: 0;'>{$otpCode}</p>
                    </div>

                    <p style='color: #64748B; font-size: 13px; text-align: center;'>
                        Ce code est strictement personnel. Si vous n'êtes pas à l'origine de cette demande, veuillez ignorer cet e-mail.
                    </p>
                </div>
            </div>
        "
    ];

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $resendApiKey,
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 \vert{}\vert{}$httpCode === 201) {
        // Pour les tests, on peut stocker l'OTP en session ou en base. 
        // Si vous voulez une validation stricte dans `verify_and_finalize.php`, stockez $otpCode en base.
        // Ici, pour simplifier sans bloquer, on accepte n'importe quel code à 6 chiffres ou on le sauvegarde :
        $stmtOtp =$pdo->prepare("UPDATE users SET password_hash = :otp WHERE email = :email");
        $stmtOtp->execute([':otp' => password_hash($otpCode, PASSWORD_BCRYPT), ':email' =>$email]);

        echo json_encode([
            "status" => "success",
            "message" => "Code de confirmation envoyé avec succès par e-mail."
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "Erreur lors de l'envoi de l'e-mail via Resend."
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Erreur serveur : " . $e->getMessage()
    ]);
}
?>
