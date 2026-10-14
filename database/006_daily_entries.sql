-- Migration 006 — Saisie quotidienne des restaurants
-- À exécuter manuellement :
--   Get-Content database/006_daily_entries.sql -Raw | docker compose exec -T db mysql -uroot -proot dashboard

-- Une ligne par restaurant et par jour.
-- La colonne `source` permet de faire cohabiter saisie manuelle, import de fichier
-- et API sans changer la structure : le dashboard lit toujours la même table.
CREATE TABLE daily_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  entry_date DATE NOT NULL,
  ca_ttc DECIMAL(10,2) NOT NULL DEFAULT 0,
  couverts INT NOT NULL DEFAULT 0,
  notes VARCHAR(255) DEFAULT NULL,
  source ENUM('manuel','email','api') NOT NULL DEFAULT 'manuel',
  created_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_project_day (project_id, entry_date),
  FOREIGN KEY (project_id) REFERENCES projects(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE INDEX idx_daily_date ON daily_entries (entry_date);

-- Le ticket moyen n'est pas stocké : il se calcule (CA / couverts).
-- Le stocker créerait un risque d'incohérence si le CA ou les couverts sont corrigés.

-- Données de démonstration sur les 7 derniers jours, pour un seul restaurant
-- (le premier par ordre alphabétique) afin d'avoir un dashboard non vide.
INSERT INTO daily_entries (project_id, entry_date, ca_ttc, couverts, source)
SELECT cible.id, d.jour, d.ca, d.cv, 'manuel'
FROM (
  SELECT p.id
  FROM projects p
  JOIN project_types pt ON pt.id = p.project_type_id
  WHERE pt.code = 'restauration_exploitation' AND p.status = 'active'
  ORDER BY p.name
  LIMIT 1
) AS cible
CROSS JOIN (
  SELECT CURDATE() - INTERVAL 6 DAY AS jour, 1200.00 AS ca, 44 AS cv
  UNION ALL SELECT CURDATE() - INTERVAL 5 DAY, 1450.00, 52
  UNION ALL SELECT CURDATE() - INTERVAL 4 DAY, 1600.00, 58
  UNION ALL SELECT CURDATE() - INTERVAL 3 DAY, 1300.00, 47
  UNION ALL SELECT CURDATE() - INTERVAL 2 DAY, 1850.00, 66
  UNION ALL SELECT CURDATE() - INTERVAL 1 DAY, 2100.00, 74
  UNION ALL SELECT CURDATE(), 1950.00, 69
) d;
