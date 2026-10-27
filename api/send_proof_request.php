<?php
// api/send_proof_request.php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$inputData = json_decode(file_get_contents("php://input"), true);
$toEmail = $inputData['email'] ?? '';
$username = $inputData['username'] ?? 'Utilisateur';

if (empty($toEmail)) {
    echo json_encode(["status" => "error", "message" => "E-mail manquant."]);
    exit();
}

$brevoApiKey = getenv('BREVO_API_KEY');
if (!$brevoApiKey) {
    echo json_encode(["status" => "error", "message" => "Clé Brevo non configurée."]);
    exit();
}

$payload = [
    "sender" => ["name" => "Yebana-Okapi", "email" => "mabandwemarco@gmail.com"],
    "to" => [["email" => $toEmail]],
    "subject" => "Instructions de paiement et validation de compte - Yebana-Okapi",
    "htmlContent" => "
        <div style='font-family: Arial, sans-serif; padding: 20px;'>
            <h2 style='color: #1E3A8A;'>Yebana-Okapi - Service Client</h2>
            <p>Bonjour <strong>{$username}</strong>,</p>
            <p>Merci d'avoir contacté le service client. Pour payer les frais d'enregistrement de 5$, veuillez passer au bureau administratif ou utiliser l'un de nos canaux officiels :</p>
            <ul>
                <li><strong>M-Pesa / Orange Money / Airtel Money :</strong> 0852591433</li>
                <li><strong>Equity BCDC :</strong> 399100091209778</li>
            </ul>
            <p>Si vous avez déjà payé et que votre compte n'est pas encore opérationnel (OK), veuillez envoyer la photo de la preuve d'envoi d'argent ainsi que votre <strong>référence opérateur</strong> à l'adresse : <strong>mabandwemarco@gmail.com</strong></p>
            <p>MERCI !</p>
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
    echo json_encode(["status" => "success", "message" => "E-mail envoyé avec succès."]);
} else {
    echo json_encode(["status" => "error", "message" => "Échec de l'envoi de l'e-mail."]);
}
?>
