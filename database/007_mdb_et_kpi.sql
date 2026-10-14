-- Migration 007 — Type MDB, indicateurs de pilotage, et sociétés réelles
-- À exécuter manuellement :
--   Get-Content database/007_mdb_et_kpi.sql -Raw | docker compose exec -T db mysql -uroot -proot dashboard

-- 1) Nouveau type : marchands de biens
INSERT INTO project_types (code, label)
SELECT 'mdb', 'Marchand de biens'
WHERE NOT EXISTS (SELECT 1 FROM project_types WHERE code = 'mdb');

-- 2) Indicateurs affichés sur la vue globale.
--    Ce sont des valeurs "à date" (photo instantanée), pas un historique : la vue
--    globale n'a besoin que de la dernière valeur connue. `solde_maj_le` sert à
--    repérer d'un coup d'œil une donnée qui n'a pas été actualisée depuis longtemps.
ALTER TABLE projects
  ADD COLUMN solde_bancaire DECIMAL(12,2) DEFAULT NULL AFTER montant_emprunt,
  ADD COLUMN solde_maj_le DATE DEFAULT NULL AFTER solde_bancaire,
  ADD COLUMN lots_total INT DEFAULT NULL AFTER solde_maj_le,
  ADD COLUMN lots_loues INT DEFAULT NULL AFTER lots_total,
  ADD COLUMN affaires_en_cours INT DEFAULT NULL AFTER lots_loues;

-- 3) Sociétés relevées dans Pennylane.
--    Insertion uniquement si le nom n'existe pas déjà (évite les doublons avec
--    les données de démonstration créées au départ).
INSERT INTO projects (name, project_type_id)
SELECT s.nom, (SELECT id FROM project_types WHERE code = s.type_code)
FROM (
  SELECT 'AD HOLDING'            AS nom, 'holding'                   AS type_code
  UNION ALL SELECT 'AGD INVEST',            'immobilier'
  UNION ALL SELECT 'ALD IMMO',              'mdb'
  UNION ALL SELECT 'ARNAUD DUVIVIER',       'holding'
  UNION ALL SELECT 'COUSIMMO',              'immobilier'
  UNION ALL SELECT 'DLM',                   'mdb'
  UNION ALL SELECT 'DU GENERAL DE GAULLE',  'immobilier'
  UNION ALL SELECT 'FACTORY',               'immobilier'
  UNION ALL SELECT 'JAURES',                'immobilier'
  UNION ALL SELECT 'LE BELLEVUE',           'restauration_exploitation'
) s
WHERE NOT EXISTS (
  SELECT 1 FROM projects p WHERE UPPER(p.name) = UPPER(s.nom)
);

-- ---------------------------------------------------------------------------
-- POINTS À CONFIRMER AVEC ARNAUD (types attribués par déduction, pas par certitude)
--
--   ARNAUD DUVIVIER       : rangé en "holding" (détention en nom propre).
--   DU GENERAL DE GAULLE  : rangé en immobilier. L'organigramme situe le
--                           restaurant Le Valentino au 23 rue Général de Gaulle —
--                           il s'agit peut-être de la SCI qui en porte les murs.
--   JAURES                : l'organigramme distingue SAS Jaurès (exploitation) et
--                           SCI Jaurès (murs). Pennylane n'affiche qu'une entrée.
--   ALD IMMO / DLM        : rangés en MDB d'après la réunion, à confirmer.
--
-- Le type se corrige en deux clics depuis la fiche société, aucune migration
-- supplémentaire n'est nécessaire.
-- ---------------------------------------------------------------------------

-- 4) Valeurs de démonstration pour que la vue globale ne soit pas vide.
--    À remplacer par les vrais soldes dès qu'ils sont connus.
UPDATE projects p
JOIN project_types pt ON pt.id = p.project_type_id
SET p.solde_bancaire = 18400.00, p.solde_maj_le = CURDATE(),
    p.lots_total = 5, p.lots_loues = 4
WHERE pt.code = 'immobilier' AND p.solde_bancaire IS NULL;

UPDATE projects p
JOIN project_types pt ON pt.id = p.project_type_id
SET p.solde_bancaire = 24600.00, p.solde_maj_le = CURDATE()
WHERE pt.code IN ('restauration_exploitation', 'restauration_murs')
  AND p.solde_bancaire IS NULL;

UPDATE projects p
JOIN project_types pt ON pt.id = p.project_type_id
SET p.solde_bancaire = 54200.00, p.solde_maj_le = CURDATE(),
    p.affaires_en_cours = 3
WHERE pt.code = 'mdb' AND p.solde_bancaire IS NULL;
