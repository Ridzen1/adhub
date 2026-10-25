-- Migration 012 — Double authentification (code à 6 chiffres d'une application type
-- Google Authenticator, Microsoft Authenticator, 1Password…)
-- À exécuter manuellement :
--   Get-Content database/012_double_authentification.sql -Raw | docker compose exec -T db mysql -uroot -proot dashboard
--
-- Tant que totp_enabled = 0, la connexion reste par mot de passe seul (sauf pour les
-- rôles où la 2FA est obligatoire : l'utilisateur est alors guidé vers l'activation).

ALTER TABLE users
  ADD COLUMN totp_secret      VARCHAR(64)  NULL AFTER password_hash,
  ADD COLUMN totp_enabled     TINYINT(1)   NOT NULL DEFAULT 0 AFTER totp_secret,
  ADD COLUMN totp_enabled_at  DATETIME     NULL AFTER totp_enabled,
  -- Dernier pas de 30 s accepté : un même code ne sert qu'une fois
  ADD COLUMN totp_last_step   BIGINT       NULL AFTER totp_enabled_at,
  -- Anti force brute : échecs consécutifs et blocage temporaire
  ADD COLUMN totp_failures    INT          NOT NULL DEFAULT 0 AFTER totp_last_step,
  ADD COLUMN totp_locked_until DATETIME    NULL AFTER totp_failures;

-- Codes de secours (si le téléphone est perdu). Seule une empreinte est stockée.
CREATE TABLE IF NOT EXISTS user_recovery_codes (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  code_hash  CHAR(64) NOT NULL,
  used_at    DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
