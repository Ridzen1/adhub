-- Migration 025 — L'Addition : tickets, couverts, serveurs et paiements (Bellevue)
--
-- Depuis le 25/11/2026, la synchro L'Addition lit l'export Excel « Lignes de ventes »
-- du nouveau reporting. Il contient aussi les tickets (couverts, serveur, table, remises,
-- offerts, moyens de paiement) : ces tables les gardent pour le dashboard et la page
-- « Analyse des ventes ».
--
-- Relançable sans erreur. Après la migration, relancer la synchro sur la période voulue
-- pour remplir l'historique, par ex. :
--   php cron/caisses/laddition_sync.php 2026-11-01 2026-11-24

ALTER TABLE laddition_order_lines
    ADD COLUMN IF NOT EXISTS ticket_id  VARCHAR(64)   NULL AFTER id_ligne_vente,
    ADD COLUMN IF NOT EXISTS serveur    VARCHAR(100)  NULL AFTER nom_salle,
    ADD COLUMN IF NOT EXISTS table_nom  VARCHAR(50)   NULL AFTER serveur,
    ADD COLUMN IF NOT EXISTS remise_ttc DECIMAL(12,2) NULL AFTER table_nom,
    ADD COLUMN IF NOT EXISTS offert_ttc DECIMAL(12,2) NULL AFTER remise_ttc;

-- Un ticket (addition) par ligne ; jour = journée de service donnée par L'Addition
CREATE TABLE IF NOT EXISTS laddition_tickets (
    id_company  INT UNSIGNED  NOT NULL,
    id_ticket   VARCHAR(64)   NOT NULL,
    jour        DATE          NOT NULL,
    debut       DATETIME      NULL,
    fin         DATETIME      NULL,
    couverts    INT           NOT NULL DEFAULT 0,
    serveur     VARCHAR(100)  NULL,
    type        VARCHAR(50)   NULL,   -- Sur place, A emporter, Livraison
    salle       VARCHAR(100)  NULL,
    table_nom   VARCHAR(50)   NULL,
    total_ttc   DECIMAL(12,2) NULL,
    total_ht    DECIMAL(12,2) NULL,
    tva         DECIMAL(12,2) NULL,
    remises     DECIMAL(12,2) NULL,
    offerts     DECIMAL(12,2) NULL,
    paiements   VARCHAR(255)  NULL,   -- texte brut, ex. « CB(103,50) »
    synced_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_company, id_ticket),
    KEY idx_jour (id_company, jour)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Moyens de paiement, un par ticket et par mode
CREATE TABLE IF NOT EXISTS laddition_paiements (
    id_company  INT UNSIGNED  NOT NULL,
    id_ticket   VARCHAR(64)   NOT NULL,
    mode        VARCHAR(100)  NOT NULL,
    jour        DATE          NOT NULL,
    montant     DECIMAL(12,2) NOT NULL,
    PRIMARY KEY (id_company, id_ticket, mode),
    KEY idx_jour (id_company, jour)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
