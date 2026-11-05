-- Migration 024 — Z quotidien L'Addition reçu par courriel (Valentino)
--
-- Chaque nuit, L'Addition envoie « Votre Z quotidien » à contact@ad-hub.fr.
-- cron/caisses/z_quotidien_mail.php lit ce courriel dans la boîte o2switch, écrit le
-- résumé du jour dans daily_entries et le détail des ventes par produit ici, pour la
-- page « Analyse des ventes » (catégories, top / flop produits, solide / liquide, TVA).
--
-- Relançable sans doublon.

CREATE TABLE IF NOT EXISTS laddition_z_ventes (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT           NOT NULL,
    jour       DATE          NOT NULL,
    categorie  VARCHAR(100)  NOT NULL,
    produit    VARCHAR(255)  NOT NULL,
    quantite   DECIMAL(10,2) NOT NULL DEFAULT 0,
    ttc        DECIMAL(12,2) NOT NULL DEFAULT 0,
    ht         DECIMAL(12,2) NOT NULL DEFAULT 0,
    recu_le    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_projet_jour (project_id, jour)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Catégories du Valentino vues dans le Z du 2026-11-04 (ventes et stock).
-- INSERT IGNORE : une catégorie déjà classée à la main garde son classement.
-- Les nouvelles catégories apparaissent dans Administration > Caisses, à classer.
INSERT IGNORE INTO caisse_familles (source, categorie, famille) VALUES
  -- Solide (cuisine)
  ('laddition', 'Entrées', 'solide'), ('laddition', 'Fromage', 'solide'),
  -- Liquide (bar et cave)
  ('laddition', 'Boissons chaudes', 'liquide'), ('laddition', 'Boissons sans alcools', 'liquide'),
  ('laddition', 'Boissons alcoolisées', 'liquide'), ('laddition', 'Digestifs', 'liquide'),
  ('laddition', 'Vins au verre', 'liquide'), ('laddition', 'Demi bouteilles', 'liquide'),
  ('laddition', 'Magnum et gros flacons', 'liquide'), ('laddition', 'Champagnes', 'liquide'),
  ('laddition', 'Coteaux champenois', 'liquide'), ('laddition', 'Rosé', 'liquide'),
  ('laddition', 'Alsace', 'liquide'),
  ('laddition', 'Bourgogne blanc', 'liquide'), ('laddition', 'Bourgogne rouge', 'liquide'),
  ('laddition', 'Bordeaux rouge', 'liquide'),
  ('laddition', 'Loire blanc', 'liquide'), ('laddition', 'Loire rouge', 'liquide'),
  ('laddition', 'Rhone blanc', 'liquide'), ('laddition', 'Rhone rouge', 'liquide'),
  ('laddition', 'Sud blanc et autres', 'liquide'), ('laddition', 'Sud et autres', 'liquide');
