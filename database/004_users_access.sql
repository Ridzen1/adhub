-- Migration 004 — Comptes utilisateurs et accès par projet
-- À exécuter manuellement (base déjà initialisée) :
--   Get-Content database/004_users_access.sql -Raw | docker compose exec -T db mysql -uroot -proot dashboard
-- ou via Adminer (onglet "Requête SQL").

CREATE TABLE roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(50) NOT NULL UNIQUE,
  label VARCHAR(100) NOT NULL
);

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role_id INT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (role_id) REFERENCES roles(id)
);

-- Accès explicite : un utilisateur ne voit un projet que s'il a une ligne ici.
-- Exception : le rôle 'direction' voit tout, géré au niveau du code (pas de ligne
-- à créer pour chaque nouveau projet).
CREATE TABLE project_user_access (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  user_id INT NOT NULL,
  access_level ENUM('read','write') NOT NULL DEFAULT 'read',
  scope ENUM('all','dashboard','documents') NOT NULL DEFAULT 'all',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_project_user (project_id, user_id),
  FOREIGN KEY (project_id) REFERENCES projects(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
);

INSERT INTO roles (code, label) VALUES
  ('direction',   'Direction'),
  ('associe',     'Associé'),
  ('gestionnaire','Gestionnaire'),
  ('externe',     'Externe');

-- Compte de départ. Mot de passe : Admin2026!
-- À CHANGER dès la première connexion (page Mon compte).
INSERT INTO users (name, email, password_hash, role_id) VALUES (
  'Arnaud Duvivier',
  'arnaud@example.com',
  '$2y$12$N6QspxipPkkofC0VHTYLN.Fl3xbZS2YLMMpq.rdki7rFM1/RvI9CW',
  (SELECT id FROM roles WHERE code = 'direction')
);
