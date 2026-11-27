-- Migration 028 — Banques des sociétés lues dans Qalimo (comptes, soldes, opérations)
-- À exécuter une fois (phpMyAdmin en production) :
--   Get-Content database/028_qalimo_banques.sql -Raw | docker compose exec -T db mysql -uroot -proot dashboard
--
-- Le script cron/qalimo/qalimo_banques_sync.php remplit ces tables chaque matin en lisant
-- l'espace Qalimo (connexion par identifiants, lecture seule) et met à jour la trésorerie
-- (solde_bancaire) des sociétés reliées, hors restaurants.

SET NAMES utf8mb4;

-- Comptes bancaires connectés dans Qalimo (un compte = une société ADHUB)
CREATE TABLE IF NOT EXISTS qalimo_comptes (
    id            INT UNSIGNED  NOT NULL PRIMARY KEY,   -- id du compte dans Qalimo
    unique_id     VARCHAR(100)  NOT NULL,               -- identifiant porté par les opérations
    profil_id     INT UNSIGNED  NULL,                   -- profil bailleur Qalimo (SCI…)
    project_id    INT           NULL,                   -- société ADHUB (NULL = non reliée)
    banque        VARCHAR(150)  NULL,
    libelle       VARCHAR(200)  NULL,
    iban_fin      VARCHAR(4)    NULL,
    solde         DECIMAL(14,2) NULL,
    statut        VARCHAR(30)   NULL,                   -- active, inactive (session expirée), unconsented
    compte_gestion TINYINT(1)   NOT NULL DEFAULT 0,
    maj_banque    DATETIME      NULL,                   -- dernière synchro Qalimo ↔ banque
    synced_at     DATETIME      NOT NULL,               -- dernière lecture par ADHUB
    UNIQUE KEY uq_unique (unique_id),
    KEY idx_projet (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Opérations bancaires (entrées positives, sorties négatives)
CREATE TABLE IF NOT EXISTS qalimo_operations (
    id            BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    compte_uid    VARCHAR(100)  NOT NULL,
    date_operation DATE         NOT NULL,
    libelle       VARCHAR(500)  NULL,
    montant       DECIMAL(14,2) NOT NULL,
    synced_at     DATETIME      NOT NULL,
    KEY idx_compte_date (compte_uid, date_operation),
    KEY idx_date (date_operation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
