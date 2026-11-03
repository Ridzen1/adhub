-- Migration 021 — Comptes des équipes restaurants + nouvelle société JACS BOX
--
-- 1. Crée la société restaurant JACS BOX (vide pour l'instant).
-- 2. Crée les comptes des équipes et leur donne accès à leurs restaurants :
--      LE BELLEVUE            : Olivier Forgas, Baptiste Gautier
--      SAS LE VALENTINO       : Romain Asselin, Baptiste Gautier
--      SAS JAURES / CHEZ COME : Baptiste Gautier, Pierre Legrand, Mathieu Trognon
--    Arnaud (Arnaud Duvivier) a le rôle Direction : il voit déjà toutes les sociétés.
--
-- Rôle : Gestionnaire. Accès : lecture seule, tout le restaurant (modifiable
-- ensuite dans Administration > Utilisateurs).
--
-- MOTS DE PASSE : aucun mot de passe n'est écrit ici. Les comptes sont créés
-- avec une empreinte invalide, donc personne ne peut s'y connecter tant que la
-- direction n'a pas défini un mot de passe provisoire dans Administration >
-- Utilisateurs (bouton « Modifier »).
--
-- E-MAILS : adresses réelles fournies par Kader le 2026-11-03. Si une première
-- version de cette migration a déjà créé des adresses provisoires
-- (@a-completer.invalid), la section 4 les remplace.
--
-- La migration peut être relancée sans créer de doublon.

SET NAMES utf8mb4;

-- 1. Société JACS BOX ---------------------------------------------------------
INSERT INTO projects (name, project_type_id)
SELECT 'JACS BOX', (SELECT id FROM project_types WHERE code = 'restauration_exploitation')
WHERE NOT EXISTS (SELECT 1 FROM projects WHERE UPPER(name) = 'JACS BOX');

-- 2. Comptes ------------------------------------------------------------------
INSERT INTO users (name, email, password_hash, role_id)
SELECT c.nom, c.email, '!a-definir', (SELECT id FROM roles WHERE code = 'gestionnaire')
FROM (
            SELECT 'Olivier Forgas'   AS nom, 'olivier.forgas@free.fr'   AS email
  UNION ALL SELECT 'Baptiste Gautier',        'baptiste.gautier61@gmail.com'
  UNION ALL SELECT 'Romain Asselin',          'r.asselin1985@gmail.com'
  UNION ALL SELECT 'Pierre Legrand',          'pierre.legrand.aube@outlook.com'
  UNION ALL SELECT 'Mathieu Trognon',         'mathieutrognon@gmail.com'
) c
WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.name = c.nom);

-- 3. Accès par restaurant -----------------------------------------------------
INSERT INTO project_user_access (project_id, user_id, access_level, scope)
SELECT p.id, u.id, 'read', 'all'
FROM (
            SELECT 'LE BELLEVUE'            AS societe, 'Olivier Forgas'   AS nom
  UNION ALL SELECT 'LE BELLEVUE',                       'Baptiste Gautier'
  UNION ALL SELECT 'SAS LE VALENTINO',                  'Romain Asselin'
  UNION ALL SELECT 'SAS LE VALENTINO',                  'Baptiste Gautier'
  UNION ALL SELECT 'SAS JAURES / CHEZ COME',            'Baptiste Gautier'
  UNION ALL SELECT 'SAS JAURES / CHEZ COME',            'Pierre Legrand'
  UNION ALL SELECT 'SAS JAURES / CHEZ COME',            'Mathieu Trognon'
) a
JOIN projects p ON UPPER(p.name) = UPPER(a.societe)
JOIN users u    ON u.name = a.nom
WHERE NOT EXISTS (
  SELECT 1 FROM project_user_access x WHERE x.project_id = p.id AND x.user_id = u.id
);

-- 4. Remplacement des adresses provisoires éventuelles --------------------------
UPDATE users SET email = 'olivier.forgas@free.fr' WHERE email = 'olivier.forgas@a-completer.invalid';
UPDATE users SET email = 'baptiste.gautier61@gmail.com' WHERE email = 'baptiste.gautier@a-completer.invalid';
UPDATE users SET email = 'r.asselin1985@gmail.com' WHERE email = 'romain.asselin@a-completer.invalid';
UPDATE users SET email = 'pierre.legrand.aube@outlook.com' WHERE email = 'pierre.legrand@a-completer.invalid';
UPDATE users SET email = 'mathieutrognon@gmail.com' WHERE email = 'mathieu.trognon@a-completer.invalid';

-- Vérification : doit lister 7 lignes.
SELECT p.name AS societe, u.name AS utilisateur, u.email
FROM project_user_access pua
JOIN projects p ON p.id = pua.project_id
JOIN users u    ON u.id = pua.user_id
WHERE u.name IN
  ('Olivier Forgas','Baptiste Gautier','Romain Asselin','Pierre Legrand','Mathieu Trognon')
ORDER BY p.name, u.name;
