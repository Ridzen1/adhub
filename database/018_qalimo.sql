-- Migration 018 — Gestion locative Qalimo (biens, baux et écritures des SCI)
-- À exécuter une fois (phpMyAdmin en production) :
--   Get-Content database/018_qalimo.sql -Raw | docker compose exec -T db mysql -uroot -proot dashboard
--
-- Le script cron/qalimo/qalimo_sync.php remplit ces tables chaque nuit (lecture seule
-- côté Qalimo) et met à jour lots_total / lots_loues des sociétés reliées.

SET NAMES utf8mb4;

-- Profils bailleurs Qalimo (une SCI = un profil), reliés à une société ADHUB
CREATE TABLE IF NOT EXISTS qalimo_profils (
    id          INT UNSIGNED  NOT NULL PRIMARY KEY,
    nom         VARCHAR(200)  NOT NULL,
    societe     TINYINT(1)    NOT NULL DEFAULT 1,
    project_id  INT           NULL,
    synced_at   DATETIME      NOT NULL,
    KEY idx_projet (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Biens actifs (logements, locaux, parkings…)
CREATE TABLE IF NOT EXISTS qalimo_biens (
    id          INT UNSIGNED  NOT NULL PRIMARY KEY,
    profil_id   INT UNSIGNED  NULL,
    nom         VARCHAR(255)  NOT NULL,
    type        VARCHAR(30)   NULL,   -- habitation, commerce, commercial, parking, garage
    type_logement VARCHAR(30) NULL,   -- collectif, individuel
    ville       VARCHAR(100)  NULL,
    surface     DECIMAL(8,2)  NULL,
    pieces      SMALLINT      NULL,
    loyer_hc    DECIMAL(12,2) NULL,
    charges     DECIMAL(12,2) NULL,
    synced_at   DATETIME      NOT NULL,
    KEY idx_profil (profil_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Baux actifs
CREATE TABLE IF NOT EXISTS qalimo_baux (
    id          INT UNSIGNED  NOT NULL PRIMARY KEY,
    bien_id     INT UNSIGNED  NOT NULL,
    locataire   VARCHAR(255)  NULL,
    debut       DATE          NULL,
    fin         DATE          NULL,
    loyer_hc    DECIMAL(12,2) NULL,
    charges     DECIMAL(12,2) NULL,
    preavis     TINYINT(1)    NOT NULL DEFAULT 0,
    synced_at   DATETIME      NOT NULL,
    KEY idx_bien (bien_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Écritures des baux : appels négatifs, paiements positifs (convention Qalimo)
CREATE TABLE IF NOT EXISTS qalimo_ecritures (
    id          INT UNSIGNED  NOT NULL PRIMARY KEY,
    bail_id     INT UNSIGNED  NOT NULL,
    date_ecriture DATE        NOT NULL,
    type        VARCHAR(40)   NOT NULL,  -- appel_loyer, appel_charge, paiement, appel_dg, paiement_dg…
    montant     DECIMAL(12,2) NOT NULL,
    domaine     VARCHAR(30)   NULL,
    KEY idx_bail (bail_id, date_ecriture),
    KEY idx_date (date_ecriture)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
