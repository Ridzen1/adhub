-- Migration 015 — Trésorerie calculée à partir de Pennylane
--
-- Le solde saisi dans la fiche société devient un point de départ : à chaque import
-- Pennylane, ADHUB lui ajoute les mouvements bancaires postérieurs à sa date.

SET NAMES utf8mb4;

ALTER TABLE projects
  ADD COLUMN solde_reference DECIMAL(12,2) NULL AFTER solde_maj_le,
  ADD COLUMN solde_reference_le DATE NULL AFTER solde_reference;
