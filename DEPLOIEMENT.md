# Déploiement d'AD'HUB sur o2switch

Procédure complète, à suivre dans l'ordre. Compter une heure la première fois.

---

## 1. Créer le sous-domaine

Dans **cPanel → Domaines → Sous-domaines** :

- Sous-domaine : `adhub`
- Domaine : `duvivier-access.fr` (ou le domaine du groupe)
- Racine du document : cPanel propose `/adhub` — **laisser tel quel**

Le dossier `/adhub` est créé automatiquement dans ton espace. C'est là que le code ira.

> Le sous-domaine met parfois quelques minutes à répondre, le temps de la propagation DNS.

---

## 2. Vérifier la version de PHP

**cPanel → Logiciels → Sélectionner une version de PHP**

- Choisir **PHP 8.2** ou **8.3**
- Onglet **Extensions** : cocher `pdo_mysql`, `mbstring`, `openssl`
- Cocher aussi **`imap`** — nécessaire plus tard pour l'import automatique des courriels
- Enregistrer

---

## 3. Créer la base de données

**cPanel → Bases de données → Assistant Base de données MySQL**

1. Nom de la base : `adhub`
   → cPanel la préfixe automatiquement, ce qui donne par exemple `abcd1234_adhub`
2. Créer un utilisateur : `adhub`
   → même préfixe : `abcd1234_adhub`
3. **Générer un mot de passe** avec le bouton de cPanel, et le copier immédiatement quelque part : il ne sera plus affiché ensuite
4. Privilèges : cocher **TOUS LES PRIVILÈGES**

> Ne pas réutiliser la base de la data room : les deux projets doivent rester isolés.

**Noter les trois valeurs** — nom de base, utilisateur, mot de passe — elles servent à l'étape 5.

---

## 4. Envoyer les fichiers

**cPanel → Fichiers → Gestionnaire de fichiers**, aller dans le dossier `/adhub`.

Envoyer le contenu du dossier **`src/`** — et non le dossier `src` lui-même.

À la fin, `/adhub` doit contenir directement :

```
/adhub
   .htaccess
   index.php
   /config
   /inc
   /pages
```

> Le gestionnaire de fichiers masque les fichiers commençant par un point.
> Activer **Paramètres → Afficher les fichiers cachés** pour voir les `.htaccess`.
> Ils sont indispensables : sans eux, les dossiers `config` et `inc` seraient accessibles depuis le web.

Ne pas envoyer : `docker-compose.yml`, `README.md`, ni le dossier `database/`.

---

## 5. Créer le fichier de configuration

Dans `/adhub/config`, à l'aide du gestionnaire de fichiers :

1. Copier `config.local.php.exemple` en **`config.local.php`**
2. L'ouvrir avec **Modifier** et renseigner les valeurs de l'étape 3 :

```php
return [
    'db' => [
        'host' => 'localhost',
        'name' => 'abcd1234_adhub',
        'user' => 'abcd1234_adhub',
        'pass' => 'le-mot-de-passe-généré',
    ],
];
```

3. Enregistrer

> Ce fichier ne doit **jamais** être versionné ni recopié vers le poste de développement.

---

## 6. Importer la base de données

**cPanel → Bases de données → phpMyAdmin**, sélectionner la base `abcd1234_adhub`.

Onglet **Importer**, puis exécuter les fichiers **dans cet ordre strict** :

