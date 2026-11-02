-- Migration 020 — Boîte à idées : problèmes et améliorations remontés par les utilisateurs
--
-- Tout utilisateur connecté peut envoyer un message. Seuls les comptes de la
-- direction voient l'ensemble des messages ; chacun ne voit que les siens.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS retours (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NULL COMMENT 'Auteur (NULL si le compte a été supprimé)',
  type        ENUM('probleme','idee') NOT NULL,
  sujet       VARCHAR(150) NOT NULL,
  message     TEXT NOT NULL,
  page        VARCHAR(255) NULL COMMENT 'Page d''où le message a été envoyé',
  statut      ENUM('nouveau','en_cours','traite') NOT NULL DEFAULT 'nouveau',
  reponse     TEXT NULL COMMENT 'Réponse de la direction, visible par l''auteur',
  repondu_par INT NULL,
  repondu_le  DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_statut (statut, created_at),
  KEY idx_auteur (user_id, created_at),
  CONSTRAINT retours_auteur FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT retours_repondant FOREIGN KEY (repondu_par) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
