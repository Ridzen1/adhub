-- Migration 016 — Objectifs de chiffre d'affaires des restaurants
--
-- Un objectif mensuel de CA TTC par restaurant, fixé par la direction seule.
-- Aucune saisie demandée aux équipes : la progression se calcule à partir des
-- ventes remontées par les caisses (daily_entries).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS objectifs_ca (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  mois       DATE NOT NULL COMMENT 'Premier jour du mois',
  montant    DECIMAL(12,2) NOT NULL COMMENT 'CA TTC visé sur le mois',
  fixe_par   INT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_projet_mois (project_id, mois),
  CONSTRAINT objectifs_ca_projet FOREIGN KEY (project_id) REFERENCES projects (id),
  CONSTRAINT objectifs_ca_auteur FOREIGN KEY (fixe_par) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
