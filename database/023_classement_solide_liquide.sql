-- Migration 023 — Classement solide / liquide des catégories de caisse du Bellevue (L'Addition)
--
-- Catégories relevées le 2026-11-03 dans Administration > Caisses.
-- INSERT IGNORE : une catégorie déjà classée à la main garde son classement.
-- Restent à classer sur la page : « Carte Après-Midi » et « Petit Déjeuner »
-- (mélangent peut-être plats et boissons, à confirmer avec Kader).
--
-- Après la migration : Administration > Caisses > « Recalculer les journées passées »
-- pour appliquer le classement à l'historique.

INSERT IGNORE INTO caisse_familles (source, categorie, famille) VALUES
  -- Solide (cuisine)
  ('laddition', 'Carné', 'solide'), ('laddition', 'Végé', 'solide'), ('laddition', 'Iodé', 'solide'),
  ('laddition', 'Entrée', 'solide'), ('laddition', 'Entrée Midi', 'solide'), ('laddition', 'Entrée Soir', 'solide'),
  ('laddition', 'Plats', 'solide'), ('laddition', 'Plats Midi', 'solide'), ('laddition', 'Plats Soir', 'solide'),
  ('laddition', 'Dessert', 'solide'), ('laddition', 'Desserts', 'solide'),
  ('laddition', 'Desserts Midi', 'solide'), ('laddition', 'Dessert Soir', 'solide'),
  ('laddition', 'Glace', 'solide'), ('laddition', 'Parfum Glace', 'solide'),
  ('laddition', 'Chantilly', 'solide'), ('laddition', 'Touche dessert', 'solide'),
  ('laddition', 'Sauce', 'solide'), ('laddition', 'Cuisson', 'solide'),
  -- Liquide (bar)
  ('laddition', 'Cocktail', 'liquide'), ('laddition', 'Champagne', 'liquide'), ('laddition', 'Kir', 'liquide'),
  ('laddition', 'Vin Au Verre', 'liquide'), ('laddition', 'Vin Blanc', 'liquide'),
  ('laddition', 'Vin Rosé', 'liquide'), ('laddition', 'Vin Rouge', 'liquide'),
  ('laddition', 'Soft', 'liquide'), ('laddition', 'Sirop', 'liquide'),
  ('laddition', 'Supplément Sirop', 'liquide'), ('laddition', 'Supplément Picon', 'liquide'),
  ('laddition', 'Citron', 'liquide'), ('laddition', 'Orange', 'liquide'), ('laddition', 'Glaçons', 'liquide'),
  ('laddition', 'Cappuccino', 'liquide'), ('laddition', 'Déca', 'liquide'), ('laddition', 'Thé', 'liquide'),
  -- Autre (options sans prix, lignes sans catégorie)
  ('laddition', 'Commentaires', 'autre'), ('laddition', 'Goumand', 'autre'),
  ('laddition', '_Sans catégorie', 'autre'), ('popina', '.', 'autre');
