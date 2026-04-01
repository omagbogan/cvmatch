-- ============================================
-- CVMatch IA - Base de données MySQL complète
-- ============================================

CREATE DATABASE IF NOT EXISTS cvmatch_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cvmatch_db;

-- -----------------------------------------------
-- Table users (candidats + recruteurs + admins)
-- -----------------------------------------------
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(200) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('candidat', 'recruteur', 'admin') DEFAULT 'candidat',
    telephone VARCHAR(20) DEFAULT NULL,
    ville VARCHAR(100) DEFAULT NULL,
    entreprise VARCHAR(200) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- -----------------------------------------------
-- Table cvs (fichiers uploadés par les candidats)
-- -----------------------------------------------
CREATE TABLE cvs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    fichier_original VARCHAR(255) NOT NULL,
    fichier_stocke VARCHAR(255) NOT NULL,
    type_fichier VARCHAR(50) NOT NULL,
    taille_fichier INT NOT NULL,
    texte_extrait LONGTEXT DEFAULT NULL,
    competences_extraites TEXT DEFAULT NULL,
    annees_experience INT DEFAULT 0,
    formation TEXT DEFAULT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- -----------------------------------------------
-- Table recherches (historique requêtes IA)
-- -----------------------------------------------
CREATE TABLE recherches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recruteur_id INT NOT NULL,
    requete TEXT NOT NULL,
    resultats_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (recruteur_id) REFERENCES users(id) ON DELETE CASCADE
);

-- -----------------------------------------------
-- Table contacts (emails simulés vers candidats)
-- -----------------------------------------------
CREATE TABLE contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recruteur_id INT NOT NULL,
    candidat_id INT NOT NULL,
    objet VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    statut ENUM('envoye', 'lu') DEFAULT 'envoye',
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (recruteur_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (candidat_id) REFERENCES users(id) ON DELETE CASCADE
);

-- -----------------------------------------------
-- Comptes de démonstration
-- Mot de passe pour tous : password123
-- -----------------------------------------------
INSERT INTO users (nom, email, password_hash, role, telephone, ville, entreprise) VALUES
('Admin CVMatch',   'admin@cvmatch.ci',     '$2y$10$u1ZGDiOp9PNkH9DYEp/bm.EE2IHQ3LkBhivZ4wYp8kGZ1PQpzFRsq', 'admin',     '+225 07 00 00 00', 'Abidjan', 'CVMatch IA'),
('Marie Recruteur', 'recruteur@cvmatch.ci', '$2y$10$u1ZGDiOp9PNkH9DYEp/bm.EE2IHQ3LkBhivZ4wYp8kGZ1PQpzFRsq', 'recruteur', '+225 07 11 22 33', 'Abidjan', 'TechCorp CI'),
('Jean Dupont',     'jean@candidat.ci',     '$2y$10$u1ZGDiOp9PNkH9DYEp/bm.EE2IHQ3LkBhivZ4wYp8kGZ1PQpzFRsq', 'candidat',  '+225 05 44 55 66', 'Abidjan', NULL),
('Fatou Koné',      'fatou@candidat.ci',    '$2y$10$u1ZGDiOp9PNkH9DYEp/bm.EE2IHQ3LkBhivZ4wYp8kGZ1PQpzFRsq', 'candidat',  '+225 07 77 88 99', 'Abidjan', NULL);
