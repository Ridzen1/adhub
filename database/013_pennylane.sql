-- Migration 013 — Données Pennylane importées à la main (export Excel)
-- À exécuter manuellement :
--   Get-Content database/013_pennylane.sql -Raw | docker compose exec -T db mysql -uroot -proot dashboard
--
-- Pas de connexion automatique à Pennylane : la direction exporte les données et les
-- dépose dans Administration → Import Pennylane. Chaque ligne garde son identifiant
-- Pennylane : réimporter un export plus récent met à jour sans créer de doublon.
-- Les tables ont la même forme que le fichier pennylane_import_2026.sql : ce fichier peut
-- aussi être passé tel quel dans phpMyAdmin.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS pennylane_factures (
  id BIGINT NOT NULL PRIMARY KEY,
  sens ENUM('fournisseur','client') NOT NULL,
  societe VARCHAR(100) NOT NULL,          -- nom de la société dans Pennylane
  societe_id BIGINT NULL,                 -- id Pennylane de la société (absent de l'export Excel)
  numero VARCHAR(100) NULL,
  tiers VARCHAR(255) NULL,
  libelle VARCHAR(500) NULL,
  date_facture DATE NULL,
  echeance DATE NULL,
  ht DECIMAL(14,2) NULL,
  tva DECIMAL(14,2) NULL,
  ttc DECIMAL(14,2) NULL,
  reste_a_payer DECIMAL(14,2) NULL,
  statut_paiement VARCHAR(40) NULL,
  payee TINYINT(1) NULL,
  date_paiement DATE NULL,
  avoir TINYINT(1) NULL,
  archivee TINYINT(1) NULL,
  tiers_id BIGINT NULL,
  synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_soc_date (societe_id, date_facture),
  KEY idx_societe (societe, sens, date_facture)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pennylane_transactions (
  id BIGINT NOT NULL PRIMARY KEY,
  societe VARCHAR(100) NOT NULL,
  societe_id BIGINT NULL,
  date_operation DATE NOT NULL,
  libelle VARCHAR(500) NULL,
  montant DECIMAL(14,2) NOT NULL,         -- positif = encaissement, négatif = décaissement
  devise CHAR(3) NULL,
  compte VARCHAR(100) NULL,
  tiers VARCHAR(255) NULL,
  statut VARCHAR(40) NULL,
  nb_justificatifs INT NULL,
  justificatif_requis TINYINT(1) NULL,
  synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_soc_date (societe_id, date_operation),
  KEY idx_societe (societe, date_operation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Le fichier SQL d'origine déclarait societe_id NOT NULL : si ses tables existent déjà,
-- on assouplit la colonne et on ajoute l'index par nom.
ALTER TABLE pennylane_factures MODIFY societe_id BIGINT NULL;
ALTER TABLE pennylane_transactions MODIFY societe_id BIGINT NULL;
ALTER TABLE pennylane_factures ADD INDEX IF NOT EXISTS idx_societe (societe, sens, date_facture);
ALTER TABLE pennylane_transactions ADD INDEX IF NOT EXISTS idx_societe (societe, date_operation);

-- Correspondance « nom Pennylane » → société ADHUB (réglable dans la page d'import)
CREATE TABLE IF NOT EXISTS pennylane_societes (
  societe VARCHAR(100) NOT NULL PRIMARY KEY,
  project_id INT NULL,
  FOREIGN KEY (project_id) REFERENCES projects(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Historique des imports
CREATE TABLE IF NOT EXISTS pennylane_imports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fichier VARCHAR(255) NULL,
  nb_factures INT NOT NULL DEFAULT 0,
  nb_transactions INT NOT NULL DEFAULT 0,
  donnees_du DATE NULL,
  donnees_au DATE NULL,
  user_id INT NULL,
  imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
