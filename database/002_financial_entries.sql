-- Migration 002 — Suivi financier mensuel par projet (revenus / charges)
-- La base existe déjà, donc ce fichier doit être exécuté manuellement
-- (voir README.md : section "Appliquer une migration").

CREATE TABLE financial_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  period_date DATE NOT NULL,        -- toujours le 1er jour du mois, ex : 2026-06-01
  revenus DECIMAL(12,2) NOT NULL DEFAULT 0,
  charges DECIMAL(12,2) NOT NULL DEFAULT 0,
  notes VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_project_period (project_id, period_date),
  FOREIGN KEY (project_id) REFERENCES projects(id)
);

-- Données de démonstration pour voir tout de suite un dashboard non vide
INSERT INTO financial_entries (project_id, period_date, revenus, charges) VALUES
  ((SELECT id FROM projects WHERE name = 'SCI Paul Dubois'), '2026-04-01', 4200, 2600),
  ((SELECT id FROM projects WHERE name = 'SCI Paul Dubois'), '2026-05-01', 4200, 3100),
  ((SELECT id FROM projects WHERE name = 'SCI Paul Dubois'), '2026-06-01', 4350, 2700),
  ((SELECT id FROM projects WHERE name = 'AD Holding'),      '2026-04-01', 18000, 15500),
  ((SELECT id FROM projects WHERE name = 'AD Holding'),      '2026-05-01', 17200, 16800),
  ((SELECT id FROM projects WHERE name = 'AD Holding'),      '2026-06-01', 19100, 14900);
