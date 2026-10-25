-- ADHUB : base complète (schéma + migrations 002 à 014, sans données de démonstration).
-- Dans phpMyAdmin : sélectionner la base vide créée dans cPanel, onglet Importer, choisir ce fichier, Exécuter.
-- Contient le compte de départ arnaud@example.com / Admin2026! : changer e-mail et mot de passe dès la première connexion.


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `caisse_etablissements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `caisse_etablissements` (
  `source` enum('laddition','popina') NOT NULL,
  `id_externe` int(10) unsigned NOT NULL,
  `project_id` int(11) NOT NULL,
  `nom` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`source`,`id_externe`),
  KEY `idx_project` (`project_id`),
  CONSTRAINT `caisse_etablissements_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `caisse_etablissements` DISABLE KEYS */;
/*!40000 ALTER TABLE `caisse_etablissements` ENABLE KEYS */;
DROP TABLE IF EXISTS `caisse_familles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `caisse_familles` (
  `source` enum('laddition','popina') NOT NULL,
  `categorie` varchar(100) NOT NULL,
  `famille` enum('solide','liquide','autre') NOT NULL,
  PRIMARY KEY (`source`,`categorie`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `caisse_familles` DISABLE KEYS */;
INSERT INTO `caisse_familles` VALUES
('laddition','Boisson Chaude','liquide'),
('laddition','Boissons','liquide'),
('laddition','Dessert','solide'),
('laddition','Entrée','solide'),
('laddition','Menu','autre'),
('laddition','Plat','solide'),
('popina','ALCOOLS FORTS','liquide'),
('popina','BIERES/CIDRES','liquide'),
('popina','CARNÉ','solide'),
('popina','CAVE','liquide'),
('popina','COCKTAILS','liquide'),
('popina','DESSERTS','solide'),
('popina','MARIN','solide'),
('popina','SOFTS','liquide'),
('popina','Suggestion','solide'),
('popina','VÉGÉ','solide');
/*!40000 ALTER TABLE `caisse_familles` ENABLE KEYS */;
DROP TABLE IF EXISTS `daily_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `daily_entries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `entry_date` date NOT NULL,
  `ca_ttc` decimal(10,2) NOT NULL DEFAULT 0.00,
  `ca_ht` decimal(10,2) DEFAULT NULL,
  `remises` decimal(10,2) DEFAULT NULL,
  `offerts` decimal(10,2) DEFAULT NULL,
  `couverts` int(11) NOT NULL DEFAULT 0,
  `ticket_moyen` decimal(8,2) DEFAULT NULL,
  `corrections_cuisine` decimal(10,2) DEFAULT NULL,
  `ca_solide` decimal(10,2) DEFAULT NULL,
  `ca_liquide` decimal(10,2) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `source` enum('manuel','email','api') NOT NULL DEFAULT 'manuel',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_project_day` (`project_id`,`entry_date`),
  KEY `created_by` (`created_by`),
  KEY `idx_daily_date` (`entry_date`),
  CONSTRAINT `daily_entries_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `daily_entries_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `daily_entries` DISABLE KEYS */;
/*!40000 ALTER TABLE `daily_entries` ENABLE KEYS */;
DROP TABLE IF EXISTS `document_access_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_access_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `opened_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`),
  KEY `user_id` (`user_id`),
  KEY `idx_log_opened` (`opened_at`),
  CONSTRAINT `document_access_log_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `document_categories` (`id`),
  CONSTRAINT `document_access_log_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `document_access_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `document_access_log` ENABLE KEYS */;
