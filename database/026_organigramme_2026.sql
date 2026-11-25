-- Migration 026 — Organigramme & patrimoine 2026 d'Arnaud Duvivier
--
-- Reprend les informations de l'organigramme 2026 (PowerPoint fourni par Kader le 25/11/2026) :
--   1. crée les sociétés de l'organigramme absentes de la base (aucun id existant n'est modifié) ;
--   2. renseigne le SIRET/SIREN des sociétés qui n'en ont pas encore (une valeur déjà saisie
--      n'est jamais écrasée) ;
--   3. enregistre les liens de détention (qui détient quel % de qui).
--
-- Seules les données présentes sur l'organigramme sont reprises. Rien n'est supprimé,
-- sauf l'ancien lien de démonstration « AD Holding détient 51 % de SCI Paul Dubois » :
-- l'organigramme indique que SCI Paul Dubois est détenue à 51 % par Arnaud Duvivier en nom propre.
--
-- Relançable sans erreur ni doublon. Faire une sauvegarde de la base avant (phpMyAdmin > Exporter).
-- Les sociétés sont retrouvées par leur nom (plusieurs écritures acceptées, accents et
-- majuscules indifférents), pas par leur id.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Sociétés de l'organigramme
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS org_societe;
CREATE TEMPORARY TABLE org_societe (
    cle       VARCHAR(30)  NOT NULL PRIMARY KEY,
    nom       VARCHAR(150) NOT NULL,   -- nom utilisé seulement si la société doit être créée
    type_code VARCHAR(50)  NOT NULL,
    siret     VARCHAR(20)  NULL,
    note      VARCHAR(255) NULL        -- ajoutée à la description seulement si elle est vide
) DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Le SIRET est recopié tel qu'il est écrit sur l'organigramme.
INSERT INTO org_societe (cle, nom, type_code, siret, note) VALUES
  ('ARNAUD',    'ARNAUD DUVIVIER',        'holding',                   NULL,                 'Arnaud Duvivier en nom propre (organigramme 2026).'),
  ('ADH',       'AD Holding',             'holding',                   '888 849 346 00026',  NULL),
  ('PAULDUBOIS','SCI Paul Dubois',        'immobilier',                '522 055 458 00023',  NULL),
  ('AGD',       'SCI AGD INVEST',         'immobilier',                '938 169 455 00012',  NULL),
  ('HOTELDIEU', 'SCI HOTEL DIEU',         'immobilier',                '511 349 748 00018',  NULL),
  ('COUSIMMO',  'SCI COUSIMMO',           'immobilier',                '847 607 827 00023',  NULL),
  ('SCIVALENT', 'SCI LE VALENTINO',       'restauration_murs',         '989 115 548 00012',  NULL),
  ('GDG',       'SCI GENERAL DE GAULLE',  'immobilier',                '921 242 913 00017',  NULL),
  ('FACTORY',   'SCI FACTORY',            'immobilier',                '880 535 919 00026',  NULL),
  ('CAPILOC',   'SCI CAPILOC',            'immobilier',                '101 346 021 00013',  NULL),
  ('DESPONTS',  'SCI DES PONTS',          'immobilier',                NULL,                 'En cours de création (organigramme 2026).'),
  ('LEOPOLD',   'SCI LEOPOLD',            'restauration_murs',         '101 783 165 00018',  NULL),
  ('CASANOSTRA','SCI CASA NOSTRA',        'immobilier',                '850 907 049 00025',  NULL),
  ('ALDIMMO',   'ALD IMMO',               'mdb',                       '889 182 143 00020',  NULL),
  ('DLM',       'SAS DLM',                'mdb',                       '979 605 433 00015',  NULL),
  ('BELLEVUE',  'SAS LE BELLEVUE',        'restauration_exploitation', '10 192 107 000013',  NULL),
  ('SASVALENT', 'SAS LE VALENTINO',       'restauration_exploitation', '989 795 760 00010',  NULL),
  ('JAURES',    'SAS JAURES / CHEZ COME', 'restauration_exploitation', '944 699 202 00024',  'Siège social : SIRET 944 699 202 00016 (organigramme 2026).'),
  ('DTL',       'SCI DTL',                'restauration_murs',         '949 579 940 00014',  NULL);

