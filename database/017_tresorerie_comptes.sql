-- Migration 017 — Soldes des comptes bancaires Pennylane
--
-- Tables remplies par la partie « Trésorerie Pennylane » du script d'export (elle les crée
-- aussi si besoin). Le solde de chaque compte devient le point de départ de la trésorerie
-- de la société : plus besoin de le saisir à la main.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS pennylane_comptes (id BIGINT NOT NULL PRIMARY KEY, societe VARCHAR(100) NOT NULL, societe_id BIGINT NOT NULL, banque VARCHAR(150) NULL, libelle VARCHAR(200) NULL, iban_fin CHAR(4) NULL, solde DECIMAL(14,2) NULL, synchronise TINYINT(1) NULL, derniere_synchro DATETIME NULL, releve_le DATE NOT NULL, KEY idx_soc (societe_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pennylane_tresorerie_mensuelle (societe_id BIGINT NOT NULL, mois CHAR(7) NOT NULL, societe VARCHAR(100) NOT NULL, solde_debut DECIMAL(14,2) NULL, encaissements DECIMAL(14,2) NULL, decaissements DECIMAL(14,2) NULL, solde_fin DECIMAL(14,2) NULL, mois_en_cours TINYINT(1) NOT NULL DEFAULT 0, releve_le DATE NOT NULL, PRIMARY KEY (societe_id, mois)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pennylane_tva (societe_id BIGINT NOT NULL, debut DATE NOT NULL, societe VARCHAR(100) NOT NULL, fin DATE NULL, montant DECIMAL(14,2) NULL, sens VARCHAR(30) NULL, frequence VARCHAR(20) NULL, releve_le DATE NOT NULL, PRIMARY KEY (societe_id, debut)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
