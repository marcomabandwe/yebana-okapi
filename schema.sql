-- 1. Création et sélection de la base de données
CREATE DATABASE IF NOT EXISTS yebana_okapi_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE yebana_okapi_db;

-- --------------------------------------------------------
-- 2. Table : users (Compte de connexion & Rôle)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id VARCHAR(36) PRIMARY KEY, -- UUID v4
    email VARCHAR(150) NOT NULL UNIQUE,
    username VARCHAR(50) NOT NULL UNIQUE, -- Identifiant unique (ex: YO-MIL-8492)
    password_hash VARCHAR(255) NOT NULL,
    type_profil ENUM('CIVIL', 'MILITAIRE') NOT NULL,
    statut_compte ENUM('EN_ATTENTE', 'ACTIF', 'SUSPENDU') DEFAULT 'EN_ATTENTE',
    email_verifie BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- 3. Table : profiles_civil (Informations spécifiques aux civils)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS profiles_civil (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL UNIQUE,
    nom VARCHAR(100) NOT NULL,
    postnom VARCHAR(100),
    prenom VARCHAR(100) NOT NULL,
    sexe ENUM('M', 'F') NOT NULL,
    date_naissance DATE NOT NULL,
    lieu_naissance VARCHAR(100) NOT NULL,
    etat_civil ENUM('CELIBATAIRE', 'MARIE', 'DIVORCE', 'VEUF') NOT NULL,
    telephone VARCHAR(20) NOT NULL,
    profession VARCHAR(100),
    adresse_physique TEXT NOT NULL,
    photo_url VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- 4. Table : profiles_militaire (Informations spécifiques aux militaires)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS profiles_militaire (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL UNIQUE,
    nom VARCHAR(100) NOT NULL,
    postnom VARCHAR(100),
    prenom VARCHAR(100) NOT NULL,
    sexe ENUM('M', 'F') NOT NULL,
    date_naissance DATE NOT NULL,
    lieu_naissance VARCHAR(100) NOT NULL,
    etat_civil ENUM('CELIBATAIRE', 'MARIE', 'DIVORCE', 'VEUF') NOT NULL,
    telephone VARCHAR(20) NOT NULL,
    matricule VARCHAR(50) NOT NULL UNIQUE, -- Numéro matricule officiel
    grade VARCHAR(80) NOT NULL,
    unite VARCHAR(150) NOT NULL,
    garnison VARCHAR(150) NOT NULL,
    photo_url VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- 5. Table : family_members (Ayants droit / Famille)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS family_members (
    id VARCHAR(36) PRIMARY KEY, -- UUID v4
    owner_user_id VARCHAR(36) NOT NULL, -- Titulaire principal
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    lien_parental ENUM('EPOUX', 'EPOUSE', 'ENFANT', 'PARENT') NOT NULL,
    sexe ENUM('M', 'F') NOT NULL,
    date_naissance DATE NOT NULL,
    photo_url VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- 6. Table : payments (Transactions des frais de pass - 5$)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
    id VARCHAR(36) PRIMARY KEY, -- UUID / ID de Transaction
    user_id VARCHAR(36) NOT NULL,
    montant DECIMAL(6, 2) NOT NULL DEFAULT 5.00,
    devise VARCHAR(3) NOT NULL DEFAULT 'USD',
    methode_paiement ENUM('MOBILE_MONEY', 'CARTE_BANCAIRE') NOT NULL,
    reference_opérateur VARCHAR(100) NOT NULL, -- Réf M-Pesa, Airtel Money, Orange Money, etc.
    statut_paiement ENUM('EN_COURS', 'SUCCES', 'ECHEC') DEFAULT 'EN_COURS',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- 7. Table : qr_scans_log (Journal des contrôles sur le terrain)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS qr_scans_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scanned_user_id VARCHAR(36) NOT NULL, -- Citoyen/Militaire scanné
    scanned_by_agent_id VARCHAR(36), -- Agent de contrôle (si applicable)
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    resultat_verification ENUM('VALIDE', 'EXPIRE', 'SUSPECT') NOT NULL,
    scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (scanned_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (scanned_by_agent_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- Index d'optimisation des requêtes
-- --------------------------------------------------------
CREATE INDEX idx_users_username ON users(username);
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_militaire_matricule ON profiles_militaire(matricule);
CREATE INDEX idx_family_owner ON family_members(owner_user_id);
CREATE INDEX idx_payments_user ON payments(user_id);