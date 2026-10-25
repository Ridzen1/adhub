-- Suppression des données de démonstration insérées par les migrations
-- 002, 006, 008 et 010, à passer une fois en production après les migrations.
--
-- Chaque suppression cible exactement les lignes fictives (mêmes montants,
-- mêmes libellés) : une saisie réelle, une synchro caisse ou un import
-- Pennylane n'est jamais touché, même si le script est relancé plus tard.
-- Les sociétés, rôles et catégories de documents sont conservés.

SET NAMES utf8mb4;

-- 002 : six mois fictifs (avril à juin 2026) sur SCI Paul Dubois et AD Holding
DELETE fe FROM financial_entries fe
JOIN projects p ON p.id = fe.project_id
JOIN (
  SELECT 'SCI Paul Dubois' AS nom, DATE '2026-04-01' AS mois, 4200.00 AS rev, 2600.00 AS chg
  UNION ALL SELECT 'SCI Paul Dubois', DATE '2026-05-01', 4200.00, 3100.00
  UNION ALL SELECT 'SCI Paul Dubois', DATE '2026-06-01', 4350.00, 2700.00
  UNION ALL SELECT 'AD Holding',      DATE '2026-04-01', 18000.00, 15500.00
  UNION ALL SELECT 'AD Holding',      DATE '2026-05-01', 17200.00, 16800.00
  UNION ALL SELECT 'AD Holding',      DATE '2026-06-01', 19100.00, 14900.00
) d ON d.nom = p.name AND d.mois = fe.period_date AND d.rev = fe.revenus AND d.chg = fe.charges;

-- 010 : août 2026 fictif sur trois SCI et SAS LE VALENTINO
DELETE fe FROM financial_entries fe
JOIN projects p ON p.id = fe.project_id
WHERE fe.period_date = '2026-08-01'
  AND (
    (p.name IN ('SCI HOTEL DIEU', 'SCI CASA NOSTRA', 'SCI LEOPOLD') AND fe.revenus = 5200.00 AND fe.charges = 1800.00)
    OR (p.name = 'SAS LE VALENTINO' AND fe.revenus = 45000.00 AND fe.charges = 32000.00)
  );

-- 006 : sept journées fictives saisies à la main sur un restaurant
DELETE de FROM daily_entries de
JOIN (
  SELECT 1200.00 AS ca, 44 AS cv
  UNION ALL SELECT 1450.00, 52
  UNION ALL SELECT 1600.00, 58
  UNION ALL SELECT 1300.00, 47
  UNION ALL SELECT 1850.00, 66
  UNION ALL SELECT 2100.00, 74
  UNION ALL SELECT 1950.00, 69
) d ON d.ca = de.ca_ttc AND d.cv = de.couverts
WHERE de.source = 'manuel' AND de.notes IS NULL;

-- 008 : trois charges fixes fictives sur une SCI (repérées sans les accents des
-- libellés, qui peuvent avoir été mal encodés à l'import)
DELETE FROM fixed_charges
WHERE (category = 'assurance' AND fournisseur = 'AXA'          AND montant = 480.00 AND label LIKE 'Assurance PNO')
   OR (category = 'energie'   AND fournisseur = 'EDF'          AND montant = 95.00  AND label LIKE '%parties communes')
   OR (category = 'entretien' AND fournisseur = 'Chauffagiste' AND montant = 180.00 AND label LIKE 'Entretien chaudi%');
