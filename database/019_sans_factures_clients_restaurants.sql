-- Migration 019 — Retire les factures clients Pennylane des restaurants
-- À exécuter une fois (phpMyAdmin en production) :
--   Get-Content database/019_sans_factures_clients_restaurants.sql -Raw | docker compose exec -T db mysql -uroot -proot dashboard
--
-- Le CA des restaurants vient des caisses (L'Addition, Popina) : les factures clients
-- importées de Pennylane faussaient l'onglet Comptabilité. Les factures fournisseurs
-- sont gardées. ADHUB refait ce nettoyage tout seul après chaque import ; cette
-- migration vide simplement ce qui est déjà en base.

DELETE f FROM pennylane_factures f
JOIN pennylane_societes ps ON ps.societe = f.societe
JOIN projects p ON p.id = ps.project_id
JOIN project_types pt ON pt.id = p.project_type_id
WHERE f.sens = 'client' AND pt.code = 'restauration_exploitation';
