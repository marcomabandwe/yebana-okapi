<?php
// Config/database.php

class Database {
    private $host;
    private $port;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    public function __construct() {
        $this->host = getenv('OKAPI_DB_HOST') ?: 'mysql-9260506-mabandwemarco-edad.d.aivencloud.com';
        $this->port = getenv('OKAPI_DB_PORT') ?: '22154';
        $this->db_name = getenv('OKAPI_DB_NAME') ?: 'idcongo';
        $this->username = getenv('OKAPI_DB_USER') ?: 'avnadmin';
        $this->password = getenv('OKAPI_DB_PASS') ?: '';
    }

    public function getConnection() {
        $this->conn = null;

        try {
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Connexion SSL sécurisée pour Aiven sans vérification stricte du fichier CA local
                PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
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
