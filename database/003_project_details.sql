-- Migration 003 — Fiche entreprise approfondie
-- À exécuter manuellement (base déjà initialisée) :
--   Get-Content database/003_project_details.sql -Raw | docker compose exec -T db mysql -uroot -proot dashboard
-- ou via Adminer (onglet "Requête SQL").

ALTER TABLE projects
  ADD COLUMN adresse VARCHAR(255) DEFAULT NULL AFTER siret_siren,
  ADD COLUMN surface_m2 DECIMAL(8,2) DEFAULT NULL AFTER adresse,
  ADD COLUMN valeur_bien DECIMAL(12,2) DEFAULT NULL AFTER surface_m2,
  ADD COLUMN montant_emprunt DECIMAL(12,2) DEFAULT NULL AFTER valeur_bien,
  ADD COLUMN documents_url VARCHAR(500) DEFAULT NULL AFTER montant_emprunt,
  ADD COLUMN description TEXT DEFAULT NULL AFTER documents_url;

-- Associés d'un projet : personnes ou entités qui détiennent une part,
-- mais qui n'ont pas forcément leur propre fiche "projet" dans l'outil
-- (contrairement à project_links, qui relie deux projets déjà existants entre eux,
-- ex. AD Holding <-> SCI Paul Dubois).
CREATE TABLE project_associates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  role VARCHAR(100) DEFAULT NULL,       -- ex: gérant, associé, commanditaire
  percentage DECIMAL(5,2) DEFAULT NULL,
  contact_email VARCHAR(150) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (project_id) REFERENCES projects(id)
);

-- Données de démonstration
UPDATE projects
SET adresse = '1 Rue Paul Dubois, Nogent-sur-Seine',
    surface_m2 = 140,
    valeur_bien = 350000,
    montant_emprunt = 101156,
    description = 'Immeuble locatif, 5 lots.'
WHERE name = 'SCI Paul Dubois';

INSERT INTO project_associates (project_id, name, role, percentage) VALUES
  ((SELECT id FROM projects WHERE name = 'SCI Paul Dubois'), 'Associé non identifié', 'associé', 49.00);
