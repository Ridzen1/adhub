-- Migration 008 — Charges fixes et dates anniversaires
-- À exécuter manuellement :
--   Get-Content database/008_charges_fixes.sql -Raw | docker compose exec -T db mysql -uroot -proot dashboard

CREATE TABLE fixed_charges (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  label VARCHAR(150) NOT NULL,
  category ENUM('assurance','energie','telecom','entretien','abonnement',
                'loyer','pret','honoraires','autre') NOT NULL DEFAULT 'autre',
  fournisseur VARCHAR(150) DEFAULT NULL,
  montant DECIMAL(10,2) NOT NULL DEFAULT 0,
  frequence ENUM('mensuel','trimestriel','semestriel','annuel') NOT NULL DEFAULT 'mensuel',

  -- Date de référence de l'échéance : sert de point de départ pour calculer
  -- toutes les occurrences suivantes. Pour un contrat annuel, c'est la date
  -- anniversaire (reconduction tacite).
  date_reference DATE NOT NULL,

  -- Préavis de résiliation en jours. 0 = pas de préavis à surveiller.
  -- C'est ce champ qui permet d'alerter AVANT qu'il soit trop tard pour résilier.
  preavis_jours INT NOT NULL DEFAULT 0,

  date_fin DATE DEFAULT NULL,            -- contrat à durée déterminée
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  notes VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (project_id) REFERENCES projects(id)
);

CREATE INDEX idx_charges_project ON fixed_charges (project_id, is_active);

-- Données de démonstration sur la première SCI trouvée, pour que la page
-- ne soit pas vide au premier lancement.
INSERT INTO fixed_charges
  (project_id, label, category, fournisseur, montant, frequence, date_reference, preavis_jours, notes)
SELECT cible.id, d.label, d.cat, d.fourn, d.montant, d.freq, d.ref, d.preavis, d.notes
FROM (
  SELECT p.id
  FROM projects p
  JOIN project_types pt ON pt.id = p.project_type_id
  WHERE pt.code = 'immobilier' AND p.status = 'active'
  ORDER BY p.name
  LIMIT 1
) AS cible
CROSS JOIN (
  SELECT 'Assurance PNO' AS label, 'assurance' AS cat, 'AXA' AS fourn,
         480.00 AS montant, 'annuel' AS freq,
         DATE_ADD(CURDATE(), INTERVAL 45 DAY) AS ref, 60 AS preavis,
         'Reconduction tacite' AS notes
  UNION ALL
  SELECT 'Électricité parties communes', 'energie', 'EDF',
         95.00, 'mensuel', DATE_SUB(CURDATE(), INTERVAL 20 DAY), 0, NULL
  UNION ALL
  SELECT 'Entretien chaudière', 'entretien', 'Chauffagiste',
         180.00, 'annuel', DATE_ADD(CURDATE(), INTERVAL 200 DAY), 30, NULL
) d;
