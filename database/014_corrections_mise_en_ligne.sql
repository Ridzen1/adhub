-- Migration 014 — Corrections avant la mise en ligne
--
-- 1. SAS JAURES / CHEZ COME est le restaurant (exploitation) : il était rangé en
--    immobilier, donc sans saisie quotidienne ni analyse des ventes.
-- 2. Les migrations 007 et 010 avaient mis des soldes bancaires, lots et affaires
--    en cours fictifs sur les sociétés. On les efface (valeurs exactes de démo
--    uniquement) : un solde saisi à la main n'est jamais touché.

SET NAMES utf8mb4;

UPDATE projects
SET project_type_id = (SELECT id FROM project_types WHERE code = 'restauration_exploitation')
WHERE name = 'SAS JAURES / CHEZ COME';

UPDATE projects
SET solde_bancaire = NULL, solde_maj_le = NULL, lots_total = NULL, lots_loues = NULL
WHERE (solde_bancaire = 18400.00 AND lots_total = 5 AND lots_loues = 4)
   OR (solde_bancaire = 14250.00 AND lots_total = 6 AND lots_loues = 5)
   OR (solde_bancaire = 3100.00  AND lots_total = 2 AND lots_loues = 1);

UPDATE projects
SET solde_bancaire = NULL, solde_maj_le = NULL, affaires_en_cours = NULL
WHERE solde_bancaire = 54200.00 AND affaires_en_cours = 3;

UPDATE projects
SET solde_bancaire = NULL, solde_maj_le = NULL
WHERE solde_bancaire IN (24600.00, 35800.00);
