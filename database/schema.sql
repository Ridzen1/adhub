-- Schéma de départ : brique générique "projet"
-- Exécuté automatiquement au premier démarrage du conteneur db (docker-entrypoint-initdb.d)

CREATE TABLE project_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(50) NOT NULL UNIQUE,
  label VARCHAR(100) NOT NULL
);

CREATE TABLE projects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  project_type_id INT NOT NULL,
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  siret_siren VARCHAR(20) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (project_type_id) REFERENCES project_types(id)
);

CREATE TABLE project_links (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id_a INT NOT NULL,
  project_id_b INT NOT NULL,
  link_type ENUM('murs_exploitation','detention') NOT NULL,
  percentage DECIMAL(5,2) DEFAULT NULL,
  FOREIGN KEY (project_id_a) REFERENCES projects(id),
  FOREIGN KEY (project_id_b) REFERENCES projects(id)
);

-- Types de projets de départ (à ajuster après le cadrage avec Arnaud)
INSERT INTO project_types (code, label) VALUES
  ('holding', 'Holding'),
  ('immobilier', 'Immobilier'),
  ('restauration_murs', 'Restauration - Murs'),
  ('restauration_exploitation', 'Restauration - Exploitation'),
  ('distribution', 'Distribution');

-- Données de démonstration, reprises de l'organigramme, pour vérifier que tout communique
INSERT INTO projects (name, project_type_id, siret_siren) VALUES
  ('AD Holding', (SELECT id FROM project_types WHERE code = 'holding'), '888 849 346 00026'),
  ('SCI Paul Dubois', (SELECT id FROM project_types WHERE code = 'immobilier'), '522 055 458 00023');

INSERT INTO project_links (project_id_a, project_id_b, link_type, percentage) VALUES
  (
    (SELECT id FROM projects WHERE name = 'AD Holding'),
    (SELECT id FROM projects WHERE name = 'SCI Paul Dubois'),
    'detention',
    51.00
  );
