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
$provider = strtoupper(trim($data['provider'] ?? 'MPESA'));
$montant = 5.00;
$devise = 'USD';

if (empty($userId) || empty($email) || empty($phone)) {
    http_response_code(422);
    echo json_encode([
        "status" => "error",
        "message" => "Veuillez renseigner un e-mail valide et un numéro Mobile Money."
    ]);
    exit();
}

try {
    $pdo->beginTransaction();

    // 1. Mettre à jour l'email de l'utilisateur dans 'users'
    $sqlUpdateUser = "UPDATE users SET email = :email, updated_at = NOW() WHERE id = :id";
    $stmtUser = $pdo->prepare($sqlUpdateUser);
    $stmtUser->execute([
        ':email' => $email,
        ':id'    => $userId,
    ]);

    // 2. Générer une référence opérateur fictive ou réelle
    $refOperateur = strtoupper($provider) . "-" . date("YmdHis") . "-" . rand(1000, 9999);
    $paymentId = generateUUID();

    // 3. Insérer le paiement dans la table 'payments'
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

    $pdo->commit();

    http_response_code(201);
    echo json_encode([
        "status"              => "success",
        "message"             => "Paiement effectué avec succès.",
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
        "message" => "Erreur lors du traitement du paiement : " . $e->getMessage()
    ]);
}
