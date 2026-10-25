<?php
// api/upload_image.php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

// Désactiver l'affichage des erreurs HTML pour protéger le format JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Dossier de destination des images (situé à la racine, au même niveau que le dossier api)
$targetDir = __DIR__ . "/../uploads/";

// Créer le dossier uploads s'il n'existe pas
if (!file_exists($targetDir)) {
    mkdir($targetDir, 0755, true);
}

if (isset($_FILES['image'])) {
    $file = $_FILES['image'];
    
    // Vérifier s'il y a une erreur de téléchargement
    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode([
            "status" => "error",
            "message" => "Erreur lors du transfert du fichier (Code: " . $file['error'] . ")"
        ]);
        exit();
    }

    // Générer un nom de fichier unique pour éviter les conflits
    $fileName = time() . "_" . uniqid() . "_" . preg_replace("/[^a-zA-Z0-9\._-]/", "", basename($file['name']));
    $targetFilePath = $targetDir . $fileName;

    // URL publique accessible par l'application mobile et la base de données
    $publicUrl = "https://yebana-okapi.onrender.com/uploads/" . $fileName;

    if (move_uploaded_file($file['tmp_name'], $targetFilePath)) {
        echo json_encode([
            "status" => "success",
            "url" => $publicUrl
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "Impossible de déplacer le fichier téléchargé sur le serveur."
        ]);
    }
} else {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Aucun fichier image reçu (clé 'image' manquante)."
    ]);
}
?>