1. `schema.sql`
2. `002_financial_entries.sql`
3. `003_project_details.sql`
4. `004_users_access.sql`
5. `005_documents.sql`
6. `006_daily_entries.sql`
7. `007_mdb_et_kpi.sql`
8. `008_charges_fixes.sql`
9. `009_rapport_caisse.sql`
10. `010_restant_entreprises.sql`
11. `011_caisses.sql`
12. `012_double_authentification.sql` (double authentification : à passer **avant** de mettre en ligne les fichiers qui l'utilisent, sinon la connexion échoue)
13. `013_pennylane.sql` (tables de l'import Pennylane ; à passer avant ou après le script SQL généré avec l'export, les deux sont compatibles)

Chaque fichier s'appuie sur les précédents : un ordre différent provoquera des erreurs.

> Les migrations contiennent des données de démonstration. Elles sont là pour vérifier
> que tout fonctionne — à supprimer une fois les vraies données saisies.

---

## 7. Activer le certificat SSL

o2switch installe Let's Encrypt automatiquement, mais pas toujours immédiatement sur un
sous-domaine récent.

**cPanel → Sécurité → SSL/TLS Status** : si le sous-domaine n'est pas couvert,
cliquer sur **Run AutoSSL** et patienter quelques minutes.

Le `.htaccess` force déjà la redirection vers HTTPS.

---

## 8. Première connexion

Ouvrir `https://adhub.duvivier-access.fr`

Identifiants de départ :
- **arnaud@example.com**
- **Admin2026!**

### À faire immédiatement, avant toute autre chose

1. **Mon compte → changer le mot de passe.** Celui d'origine est public : il figure dans ce dépôt.
2. Dans phpMyAdmin, table `users` : remplacer `arnaud@example.com` par la vraie adresse d'Arnaud.
3. Vérifier que `https://adhub.duvivier-access.fr/config/config.local.php` renvoie bien une
   **erreur 403**. Si le fichier s'affiche, le `.htaccess` n'a pas été envoyé — corriger avant
   d'aller plus loin.

---

## 9. Sauvegardes

o2switch effectue des sauvegardes automatiques, mais mieux vaut ne pas dépendre uniquement d'elles.

**cPanel → Fichiers → Sauvegarde → Télécharger une sauvegarde de base de données MySQL**

À faire avant chaque mise à jour importante, et à conserver hors de l'hébergement.

---

## Mettre à jour l'application par la suite

### Automatiquement (par défaut)

Chaque fusion dans `main` déclenche l'action GitHub **Mise en ligne**
(`.github/workflows/deploiement.yml`) :

1. vérification de la syntaxe de tous les fichiers PHP (aussi sur chaque PR) ;
2. envoi par FTPS (lftp) des fichiers de `src/` dans `~/adhub.fr` ;
3. les fichiers `*.local.php`, journaux et fichiers propres au serveur ne sont jamais
   envoyés, écrasés ni supprimés.

Prérequis, une seule fois : un compte FTP dédié dans **cPanel → Fichiers → Comptes FTP**,
limité au dossier `adhub.fr`, et trois secrets dans GitHub
(**Settings → Secrets and variables → Actions**) : `FTP_SERVER`, `FTP_USERNAME`,
`FTP_PASSWORD`. Sans ces secrets, l'action ne met rien en ligne et le signale.

**Les migrations SQL ne sont pas exécutées automatiquement.** Quand une fusion ajoute un
fichier dans `database/`, le résumé de l'action l'indique : sauvegarder la base, puis
l'importer dans phpMyAdmin. Si une migration est nécessaire au nouveau code, la passer
juste après la mise en ligne.

### À la main (secours)

1. Sauvegarder la base (étape 9)
2. Remplacer les fichiers modifiés dans `/adhub`
3. **Ne jamais écraser `config/config.local.php`**
4. Exécuter les nouvelles migrations dans phpMyAdmin, dans l'ordre

---

## En cas de problème

| Symptôme | Cause probable |
|---|---|
| Page blanche | Erreur PHP — voir **cPanel → Métriques → Erreurs** |
| « Service momentanément indisponible » | Identifiants incorrects dans `config.local.php` |
| Erreur 403 sur tout le site | `.htaccess` mal placé, ou `/adhub` vide |
| Redirection en boucle | Certificat SSL pas encore actif — refaire l'étape 7 |
| Le fichier `config.local.php` s'affiche | `.htaccess` de `/config` absent — **à corriger tout de suite** |
| Erreur SQL à l'import | Migrations exécutées dans le désordre |
| Les codes de connexion n'arrivent pas par e-mail | Voir « Double authentification par e-mail » ci-dessous |

---

## Double authentification par e-mail

Chaque connexion depuis un nouvel appareil demande un code à 6 chiffres envoyé par e-mail
(valable 10 minutes ; l'appareil peut être retenu 30 jours). Aucune migration, aucun service payant :
l'envoi passe par la fonction `mail()` d'o2switch, depuis `ne-pas-repondre@ad-hub.fr`.

- Pour changer l'expéditeur : bloc `'mail'` de `config.local.php` (voir `config.local.php.exemple`).
  L'adresse doit être sur un domaine hébergé sur le compte o2switch, sinon les messages finissent en indésirables.
- Un compte sans adresse e-mail valide (`.invalid`, `example.com`…) ne peut plus se connecter :
  badge « E-mail à corriger » dans Administration › Utilisateurs.
- **Secours si plus personne ne reçoit de code** : dans cPanel › Gestionnaire de fichiers, ouvrir
  `adhub.fr/config/config.local.php` et ajouter `'double_auth_email' => false,` dans le tableau.
  La connexion repasse au mot de passe seul. Retirer la ligne dès que l'envoi refonctionne.

## Chacun choisit son mot de passe (lien par e-mail)

Administration › Utilisateurs › « Laisser chacun choisir son mot de passe » : envoie à chaque compte
un lien sécurisé (valable 7 jours, utilisable une seule fois) pour choisir lui-même son mot de passe.
Bouton par compte (« Envoyer le lien mot de passe ») ou envoi groupé (comptes sans mot de passe, ou
tous les comptes actifs sauf soi). La page de connexion propose aussi « Mot de passe oublié ? »
(lien valable 1 heure, message identique que le compte existe ou non).

- Aucune migration : le lien est signé avec l'empreinte actuelle du mot de passe et le mot de passe
  de la base ; il cesse de marcher dès que le mot de passe a été choisi.
- Expéditeur : `contact@ad-hub.fr` par défaut. **Cette boîte doit exister** dans cPanel ›
  Comptes de messagerie (sinon les e-mails partent mal et les réponses se perdent). Pour en changer :
  `'expediteur_comptes'` dans le bloc `'mail'` de `config.local.php`.
- Les liens pointent vers `https://ad-hub.fr` (modifiable avec `'url_site'`).
