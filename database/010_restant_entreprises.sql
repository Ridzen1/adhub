-- 1. Ajout des 9 sociétés manquantes identifiées dans l'organigramme
INSERT INTO projects (name, project_type_id)
SELECT s.nom, (SELECT id FROM project_types WHERE code = s.type_code)
FROM (
  SELECT 'SCI HOTEL DIEU' AS nom, 'immobilier' AS type_code
  UNION ALL SELECT 'SCI CASA NOSTRA', 'immobilier'
  UNION ALL SELECT 'SCI CAPILOC', 'immobilier'
  UNION ALL SELECT 'SCI DES PONTS', 'immobilier'
  UNION ALL SELECT 'SAS LE VALENTINO', 'restauration_exploitation'
  UNION ALL SELECT 'SCI LE VALENTINO', 'restauration_murs'
  UNION ALL SELECT 'SCI LEOPOLD', 'restauration_murs'
  UNION ALL SELECT 'SCI DTL', 'restauration_murs'
  UNION ALL SELECT 'SCI JAURES', 'restauration_murs'
) s
WHERE NOT EXISTS (
  SELECT 1 FROM projects p WHERE UPPER(p.name) = UPPER(s.nom)
);

-- 2. Clarification des noms existants
-- L'organigramme distingue la SAS et la SCI Jaurès, et précise les statuts.
UPDATE projects SET name = 'SAS JAURES / CHEZ COME' WHERE name = 'JAURES';
UPDATE projects SET name = 'SAS DLM' WHERE name = 'DLM';
UPDATE projects SET name = 'SCI GENERAL DE GAULLE' WHERE name = 'DU GENERAL DE GAULLE';

-- 3. Jeu d'essai (faux chiffres) pour lisser le tableau de bord avec les nouvelles entités

-- Soldes bancaires et lots pour les SCI Immobilières nouvellement créées
UPDATE projects p
JOIN project_types pt ON pt.id = p.project_type_id
SET p.solde_bancaire = 14250.00, p.solde_maj_le = CURDATE(), p.lots_total = 6, p.lots_loues = 5
WHERE p.name IN ('SCI HOTEL DIEU', 'SCI CASA NOSTRA');

UPDATE projects p
JOIN project_types pt ON pt.id = p.project_type_id
SET p.solde_bancaire = 3100.00, p.solde_maj_le = CURDATE(), p.lots_total = 2, p.lots_loues = 1
WHERE p.name IN ('SCI CAPILOC', 'SCI DES PONTS');

-- Soldes bancaires pour les nouvelles sociétés de Restauration (Exploitation et Murs)
UPDATE projects p
JOIN project_types pt ON pt.id = p.project_type_id
SET p.solde_bancaire = 35800.00, p.solde_maj_le = CURDATE()
WHERE p.name IN ('SAS LE VALENTINO', 'SCI LE VALENTINO', 'SCI LEOPOLD', 'SCI DTL', 'SCI JAURES');

-- Ajout de données financières mensuelles fictives (Revenus / Charges)
INSERT INTO financial_entries (project_id, period_date, revenus, charges)
SELECT id, '2026-08-01', 5200.00, 1800.00
FROM projects WHERE name IN ('SCI HOTEL DIEU', 'SCI CASA NOSTRA', 'SCI LEOPOLD');

INSERT INTO financial_entries (project_id, period_date, revenus, charges)
SELECT id, '2026-08-01', 45000.00, 32000.00
FROM projects WHERE name = 'SAS LE VALENTINO';