-- Migration 022 — Adresse de Kader + comptes en lecture seule sur toutes les sociétés
--
-- 1. Le compte de départ (arnaud@example.com, rôle Direction) est celui de Kader :
--    il passe à ryad.haddad10@gmail.com et au nom « Ryad Haddad ». Mot de passe et
--    double authentification inchangés.
-- 2. Nouveau compte Arnaud Duvivier (scihoteldieu@gmail.com), rôle Gestionnaire,
--    lecture seule sur toutes les sociétés.
-- 3. Compte de test de Kader, ryad.haddad10+lecture@gmail.com (les messages arrivent
--    dans la même boîte Gmail), rôle Gestionnaire, lecture seule sur toutes les
--    sociétés : pour voir le site comme un associé.
--
-- MOTS DE PASSE : aucun ici. Les comptes 2 et 3 ne peuvent pas se connecter tant
-- qu'un mot de passe provisoire n'est pas défini dans Administration >
-- Utilisateurs (bouton « Modifier »).
--
-- Les accès portent sur les sociétés existantes au moment du lancement. Une
-- société créée plus tard s'ajoute dans Administration > Utilisateurs (ou en
-- relançant cette migration). Relançable sans doublon.

SET NAMES utf8mb4;

-- 1. Compte de Kader ------------------------------------------------------------
UPDATE users SET email = 'ryad.haddad10@gmail.com', name = 'Ryad Haddad'
WHERE email = 'arnaud@example.com'
  AND NOT EXISTS (SELECT 1 FROM (SELECT email FROM users) x WHERE x.email = 'ryad.haddad10@gmail.com');

-- 2 et 3. Comptes en lecture seule ----------------------------------------------
INSERT INTO users (name, email, password_hash, role_id)
SELECT c.nom, c.email, '!a-definir', (SELECT id FROM roles WHERE code = 'gestionnaire')
FROM (
            SELECT 'Arnaud Duvivier' AS nom, 'scihoteldieu@gmail.com' AS email
  UNION ALL SELECT 'Ryad Haddad (lecture seule)', 'ryad.haddad10+lecture@gmail.com'
) c
WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.email = c.email);

INSERT INTO project_user_access (project_id, user_id, access_level, scope)
SELECT p.id, u.id, 'read', 'all'
FROM projects p
JOIN users u ON u.email IN ('scihoteldieu@gmail.com', 'ryad.haddad10+lecture@gmail.com')
WHERE NOT EXISTS (
  SELECT 1 FROM project_user_access x WHERE x.project_id = p.id AND x.user_id = u.id
);

-- Vérification : une ligne par compte, avec le nombre de sociétés accessibles.
SELECT u.name, u.email, r.code AS role, COUNT(pua.id) AS societes
FROM users u
JOIN roles r ON r.id = u.role_id
LEFT JOIN project_user_access pua ON pua.user_id = u.id
WHERE u.email IN ('ryad.haddad10@gmail.com', 'scihoteldieu@gmail.com', 'ryad.haddad10+lecture@gmail.com')
GROUP BY u.id, u.name, u.email, r.code;
