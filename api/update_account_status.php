<?php
// api/update_account_status.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    require_once __DIR__ . '/../Config/database.php';
    $database = new Database();
    $pdo = $database->getConnection();

    // Désactiver le mode strict pour éviter les blocages sur les avertissements de troncature
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);

    $inputData = json_decode(file_get_contents("php://input"), true);
    $userId = $inputData['user_id'] ?? '';
    $nouveauStatut = strtoupper($inputData['statut'] ?? 'OK');

    if (empty($userId)) {
        echo json_encode(["status" => "error", "message" => "ID utilisateur manquant."]);
        exit();
    }

    // 1. Récupérer les informations de l'utilisateur
    $stmtUser = $pdo->prepare("
        SELECT u.id, u.email, u.username, 
               COALESCE(pc.nom, pm.nom) as nom, 
               COALESCE(pc.prenom, pm.prenom) as prenom 
        FROM users u
        LEFT JOIN profiles_civil pc ON u.id = pc.user_id
        LEFT JOIN profiles_militaire pm ON u.id = pm.user_id
        WHERE u.id = :id LIMIT 1
    ");
    $stmtUser->execute([':id' => $userId]);
    $userData = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if (!$userData) {
        echo json_encode(["status" => "error", "message" => "Utilisateur introuvable."]);
        exit();
    }

    // 2. Mettre à jour le statut dans la colonne 'statut_compte'
    $stmtUpdate = $pdo->prepare("UPDATE users SET statut_compte = :statut WHERE id = :id");
    $stmtUpdate->execute([
        ':statut' => $nouveauStatut,
        ':id' => $userId
    ]);

    // 3. Envoi du reçu par e-mail via Brevo (si configuré)
    $emailSent = false;
    $brevoApiKey = getenv('BREVO_API_KEY');

    if ($nouveauStatut === 'OK' && !empty($userData['email']) && $brevoApiKey) {
        $nomComplet = trim(($userData['prenom'] ?? '') . ' ' . ($userData['nom'] ?? ''));
        if (empty($nomComplet)) $nomComplet = $userData['username'];

        $dateActivation = date("d/m/Y à H:i");
        $refRecu = "REC-" . date("YmdHis") . "-" . rand(100, 999);

        $payload = [
            "sender" => ["name" => "Yebana-Okapi - Administration", "email" => "mabandwemarco@gmail.com"],
            "to" => [["email" => $userData['email']]],
            "subject" => "Reçu d'activation de compte - Yebana-Okapi",
            "htmlContent" => "
                <div style='font-family: Arial, sans-serif; padding: 20px; background-color: #f8fafc;'>
                    <div style='max-width: 600px; margin: auto; background: white; padding: 30px; border-radius: 8px; border: 1px solid #e2e8f0;'>
                        <h2 style='color: #1E3A8A; text-align: center; margin-bottom: 5px;'>YEBANA-OKAPI</h2>
                        <p style='text-align: center; color: #64748B; font-size: 12px; margin-top: 0;'>BORDEREAU DE PAIEMENT & ACTIVATION DE COMPTE</p>
                        <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;'>
                        
                        <p>Bonjour <strong>{$nomComplet}</strong>,</p>
                        <p>Nous vous confirmons que votre paiement a été validé par l'administration. Votre compte est désormais <strong style='color: #16a34a;'>OPÉRATIONNEL (OK)</strong>.</p>
                        
                        <div style='background-color: #f1f5f9; padding: 15px; border-radius: 6px; margin: 20px 0;'>
                            <p style='margin: 5px 0;'><strong>N° de Reçu :</strong> {$refRecu}</p>
                            <p style='margin: 5px 0;'><strong>Date d'activation :</strong> {$dateActivation}</p>
                            <p style='margin: 5px 0;'><strong>Montant perçu :</strong> 5.00 $ USD</p>
                            <p style='margin: 5px 0;'><strong>Statut :</strong> Payé & Activé</p>
                        </div>

                        <p>Vous pouvez dès à présent vous connecter et utiliser l'intégralité des fonctionnalités de l'application Yebana-Okapi.</p>
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

        $emailSent = ($httpCode === 200 || $httpCode === 201);
    }

    // Réinitialiser le mode d'erreur en Exception pour les retours propres
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo json_encode([
        "status" => "success",
        "message" => "Compte rendu opérationnel (OK) avec succès." . ($emailSent ? " Reçu e-mail envoyé." : "")
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Erreur serveur : " . $e->getMessage()
    ]);
}
?>
