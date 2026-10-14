-- Migration 005 — Documents (catégories + liens OneDrive) et journalisation des accès
-- À exécuter manuellement :
--   Get-Content database/005_documents.sql -Raw | docker compose exec -T db mysql -uroot -proot dashboard

-- Une "catégorie" = un dossier OneDrive. L'outil ne stocke aucun fichier,
-- uniquement le lien vers le dossier correspondant.
CREATE TABLE document_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  label VARCHAR(120) NOT NULL,
  onedrive_url VARCHAR(600) DEFAULT NULL,
  is_sensitive TINYINT(1) NOT NULL DEFAULT 0,   -- RH, données personnelles : accès restreint
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (project_id) REFERENCES projects(id)
);

-- Journalisation demandée en réunion : savoir qui a ouvert quel dossier et quand.
-- Note : l'outil trace l'ouverture du lien, pas le téléchargement du fichier lui-même
-- (celui-ci se passe côté OneDrive et échappe à l'application).
CREATE TABLE document_access_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NOT NULL,
  user_id INT NOT NULL,
  opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip_address VARCHAR(45) DEFAULT NULL,
  FOREIGN KEY (category_id) REFERENCES document_categories(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE INDEX idx_log_opened ON document_access_log (opened_at);
CREATE INDEX idx_cat_project ON document_categories (project_id, sort_order);

-- Catégories par défaut pour les SCI (validées en réunion)
INSERT INTO document_categories (project_id, label, sort_order)
SELECT p.id, c.label, c.ord
FROM projects p
JOIN project_types pt ON pt.id = p.project_type_id
CROSS JOIN (
  SELECT 'Juridique' AS label, 1 AS ord
  UNION ALL SELECT 'Compta', 2
  UNION ALL SELECT 'Notaire', 3
  UNION ALL SELECT 'Prévisionnel & Analyse', 4
  UNION ALL SELECT 'Devis', 5
  UNION ALL SELECT 'Plan & Diagnostique', 6
) c
WHERE pt.code = 'immobilier';

-- Catégories de départ pour les restaurants — À CONFIRMER avec les équipes,
-- la liste n'a pas été arrêtée en réunion. Modifiable directement dans l'interface.
INSERT INTO document_categories (project_id, label, sort_order)
SELECT p.id, c.label, c.ord
FROM projects p
JOIN project_types pt ON pt.id = p.project_type_id
CROSS JOIN (
  SELECT 'Juridique' AS label, 1 AS ord
  UNION ALL SELECT 'Compta', 2
  UNION ALL SELECT 'Contrats & fournisseurs', 3
  UNION ALL SELECT 'Menus & cartes', 4
  UNION ALL SELECT 'Communication & visuels', 5
  UNION ALL SELECT 'Modèles (factures, devis)', 6
) c
WHERE pt.code IN ('restauration_exploitation', 'restauration_murs');
