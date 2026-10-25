-- Migration 011 — Données détaillées des caisses (L'Addition et Popina)
-- À exécuter manuellement :
--   Get-Content database/011_caisses.sql -Raw | docker compose exec -T db mysql -uroot -proot dashboard
--
-- Les scripts de cron/caisses/ remplissent ces tables chaque nuit, puis reportent le
-- résumé de la journée dans daily_entries (source 'api'). Le dashboard existant n'a
-- donc rien à changer ; la page « Analyse » lit le détail ici.

-- Les noms de catégories contiennent des accents : sans cette ligne, le client mysql
-- de Windows les enregistre mal et les catégories ne sont plus reconnues.
SET NAMES utf8mb4;

-- Lignes de vente L'Addition (une ligne par article vendu)
CREATE TABLE IF NOT EXISTS laddition_order_lines (
    id_company      INT UNSIGNED  NOT NULL,
    id_ligne_vente  VARCHAR(64)   NOT NULL,
    etablissement   VARCHAR(255)  NULL,
    heure_vente     DATETIME      NULL,
    heure_vente_raw VARCHAR(40)   NULL,  -- valeur brute, au cas où le format change
    type_produit    VARCHAR(100)  NULL,
    categorie_produit VARCHAR(100) NULL,
    id_produit      VARCHAR(64)   NULL,
    nom_produit     VARCHAR(255)  NULL,
    taux_tva        DECIMAL(5,3)  NULL,  -- 0.100, 0.200, 0.055
    nom_tva         VARCHAR(20)   NULL,
    ht              DECIMAL(12,2) NULL,
    tva             DECIMAL(12,2) NULL,
    ttc             DECIMAL(12,2) NULL,
    id_parent       VARCHAR(64)   NULL,  -- ligne « Menu » parente (le menu est à 0 €, le prix est sur les plats)
    id_salle        VARCHAR(64)   NULL,
    nom_salle       VARCHAR(255)  NULL,
    synced_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_company, id_ligne_vente),
    KEY idx_heure (id_company, heure_vente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Journal des synchronisations (consultable dans « Journal des caisses »)
CREATE TABLE IF NOT EXISTS sync_runs (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source      VARCHAR(30)  NOT NULL,
    account     VARCHAR(100) NOT NULL,
    date_from   DATE         NOT NULL,
    date_to     DATE         NOT NULL,
    nb_lines    INT          NOT NULL DEFAULT 0,
    status      ENUM('ok','error') NOT NULL,
    message     TEXT         NULL,
    run_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_run (run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Popina : chaque ligne de ticket de l'export « Produits »
CREATE TABLE IF NOT EXISTS popina_lignes (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_etablissement INT UNSIGNED  NOT NULL,
    jour_caisse      DATE          NOT NULL,
    ouverture_caisse DATETIME      NULL,
    num_ligne        INT           NOT NULL,
    ticket_id        BIGINT        NOT NULL,
    ticket_date      DATETIME      NULL,
    num_fiscal       VARCHAR(30)   NULL,
    salle            VARCHAR(100)  NULL,
    table_nom        VARCHAR(30)   NULL,
    serveur          VARCHAR(100)  NULL,
    date_ligne       DATETIME      NULL,
    type_ligne       ENUM('article','paiement','rendu','total') NOT NULL,
    categorie_parent VARCHAR(100)  NULL,  -- ex. CARNÉ, SOFTS
    categorie        VARCHAR(100)  NULL,  -- ex. BOISSONS CHAUDES ; pour un paiement : « Paiement »
    nom              VARCHAR(255)  NULL,  -- produit, ou mode de paiement
    prix_unitaire    DECIMAL(12,2) NULL,
    tarif            VARCHAR(30)   NULL,  -- Normal / Menu
    taux_tva         DECIMAL(5,2)  NULL,
    quantite         DECIMAL(10,2) NULL,
    annule           TINYINT(1)    NOT NULL DEFAULT 0,
    transfere        TINYINT(1)    NOT NULL DEFAULT 0,
    brut_ttc         DECIMAL(12,2) NULL,
    offert_ttc       DECIMAL(12,2) NULL,
    net_ttc          DECIMAL(12,2) NULL,
    tva              DECIMAL(12,3) NULL,
    ht               DECIMAL(12,3) NULL,
    synced_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_jour (id_etablissement, jour_caisse),
    KEY idx_ticket (ticket_id),
    KEY idx_type (type_ligne)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Popina : Z de caisse, une ligne par ouverture de caisse
CREATE TABLE IF NOT EXISTS popina_caisses (
    id_etablissement INT UNSIGNED  NOT NULL,
    jour_caisse      DATE          NOT NULL,
    ouverture        DATETIME      NOT NULL,
    fermeture        DATETIME      NULL,
    appareil         VARCHAR(50)   NULL,
    brut_ttc         DECIMAL(12,2) NULL,
    brut_ht          DECIMAL(12,3) NULL,
    brut_tva         DECIMAL(12,3) NULL,
    reductions_ttc   DECIMAL(12,2) NULL,
    net_ttc          DECIMAL(12,2) NULL,
    net_ht           DECIMAL(12,3) NULL,
    net_tva          DECIMAL(12,3) NULL,
    pourboires       DECIMAL(12,2) NULL,
    couverts         INT           NULL,
    commandes        INT           NULL,
    mouvements       DECIMAL(12,2) NULL,
    ecart            DECIMAL(12,2) NULL,
    detail_json      LONGTEXT      NULL,
    synced_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_etablissement, ouverture),
    KEY idx_jour (id_etablissement, jour_caisse)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS popina_caisses_paiements (
    id_etablissement INT UNSIGNED  NOT NULL,
    jour_caisse      DATE          NOT NULL,
    ouverture        DATETIME      NOT NULL,
    mode             VARCHAR(100)  NOT NULL,
    montant          DECIMAL(12,2) NULL,
    PRIMARY KEY (id_etablissement, ouverture, mode),
    KEY idx_jour (id_etablissement, jour_caisse)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS popina_caisses_tva (
    id_etablissement INT UNSIGNED  NOT NULL,
    jour_caisse      DATE          NOT NULL,
    ouverture        DATETIME      NOT NULL,
    type             ENUM('vente','remise') NOT NULL,
    libelle          VARCHAR(50)   NOT NULL,
    ttc              DECIMAL(12,2) NULL,
    ht               DECIMAL(12,3) NULL,
    tva              DECIMAL(12,3) NULL,
    PRIMARY KEY (id_etablissement, ouverture, type, libelle),
    KEY idx_jour (id_etablissement, jour_caisse)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lien entre un établissement de caisse et une société ADHUB.
-- Rempli automatiquement depuis config/caisses.local.php à chaque synchro.
CREATE TABLE IF NOT EXISTS caisse_etablissements (
    source         ENUM('laddition','popina') NOT NULL,
    id_externe     INT UNSIGNED NOT NULL,
    project_id     INT NOT NULL,
    nom            VARCHAR(255) NULL,
    PRIMARY KEY (source, id_externe),
    KEY idx_project (project_id),
    FOREIGN KEY (project_id) REFERENCES projects(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Famille de chaque catégorie de caisse : solide (cuisine), liquide (bar) ou autre.
-- Sert au CA solide / liquide et au suivi du food cost. Modifiable dans
-- « Catégories de caisse » ; une catégorie inconnue apparaît en « à classer ».
CREATE TABLE IF NOT EXISTS caisse_familles (
    source     ENUM('laddition','popina') NOT NULL,
    categorie  VARCHAR(100) NOT NULL,
    famille    ENUM('solide','liquide','autre') NOT NULL,
    PRIMARY KEY (source, categorie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Proposition de départ, d'après les catégories vues dans les exports d'octobre 2026.
-- À confirmer avec les équipes : se corrige depuis la page, sans migration.
INSERT IGNORE INTO caisse_familles (source, categorie, famille) VALUES
  ('popina', 'CARNÉ', 'solide'), ('popina', 'VÉGÉ', 'solide'), ('popina', 'MARIN', 'solide'),
  ('popina', 'DESSERTS', 'solide'), ('popina', 'Suggestion', 'solide'),
  ('popina', 'CAVE', 'liquide'), ('popina', 'SOFTS', 'liquide'), ('popina', 'COCKTAILS', 'liquide'),
  ('popina', 'ALCOOLS FORTS', 'liquide'), ('popina', 'BIERES/CIDRES', 'liquide'),
  ('laddition', 'Entrée', 'solide'), ('laddition', 'Plat', 'solide'), ('laddition', 'Dessert', 'solide'),
  ('laddition', 'Boissons', 'liquide'), ('laddition', 'Boisson Chaude', 'liquide'),
  ('laddition', 'Menu', 'autre');
