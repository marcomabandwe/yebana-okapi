<?php
// config/database.php

class Database {
    // Récupération des variables d'environnement (ou valeurs par défaut)
    private $host;
    private $port;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    public function __construct() {
        $this->host = getenv('DB_HOST') ?: 'mysql-9260506-mabandwemarco-edad.d.aivencloud.com';
        $this->port = getenv('DB_PORT') ?: '22154'; // Port fourni par Aiven
        $this->db_name = getenv('DB_NAME') ?: 'idcongo';
        $this->username = getenv('DB_USER') ?: 'avnadmin';
        $this->password = getenv('DB_PASS') ?: '';
    }

    public function getConnection() {
        $this->conn = null;

        try {
            // Options pour MySQL Aiven (exige souvent SSL)
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_SSL_CA => true, // Active la vérification SSL Aiven
            ];

            $dsn = "mysql:host=" . $this->host . ";port=" . $this->port . ";dbname=" . $this->db_name . ";charset=utf8mb4";
            
            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
        } catch(PDOException $exception) {
            header("Content-Type: application/json; charset=UTF-8");
            http_response_code(500);
            echo json_encode([
                "status" => "error",
                "message" => "Erreur de connexion DB Aiven: " . $exception->getMessage()
            ]);
            exit();
        }

        return $this->conn;
    }
}
