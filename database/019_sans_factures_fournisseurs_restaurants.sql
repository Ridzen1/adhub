-- Migration 019 — Efface les factures fournisseurs Pennylane des restaurants
-- À exécuter une fois (phpMyAdmin en production) :
--   Get-Content database/019_sans_factures_fournisseurs_restaurants.sql -Raw | docker compose exec -T db mysql -uroot -proot dashboard
--
-- Choix de la direction (30/10/2026) : restes à payer et retards fournisseurs des restaurants
-- ne doivent pas apparaître. ADHUB refait ce nettoyage tout seul après chaque import ;
-- cette migration vide ce qui est déjà en base. Les factures clients sont gardées.

DELETE f FROM pennylane_factures f
JOIN pennylane_societes ps ON ps.societe = f.societe
JOIN projects p ON p.id = ps.project_id
JOIN project_types pt ON pt.id = p.project_type_id
WHERE f.sens = 'fournisseur' AND pt.code = 'restauration_exploitation';

-- Charges que l'outil avait tirées de ces factures (une saisie à la main n'a pas cette note)
UPDATE financial_entries fe
JOIN projects p ON p.id = fe.project_id
JOIN project_types pt ON pt.id = p.project_type_id
SET fe.charges = 0
WHERE fe.notes = 'auto:pennylane' AND pt.code = 'restauration_exploitation';