DROP TABLE IF EXISTS `document_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `label` varchar(120) NOT NULL,
  `onedrive_url` varchar(600) DEFAULT NULL,
  `is_sensitive` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cat_project` (`project_id`,`sort_order`),
  CONSTRAINT `document_categories_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `document_categories` DISABLE KEYS */;
INSERT INTO `document_categories` VALUES
(1,2,'Juridique',NULL,0,1,'2026-10-25 13:58:46'),
(2,2,'Compta',NULL,0,2,'2026-10-25 13:58:46'),
(3,2,'Notaire',NULL,0,3,'2026-10-25 13:58:46'),
(4,2,'Prévisionnel & Analyse',NULL,0,4,'2026-10-25 13:58:46'),
(5,2,'Devis',NULL,0,5,'2026-10-25 13:58:46'),
(6,2,'Plan & Diagnostique',NULL,0,6,'2026-10-25 13:58:46');
/*!40000 ALTER TABLE `document_categories` ENABLE KEYS */;
DROP TABLE IF EXISTS `financial_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `financial_entries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `period_date` date NOT NULL,
  `revenus` decimal(12,2) NOT NULL DEFAULT 0.00,
  `charges` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_project_period` (`project_id`,`period_date`),
  CONSTRAINT `financial_entries_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `financial_entries` DISABLE KEYS */;
/*!40000 ALTER TABLE `financial_entries` ENABLE KEYS */;
DROP TABLE IF EXISTS `fixed_charges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fixed_charges` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `label` varchar(150) NOT NULL,
  `category` enum('assurance','energie','telecom','entretien','abonnement','loyer','pret','honoraires','autre') NOT NULL DEFAULT 'autre',
  `fournisseur` varchar(150) DEFAULT NULL,
  `montant` decimal(10,2) NOT NULL DEFAULT 0.00,
  `frequence` enum('mensuel','trimestriel','semestriel','annuel') NOT NULL DEFAULT 'mensuel',
  `date_reference` date NOT NULL,
  `preavis_jours` int(11) NOT NULL DEFAULT 0,
  `date_fin` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_charges_project` (`project_id`,`is_active`),
  CONSTRAINT `fixed_charges_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `fixed_charges` DISABLE KEYS */;
/*!40000 ALTER TABLE `fixed_charges` ENABLE KEYS */;
DROP TABLE IF EXISTS `laddition_order_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `laddition_order_lines` (
  `id_company` int(10) unsigned NOT NULL,
  `id_ligne_vente` varchar(64) NOT NULL,
  `etablissement` varchar(255) DEFAULT NULL,
  `heure_vente` datetime DEFAULT NULL,
  `heure_vente_raw` varchar(40) DEFAULT NULL,
  `type_produit` varchar(100) DEFAULT NULL,
  `categorie_produit` varchar(100) DEFAULT NULL,
  `id_produit` varchar(64) DEFAULT NULL,
  `nom_produit` varchar(255) DEFAULT NULL,
  `taux_tva` decimal(5,3) DEFAULT NULL,
  `nom_tva` varchar(20) DEFAULT NULL,
  `ht` decimal(12,2) DEFAULT NULL,
  `tva` decimal(12,2) DEFAULT NULL,
  `ttc` decimal(12,2) DEFAULT NULL,
  `id_parent` varchar(64) DEFAULT NULL,
  `id_salle` varchar(64) DEFAULT NULL,
  `nom_salle` varchar(255) DEFAULT NULL,
  `synced_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_company`,`id_ligne_vente`),
  KEY `idx_heure` (`id_company`,`heure_vente`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `laddition_order_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `laddition_order_lines` ENABLE KEYS */;
DROP TABLE IF EXISTS `pennylane_factures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pennylane_factures` (
  `id` bigint(20) NOT NULL,
  `sens` enum('fournisseur','client') NOT NULL,
  `societe` varchar(100) NOT NULL,
  `societe_id` bigint(20) DEFAULT NULL,
  `numero` varchar(100) DEFAULT NULL,
  `tiers` varchar(255) DEFAULT NULL,
  `libelle` varchar(500) DEFAULT NULL,
  `date_facture` date DEFAULT NULL,
  `echeance` date DEFAULT NULL,
  `ht` decimal(14,2) DEFAULT NULL,
  `tva` decimal(14,2) DEFAULT NULL,
  `ttc` decimal(14,2) DEFAULT NULL,
  `reste_a_payer` decimal(14,2) DEFAULT NULL,
  `statut_paiement` varchar(40) DEFAULT NULL,
  `payee` tinyint(1) DEFAULT NULL,
  `date_paiement` date DEFAULT NULL,
  `avoir` tinyint(1) DEFAULT NULL,
  `archivee` tinyint(1) DEFAULT NULL,
  `tiers_id` bigint(20) DEFAULT NULL,
  `synced_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_soc_date` (`societe_id`,`date_facture`),
  KEY `idx_societe` (`societe`,`sens`,`date_facture`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `pennylane_factures` DISABLE KEYS */;
/*!40000 ALTER TABLE `pennylane_factures` ENABLE KEYS */;
DROP TABLE IF EXISTS `pennylane_imports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pennylane_imports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fichier` varchar(255) DEFAULT NULL,
  `nb_factures` int(11) NOT NULL DEFAULT 0,
  `nb_transactions` int(11) NOT NULL DEFAULT 0,
  `donnees_du` date DEFAULT NULL,
  `donnees_au` date DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `imported_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `pennylane_imports` DISABLE KEYS */;
/*!40000 ALTER TABLE `pennylane_imports` ENABLE KEYS */;
DROP TABLE IF EXISTS `pennylane_societes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pennylane_societes` (
  `societe` varchar(100) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`societe`),
  KEY `project_id` (`project_id`),
  CONSTRAINT `pennylane_societes_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `pennylane_societes` DISABLE KEYS */;
/*!40000 ALTER TABLE `pennylane_societes` ENABLE KEYS */;
DROP TABLE IF EXISTS `pennylane_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pennylane_transactions` (
  `id` bigint(20) NOT NULL,
  `societe` varchar(100) NOT NULL,
  `societe_id` bigint(20) DEFAULT NULL,
  `date_operation` date NOT NULL,
  `libelle` varchar(500) DEFAULT NULL,
  `montant` decimal(14,2) NOT NULL,
  `devise` char(3) DEFAULT NULL,
  `compte` varchar(100) DEFAULT NULL,
  `tiers` varchar(255) DEFAULT NULL,
  `statut` varchar(40) DEFAULT NULL,
  `nb_justificatifs` int(11) DEFAULT NULL,
  `justificatif_requis` tinyint(1) DEFAULT NULL,
  `synced_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_soc_date` (`societe_id`,`date_operation`),
  KEY `idx_societe` (`societe`,`date_operation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `pennylane_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `pennylane_transactions` ENABLE KEYS */;
DROP TABLE IF EXISTS `popina_caisses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `popina_caisses` (
  `id_etablissement` int(10) unsigned NOT NULL,
  `jour_caisse` date NOT NULL,
  `ouverture` datetime NOT NULL,
  `fermeture` datetime DEFAULT NULL,
  `appareil` varchar(50) DEFAULT NULL,
  `brut_ttc` decimal(12,2) DEFAULT NULL,
  `brut_ht` decimal(12,3) DEFAULT NULL,
  `brut_tva` decimal(12,3) DEFAULT NULL,
  `reductions_ttc` decimal(12,2) DEFAULT NULL,
  `net_ttc` decimal(12,2) DEFAULT NULL,
  `net_ht` decimal(12,3) DEFAULT NULL,
  `net_tva` decimal(12,3) DEFAULT NULL,
  `pourboires` decimal(12,2) DEFAULT NULL,
  `couverts` int(11) DEFAULT NULL,
  `commandes` int(11) DEFAULT NULL,
  `mouvements` decimal(12,2) DEFAULT NULL,
  `ecart` decimal(12,2) DEFAULT NULL,
  `detail_json` longtext DEFAULT NULL,
  `synced_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_etablissement`,`ouverture`),
  KEY `idx_jour` (`id_etablissement`,`jour_caisse`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `popina_caisses` DISABLE KEYS */;
/*!40000 ALTER TABLE `popina_caisses` ENABLE KEYS */;
DROP TABLE IF EXISTS `popina_caisses_paiements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `popina_caisses_paiements` (
  `id_etablissement` int(10) unsigned NOT NULL,
  `jour_caisse` date NOT NULL,
  `ouverture` datetime NOT NULL,
  `mode` varchar(100) NOT NULL,
  `montant` decimal(12,2) DEFAULT NULL,
  PRIMARY KEY (`id_etablissement`,`ouverture`,`mode`),
  KEY `idx_jour` (`id_etablissement`,`jour_caisse`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `popina_caisses_paiements` DISABLE KEYS */;
/*!40000 ALTER TABLE `popina_caisses_paiements` ENABLE KEYS */;
DROP TABLE IF EXISTS `popina_caisses_tva`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `popina_caisses_tva` (
  `id_etablissement` int(10) unsigned NOT NULL,
  `jour_caisse` date NOT NULL,
  `ouverture` datetime NOT NULL,
  `type` enum('vente','remise') NOT NULL,
  `libelle` varchar(50) NOT NULL,
  `ttc` decimal(12,2) DEFAULT NULL,
  `ht` decimal(12,3) DEFAULT NULL,
  `tva` decimal(12,3) DEFAULT NULL,
  PRIMARY KEY (`id_etablissement`,`ouverture`,`type`,`libelle`),
  KEY `idx_jour` (`id_etablissement`,`jour_caisse`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `popina_caisses_tva` DISABLE KEYS */;
/*!40000 ALTER TABLE `popina_caisses_tva` ENABLE KEYS */;
DROP TABLE IF EXISTS `popina_lignes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `popina_lignes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_etablissement` int(10) unsigned NOT NULL,
  `jour_caisse` date NOT NULL,
  `ouverture_caisse` datetime DEFAULT NULL,
  `num_ligne` int(11) NOT NULL,
  `ticket_id` bigint(20) NOT NULL,
  `ticket_date` datetime DEFAULT NULL,
  `num_fiscal` varchar(30) DEFAULT NULL,
  `salle` varchar(100) DEFAULT NULL,
  `table_nom` varchar(30) DEFAULT NULL,
  `serveur` varchar(100) DEFAULT NULL,
  `date_ligne` datetime DEFAULT NULL,
  `type_ligne` enum('article','paiement','rendu','total') NOT NULL,
  `categorie_parent` varchar(100) DEFAULT NULL,
  `categorie` varchar(100) DEFAULT NULL,
  `nom` varchar(255) DEFAULT NULL,
  `prix_unitaire` decimal(12,2) DEFAULT NULL,
  `tarif` varchar(30) DEFAULT NULL,
  `taux_tva` decimal(5,2) DEFAULT NULL,
  `quantite` decimal(10,2) DEFAULT NULL,
  `annule` tinyint(1) NOT NULL DEFAULT 0,
  `transfere` tinyint(1) NOT NULL DEFAULT 0,
  `brut_ttc` decimal(12,2) DEFAULT NULL,
  `offert_ttc` decimal(12,2) DEFAULT NULL,
  `net_ttc` decimal(12,2) DEFAULT NULL,
  `tva` decimal(12,3) DEFAULT NULL,
  `ht` decimal(12,3) DEFAULT NULL,
  `synced_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_jour` (`id_etablissement`,`jour_caisse`),
  KEY `idx_ticket` (`ticket_id`),
  KEY `idx_type` (`type_ligne`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `popina_lignes` DISABLE KEYS */;
/*!40000 ALTER TABLE `popina_lignes` ENABLE KEYS */;
DROP TABLE IF EXISTS `project_associates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_associates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `role` varchar(100) DEFAULT NULL,
  `percentage` decimal(5,2) DEFAULT NULL,
  `contact_email` varchar(150) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `project_id` (`project_id`),
  CONSTRAINT `project_associates_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `project_associates` DISABLE KEYS */;
INSERT INTO `project_associates` VALUES
(1,2,'Associé non identifié','associé',49.00,NULL,'2026-10-25 13:58:46');
/*!40000 ALTER TABLE `project_associates` ENABLE KEYS */;
DROP TABLE IF EXISTS `project_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id_a` int(11) NOT NULL,
  `project_id_b` int(11) NOT NULL,
  `link_type` enum('murs_exploitation','detention') NOT NULL,
  `percentage` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_id_a` (`project_id_a`),
  KEY `project_id_b` (`project_id_b`),
  CONSTRAINT `project_links_ibfk_1` FOREIGN KEY (`project_id_a`) REFERENCES `projects` (`id`),
  CONSTRAINT `project_links_ibfk_2` FOREIGN KEY (`project_id_b`) REFERENCES `projects` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `project_links` DISABLE KEYS */;
INSERT INTO `project_links` VALUES
(1,1,2,'detention',51.00);
/*!40000 ALTER TABLE `project_links` ENABLE KEYS */;
DROP TABLE IF EXISTS `project_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `project_types` DISABLE KEYS */;
INSERT INTO `project_types` VALUES
(1,'holding','Holding'),
(2,'immobilier','Immobilier'),
(3,'restauration_murs','Restauration - Murs'),
(4,'restauration_exploitation','Restauration - Exploitation'),
(5,'distribution','Distribution'),
(6,'mdb','Marchand de biens');
/*!40000 ALTER TABLE `project_types` ENABLE KEYS */;
DROP TABLE IF EXISTS `project_user_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_user_access` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `access_level` enum('read','write') NOT NULL DEFAULT 'read',
  `scope` enum('all','dashboard','documents') NOT NULL DEFAULT 'all',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_project_user` (`project_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `project_user_access_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `project_user_access_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `project_user_access` DISABLE KEYS */;
/*!40000 ALTER TABLE `project_user_access` ENABLE KEYS */;
DROP TABLE IF EXISTS `projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `project_type_id` int(11) NOT NULL,
  `status` enum('active','archived') NOT NULL DEFAULT 'active',
  `siret_siren` varchar(20) DEFAULT NULL,
  `adresse` varchar(255) DEFAULT NULL,
  `surface_m2` decimal(8,2) DEFAULT NULL,
  `valeur_bien` decimal(12,2) DEFAULT NULL,
  `montant_emprunt` decimal(12,2) DEFAULT NULL,
  `solde_bancaire` decimal(12,2) DEFAULT NULL,
  `solde_maj_le` date DEFAULT NULL,
  `lots_total` int(11) DEFAULT NULL,
  `lots_loues` int(11) DEFAULT NULL,
  `affaires_en_cours` int(11) DEFAULT NULL,
  `documents_url` varchar(500) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `project_type_id` (`project_type_id`),
  CONSTRAINT `projects_ibfk_1` FOREIGN KEY (`project_type_id`) REFERENCES `project_types` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `projects` DISABLE KEYS */;
INSERT INTO `projects` VALUES
(1,'AD Holding',1,'active','888 849 346 00026',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-10-25 13:58:46'),
(2,'SCI Paul Dubois',2,'active','522 055 458 00023','1 Rue Paul Dubois, Nogent-sur-Seine',140.00,350000.00,101156.00,NULL,NULL,NULL,NULL,NULL,NULL,'Immeuble locatif, 5 lots.','2026-10-25 13:58:46'),
(3,'AGD INVEST',2,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-10-25 13:58:47'),
(4,'ALD IMMO',6,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-10-25 13:58:47'),
(5,'ARNAUD DUVIVIER',1,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-10-25 13:58:47'),
(6,'COUSIMMO',2,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-10-25 13:58:47'),
(7,'SAS DLM',6,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-10-25 13:58:47'),
(8,'SCI GENERAL DE GAULLE',2,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-10-25 13:58:47'),
(9,'FACTORY',2,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-10-25 13:58:47'),
(10,'SAS JAURES / CHEZ COME',4,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-10-25 13:58:47'),
(11,'LE BELLEVUE',4,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-10-25 13:58:47'),
(18,'SCI HOTEL DIEU',2,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-10-25 13:58:47'),
(19,'SCI CASA NOSTRA',2,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-10-25 13:58:47'),
(20,'SCI CAPILOC',2,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-10-25 13:58:47'),
(21,'SCI DES PONTS',2,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-10-25 13:58:47'),
(22,'SAS LE VALENTINO',4,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-10-25 13:58:47'),
(23,'SCI LE VALENTINO',3,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-10-25 13:58:47'),
(24,'SCI LEOPOLD',3,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-10-25 13:58:47'),
(25,'SCI DTL',3,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-10-25 13:58:47'),
(26,'SCI JAURES',3,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-10-25 13:58:47');
/*!40000 ALTER TABLE `projects` ENABLE KEYS */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES
(1,'direction','Direction'),
(2,'associe','Associé'),
(3,'gestionnaire','Gestionnaire'),
(4,'externe','Externe');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
DROP TABLE IF EXISTS `sync_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sync_runs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `source` varchar(30) NOT NULL,
  `account` varchar(100) NOT NULL,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `nb_lines` int(11) NOT NULL DEFAULT 0,
  `status` enum('ok','error') NOT NULL,
  `message` text DEFAULT NULL,
  `run_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_run` (`run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `sync_runs` DISABLE KEYS */;
/*!40000 ALTER TABLE `sync_runs` ENABLE KEYS */;
DROP TABLE IF EXISTS `user_recovery_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_recovery_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `code_hash` char(64) NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `user_recovery_codes_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `user_recovery_codes` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_recovery_codes` ENABLE KEYS */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `totp_secret` varchar(64) DEFAULT NULL,
  `totp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `totp_enabled_at` datetime DEFAULT NULL,
  `totp_last_step` bigint(20) DEFAULT NULL,
  `totp_failures` int(11) NOT NULL DEFAULT 0,
  `totp_locked_until` datetime DEFAULT NULL,
  `role_id` int(11) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES
(1,'Arnaud Duvivier','arnaud@example.com','$2y$12$N6QspxipPkkofC0VHTYLN.Fl3xbZS2YLMMpq.rdki7rFM1/RvI9CW',NULL,0,NULL,NULL,0,NULL,1,1,'2026-10-25 13:58:46');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

