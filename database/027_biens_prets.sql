-- Migration 027 — Biens immobiliers et prêts
--
-- Tables remplies par la page Administration → Biens et prêts, où la direction dépose le
-- fichier Excel « Suivi des emprunts » (feuilles Biens, Prêts, Restaurants). Aucune donnée
-- de prêt n'est écrite ici : les numéros de prêt et montants restent hors du code.
--
-- Relançable sans erreur.

SET NAMES utf8mb4;

-- Nom de société tel qu'écrit dans le fichier -> société ADHUB (relié d'office si évident, modifiable)
CREATE TABLE IF NOT EXISTS biens_societes (
  societe    VARCHAR(150) NOT NULL PRIMARY KEY,
  project_id INT NULL,
  FOREIGN KEY (project_id) REFERENCES projects(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS biens (
  ref        VARCHAR(20)  NOT NULL PRIMARY KEY,      -- B01, B02… (identifiant du fichier)
  societe    VARCHAR(150) NOT NULL,                  -- nom écrit dans le fichier, relié via biens_societes
  type_bien  VARCHAR(150) NULL,
  adresse    VARCHAR(255) NULL,
  valeur     DECIMAL(12,2) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS prets (
  ref                     VARCHAR(20)  NOT NULL PRIMARY KEY,   -- P01…, R01… (identifiant du fichier)
  bien_ref                VARCHAR(20)  NULL,
  societe                 VARCHAR(150) NOT NULL,
  a_confirmer             TINYINT(1)   NOT NULL DEFAULT 0,     -- « (à confirmer) » dans le fichier
  restaurant              VARCHAR(100) NULL,                   -- feuille Restaurants
  bien_libelle            VARCHAR(255) NULL,
  banque                  VARCHAR(150) NULL,
  numero                  VARCHAR(100) NULL,
  objet                   VARCHAR(255) NULL,
  statut                  VARCHAR(100) NULL,
  capital_ancrage         DECIMAL(12,2) NULL,
  date_ancrage            DATE NULL,
  taux                    DECIMAL(9,6) NULL,                    -- taux annuel, 0.0332 = 3,32 %
  mensualite              DECIMAL(12,2) NULL,                   -- hors assurance
  fin_differe             DATE NULL,
  mensualite_post_differe DECIMAL(12,2) NULL,
  crd_fichier             DECIMAL(12,2) NULL,                   -- capital restant dû calculé par le fichier
  date_fin                DATE NULL,
  date_fin_estimee        TINYINT(1) NOT NULL DEFAULT 0,        -- date suivie d'un « * » dans le fichier
  updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS biens_imports (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id     INT NULL,
  fichier     VARCHAR(255) NULL,
  nb_biens    INT NOT NULL DEFAULT 0,
  nb_prets    INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