-- Écritures du nom acceptées pour retrouver chaque société déjà en base
DROP TEMPORARY TABLE IF EXISTS org_variante;
CREATE TEMPORARY TABLE org_variante (
    cle      VARCHAR(30)  NOT NULL,
    variante VARCHAR(150) NOT NULL
) DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO org_variante (cle, variante) VALUES
  ('ARNAUD', 'ARNAUD DUVIVIER'),
  ('ADH', 'AD HOLDING'), ('ADH', 'SAS AD HOLDING'),
  ('PAULDUBOIS', 'SCI PAUL DUBOIS'), ('PAULDUBOIS', 'PAUL DUBOIS'),
  ('AGD', 'SCI AGD INVEST'), ('AGD', 'AGD INVEST'),
  ('HOTELDIEU', 'SCI HOTEL DIEU'), ('HOTELDIEU', 'HOTEL DIEU'),
  ('COUSIMMO', 'SCI COUSIMMO'), ('COUSIMMO', 'COUSIMMO'),
  ('SCIVALENT', 'SCI LE VALENTINO'), ('SCIVALENT', 'SCI VALENTINO'),
  ('GDG', 'SCI GENERAL DE GAULLE'), ('GDG', 'SCI DU GENERAL DE GAULLE'), ('GDG', 'DU GENERAL DE GAULLE'), ('GDG', 'GENERAL DE GAULLE'),
  ('FACTORY', 'SCI FACTORY'), ('FACTORY', 'FACTORY'),
  ('CAPILOC', 'SCI CAPILOC'), ('CAPILOC', 'CAPILOC'),
  ('DESPONTS', 'SCI DES PONTS'), ('DESPONTS', 'DES PONTS'),
  ('LEOPOLD', 'SCI LEOPOLD'), ('LEOPOLD', 'LEOPOLD'),
  ('CASANOSTRA', 'SCI CASA NOSTRA'), ('CASANOSTRA', 'CASA NOSTRA'),
  ('ALDIMMO', 'ALD IMMO'), ('ALDIMMO', 'SAS ALD IMMO'),
  ('DLM', 'SAS DLM'), ('DLM', 'DLM'),
  ('BELLEVUE', 'SAS LE BELLEVUE'), ('BELLEVUE', 'LE BELLEVUE'), ('BELLEVUE', 'BELLEVUE'),
  ('SASVALENT', 'SAS LE VALENTINO'), ('SASVALENT', 'VALENTINO SAS'), ('SASVALENT', 'LE VALENTINO'), ('SASVALENT', 'VALENTINO'),
  ('JAURES', 'SAS JAURES / CHEZ COME'), ('JAURES', 'SAS JAURES'), ('JAURES', 'JAURES'), ('JAURES', 'CHEZ COME'),
  ('DTL', 'SCI DTL'), ('DTL', 'DTL');

-- Les deux restaurants en ligne gardent leur fiche, quel que soit leur nom actuel :
-- Chez Côme (SAS JAURES) = id 10, Le Bellevue = id 11.
INSERT INTO org_variante (cle, variante)
SELECT IF(p.id = 10, 'JAURES', 'BELLEVUE'), TRIM(p.name)
FROM projects p
JOIN project_types pt ON pt.id = p.project_type_id AND pt.code = 'restauration_exploitation'
WHERE p.id IN (10, 11);

