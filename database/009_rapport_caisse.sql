-- Migration 009 — Champs issus du rapport de caisse
-- À exécuter manuellement :
--   Get-Content database/009_rapport_caisse.sql -Raw | docker compose exec -T db mysql -uroot -proot dashboard

ALTER TABLE daily_entries
  ADD COLUMN ca_ht DECIMAL(10,2) DEFAULT NULL AFTER ca_ttc,
  ADD COLUMN remises DECIMAL(10,2) DEFAULT NULL AFTER ca_ht,
  ADD COLUMN offerts DECIMAL(10,2) DEFAULT NULL AFTER remises,

  -- Vocabulaire repris de la caisse, pour que les chiffres du tableau de bord
  -- portent le même nom que ceux du rapport quotidien reçu par mail :
  --   panier moyen = CA / couvert       (recalculable)
  --   ticket moyen = CA / addition      (fourni par la caisse, non recalculable
  --                                      faute de connaître le nombre d'additions)
  ADD COLUMN ticket_moyen DECIMAL(8,2) DEFAULT NULL AFTER couverts,

  ADD COLUMN corrections_cuisine DECIMAL(10,2) DEFAULT NULL AFTER ticket_moyen,
  ADD COLUMN ca_solide DECIMAL(10,2) DEFAULT NULL AFTER corrections_cuisine,
  ADD COLUMN ca_liquide DECIMAL(10,2) DEFAULT NULL AFTER ca_solide;