-- 1. Création des sociétés absentes (aucune société existante n'est renommée)
INSERT INTO projects (name, project_type_id, siret_siren)
SELECT s.nom, pt.id, s.siret
FROM org_societe s
JOIN project_types pt ON pt.code = s.type_code
WHERE NOT EXISTS (
    SELECT 1 FROM projects p
    JOIN org_variante v ON v.variante = TRIM(p.name) COLLATE utf8mb4_unicode_ci
    WHERE v.cle = s.cle
);

-- Correspondance société de l'organigramme -> id en base (deux copies : MySQL
-- n'accepte pas deux fois la même table temporaire dans une requête)
DROP TEMPORARY TABLE IF EXISTS org_id;
CREATE TEMPORARY TABLE org_id (cle VARCHAR(30) NOT NULL PRIMARY KEY, project_id INT NOT NULL)
    DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
INSERT INTO org_id (cle, project_id)
SELECT v.cle, MIN(p.id)
FROM org_variante v
JOIN projects p ON TRIM(p.name) COLLATE utf8mb4_unicode_ci = v.variante
GROUP BY v.cle;

DROP TEMPORARY TABLE IF EXISTS org_id2;
CREATE TEMPORARY TABLE org_id2 (cle VARCHAR(30) NOT NULL PRIMARY KEY, project_id INT NOT NULL)
    DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
INSERT INTO org_id2 SELECT cle, project_id FROM org_id;

-- 2. SIRET / SIREN, seulement là où rien n'est saisi
UPDATE projects p
JOIN org_id i ON i.project_id = p.id
JOIN org_societe s ON s.cle = i.cle
SET p.siret_siren = s.siret
WHERE s.siret IS NOT NULL
  AND (p.siret_siren IS NULL OR TRIM(p.siret_siren) = '');

-- Notes de l'organigramme (siège de SAS JAURES, SCI DES PONTS en création), seulement si la description est vide
UPDATE projects p
JOIN org_id i ON i.project_id = p.id
JOIN org_societe s ON s.cle = i.cle
SET p.description = s.note
WHERE s.note IS NOT NULL
  AND (p.description IS NULL OR TRIM(p.description) = '');

-- ---------------------------------------------------------------------------
-- 3. Liens de détention : project_id_a détient percentage % de project_id_b
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS org_lien;
CREATE TEMPORARY TABLE org_lien (
    detenteur VARCHAR(30) NOT NULL,
    detenue   VARCHAR(30) NOT NULL,
    pct       DECIMAL(5,2) NOT NULL
) DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO org_lien (detenteur, detenue, pct) VALUES
  ('ARNAUD', 'ADH',        100.00),
  ('ARNAUD', 'PAULDUBOIS',  51.00),
  ('ADH',    'AGD',         50.00),
  ('ADH',    'HOTELDIEU',   99.00),
  ('ADH',    'COUSIMMO',    55.00),
  ('ADH',    'SCIVALENT',   34.00),
  ('ADH',    'GDG',         50.00),
  ('ADH',    'FACTORY',     50.00),
  ('ADH',    'CAPILOC',     50.00),
  ('ADH',    'DESPONTS',    50.00),
  ('ADH',    'LEOPOLD',     34.00),
  ('ADH',    'CASANOSTRA',  55.00),
  ('ADH',    'ALDIMMO',     95.00),
  ('ADH',    'DLM',         50.00),
  ('ADH',    'BELLEVUE',    33.00),
  ('ADH',    'SASVALENT',   33.00),
  ('ADH',    'DTL',         34.00),
  ('DTL',    'JAURES',      75.00);

-- Ancien lien de démonstration contredit par l'organigramme (AD Holding -> SCI Paul Dubois 51 %)
DELETE pl FROM project_links pl
JOIN org_id a  ON a.cle = 'ADH'        AND a.project_id = pl.project_id_a
JOIN org_id2 b ON b.cle = 'PAULDUBOIS' AND b.project_id = pl.project_id_b
WHERE pl.link_type = 'detention' AND pl.percentage = 51.00;

-- Lien déjà présent (dans un sens ou dans l'autre) : remis dans le bon sens, au bon %
UPDATE project_links pl
JOIN org_id  a ON a.project_id IN (pl.project_id_a, pl.project_id_b)
JOIN org_id2 b ON b.project_id IN (pl.project_id_a, pl.project_id_b) AND b.project_id <> a.project_id
JOIN org_lien l ON l.detenteur = a.cle AND l.detenue = b.cle
SET pl.project_id_a = a.project_id,
    pl.project_id_b = b.project_id,
    pl.percentage   = l.pct
WHERE pl.link_type = 'detention';

-- Liens manquants
INSERT INTO project_links (project_id_a, project_id_b, link_type, percentage)
SELECT a.project_id, b.project_id, 'detention', l.pct
FROM org_lien l
JOIN org_id  a ON a.cle = l.detenteur
JOIN org_id2 b ON b.cle = l.detenue
WHERE NOT EXISTS (
    SELECT 1 FROM project_links pl
    WHERE pl.link_type = 'detention'
      AND ((pl.project_id_a = a.project_id AND pl.project_id_b = b.project_id)
        OR (pl.project_id_a = b.project_id AND pl.project_id_b = a.project_id))
);

DROP TEMPORARY TABLE IF EXISTS org_lien, org_id2, org_id, org_variante, org_societe;
