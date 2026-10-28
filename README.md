# Tableau de bord multi-sociétés — démarrage local

## Prérequis
Docker Desktop (ou Docker Engine + Docker Compose) installé.

## Lancer le projet

```bash
docker compose up
```

Puis ouvrir :
- **http://localhost:8080** → l'application (page de test qui liste les types de projets et les projets)
- **http://localhost:8081** → Adminer (interface web pour voir les tables). Se connecter avec :
  - Système : MySQL
  - Serveur : `db`
  - Utilisateur : `root`
  - Mot de passe : `root`
  - Base de données : `dashboard`

Au premier démarrage, le fichier `database/schema.sql` est exécuté automatiquement et crée les tables `project_types`, `projects`, `project_links`, avec quelques données de démonstration.

## Arrêter le projet

```bash
docker compose down
```

Pour repartir d'une base vide (efface les données) :

```bash
docker compose down -v
rm -rf data
```

## Structure

```
src/config/db.php     → connexion PDO
src/inc/projects.php  → fonctions de la brique générique "projet"
src/pages/index.php   → page de test
database/schema.sql   → schéma + données de démo
```

## Interface

- Navigation en haut (plus de menu latéral) : vue d'ensemble, sélecteur de société avec recherche
  (taper un nom puis Entrée), menu Administration (direction) et menu du compte.
- Chaque société a ses onglets : tableau de bord, analyse des ventes, évolution, saisie, charges,
  documents, fiche. Seuls les onglets autorisés pour l'utilisateur s'affichent.
- Thème : `src/assets/adhub.css` (couleurs en variables en haut du fichier), réglages des graphiques
  et recherche du menu : `src/assets/adhub.js`. Briques d'affichage partagées (montants, variations,
  tuiles d'indicateurs, en-tête société) : `src/inc/ui.php`. Indicateurs consolidés : `src/inc/indicateurs.php`.
- Bootstrap 5.3.3, Chart.js 4.4.1 et la police Inter sont servis depuis `src/assets/` : plus aucun
  appel à un CDN, la page s'affiche même si un CDN est bloqué ou lent.

## Appliquer une migration

Le premier démarrage a déjà exécuté `database/schema.sql`. Pour les fichiers de migration suivants (ex. `002_financial_entries.sql`), la base tourne déjà donc ils ne s'exécutent pas tout seuls. Deux façons de les appliquer :

**Option 1 — en une commande**, depuis le dossier du projet :
```bash
docker compose exec -T db mysql -uroot -proot dashboard < database/002_financial_entries.sql
```

**Option 2 — via Adminer** (http://localhost:8081, connexion : serveur `db`, utilisateur `root`, mot de passe `root`, base `dashboard`) : onglet **Requête SQL**, coller le contenu du fichier, exécuter.

## Comptes utilisateurs

Après avoir appliqué `004_users_access.sql`, connecte-toi avec :

- **Email** : `arnaud@example.com`
- **Mot de passe** : `Admin2026!`

**À faire dès la première connexion** : va dans « Mon compte » et change ce mot de passe. Change aussi l'email dans la table `users` via Adminer pour mettre la vraie adresse d'Arnaud.

### Comment fonctionnent les accès

- Le rôle **Direction** voit toutes les sociétés automatiquement, plus la vue globale et l'administration. Aucun accès à attribuer manuellement.
- Tous les autres rôles ne voient **que** les sociétés explicitement attribuées dans « Utilisateurs ». Sans attribution, un compte ne voit rien.
- Chaque accès a un **droit** (lecture seule / lecture + modification) et une **portée** (tout, dashboard seulement, documents seulement).

### Tester le cloisonnement

1. Crée un utilisateur avec le rôle « Associé ».
2. Donne-lui accès à une seule société.
3. Déconnecte-toi, reconnecte-toi avec ce compte : il ne doit voir que cette société, et la vue globale ne doit pas apparaître dans le menu.
4. Essaie d'ouvrir directement l'URL d'une autre société (`/pages/project_dashboard.php?id=X`) : tu dois obtenir « Vous n'avez pas accès à cette société ».

L'étape 4 est la plus importante — c'est elle qui vérifie que le cloisonnement est réel et pas seulement visuel.

## Documents

Après avoir appliqué `005_documents.sql`, chaque société dispose d'une page Documents
(`/pages/project_documents.php?id=X`, accessible depuis le bouton « Documents » sur le dashboard ou la fiche).

- Chaque **catégorie** correspond à un dossier OneDrive : on renseigne son lien, l'outil ne stocke aucun fichier.
- Les catégories SCI sont pré-créées : Juridique, Compta, Notaire, Prévisionnel & Analyse, Devis, Plan & Diagnostique.
- Les catégories restaurants sont pré-remplies avec une proposition **à confirmer avec les équipes** — elles sont modifiables directement dans l'interface.
- Une catégorie peut être marquée **sensible** (RH, données personnelles) pour la repérer visuellement.

### Journalisation

Toute ouverture de dossier passe par `open_document.php`, qui vérifie les droits **avant** de révéler l'URL OneDrive, puis enregistre l'accès. La direction consulte l'historique via « Journal des accès ».

Limite à connaître : l'outil trace l'ouverture du lien depuis l'application. Ce qui se passe ensuite sur OneDrive (téléchargement, retransmission du lien) n'est pas visible ici — c'est à régler dans les paramètres de partage OneDrive.

## Saisie quotidienne (restaurants)

Après avoir appliqué `006_daily_entries.sql`, les sociétés de type « Restauration - Exploitation »
affichent un bouton **Saisie quotidienne** sur leur dashboard.

- Formulaire volontairement minimal : date, CA TTC, couverts. Le **ticket moyen se calcule** (CA / couverts), il n'est jamais stocké — comme ça une correction du CA ou des couverts reste toujours cohérente.
- Une seule ligne par restaurant et par jour : ressaisir une date déjà remplie **corrige** la valeur au lieu d'en créer une deuxième.
- La date est pré-remplie sur la veille si elle n'est pas encore saisie (cas le plus fréquent le matin).
- Les **jours manquants** du mois sont signalés. Pour un jour de fermeture, saisir 0 pour le marquer comme traité — l'outil ne peut pas deviner la différence entre « fermé » et « oublié ».
- Le dashboard du restaurant affiche : CA du jour, couverts, ticket moyen, cumul du mois, et un graphique des 7 derniers jours saisis.

### Prêt pour l'automatisation

La table `daily_entries` porte une colonne `source` (`manuel` / `email` / `api`). Les trois canaux
écrivent au même endroit, donc brancher plus tard l'import de fichier ou l'API Popina ne demandera
**aucun changement du dashboard** — juste un script qui appelle `upsert_daily_entry()` avec la bonne
valeur de `source`.

## Vue globale (refonte)

`007_mdb_et_kpi.sql` aligne l'application sur ce qui a été présenté à Arnaud :

- **Nouveau type « Marchand de biens »** (MDB), qui n'existait pas en base.
- **Trois familles** dans la vue globale, chacune avec ses propres colonnes :
  - *Immobilier locatif* → solde du compte bancaire · taux d'occupation en fraction (« 4/5 logements loués », pas de pourcentage)
  - *Restaurants* → trésorerie · CA du mois (calculé depuis les saisies quotidiennes)
  - *Marchands de biens* → trésorerie · nombre d'affaires en cours
- Une quatrième section **Autres structures** récupère les sociétés qui n'entrent dans aucune des trois (holding, nom propre). Elles restent visibles plutôt que d'être masquées.
- Les indicateurs se saisissent sur la fiche société, bloc « Indicateurs de pilotage ».
- Un solde arrêté à plus de 30 jours est marqué **ancien** dans la vue globale.

### Sociétés créées

Les sociétés relevées dans Pennylane sont insérées automatiquement (sans doublon avec les données de démonstration). **Quatre types sont déduits, pas certains** — voir les commentaires en fin de `007_mdb_et_kpi.sql` : `ARNAUD DUVIVIER`, `DU GENERAL DE GAULLE`, `JAURES`, ainsi que le classement de `ALD IMMO` et `DLM` en MDB. Le type se corrige depuis la fiche société, sans migration.

Les soldes et taux d'occupation insérés sont des **valeurs de démonstration**, à remplacer par les vrais chiffres.

## Double authentification (2FA)

Migration `012_double_authentification.sql`. Code à 6 chiffres d'une application d'authentification
(Google Authenticator, Microsoft Authenticator, 1Password, Bitwarden…), demandé après le mot de passe.

- **Activation** : menu du compte → *Double authentification* : QR code (dessiné dans le navigateur,
  le secret ne sort pas d'ADHUB) ou clé à saisir, puis confirmation par un premier code.
  10 **codes de secours** à usage unique sont alors affichés une seule fois (copier, télécharger, imprimer).
- **Obligatoire pour la direction** (`TOTP_ROLES_OBLIGATOIRES` dans `src/inc/double_auth.php`) :
  à sa prochaine connexion, un compte direction sans 2FA est conduit à l'activation et ne peut rien
  ouvrir d'autre avant. Facultative pour les autres rôles.
- **Sécurité** : un code ne sert qu'une fois (anti-rejeu), tolérance de ± 30 s, 5 essais par connexion,
  blocage de 15 min après 8 codes faux, seule une empreinte des codes de secours est stockée.
- **Téléphone perdu** : se connecter avec un code de secours, puis *Changer de téléphone*. Sans code
  de secours : un autre compte direction clique *Réinitialiser la 2FA* dans *Utilisateurs et accès*.
  En dernier recours (seul compte direction) : `UPDATE users SET totp_enabled = 0 WHERE email = '…';` via Adminer/phpMyAdmin.
- L'heure du serveur doit être juste (c'est le cas sur o2switch) : les codes dépendent de l'heure.

## Charges fixes et dates anniversaires

`008_charges_fixes.sql` ajoute le suivi des charges récurrentes, accessible via le bouton
**Charges** sur le dashboard de chaque société.

L'intérêt n'est pas de lister des montants, c'est de **prévenir avant la date limite de résiliation** :
un contrat à reconduction tacite se renouvelle tout seul si personne n'a résilié dans le délai de préavis.

- On saisit la **prochaine échéance connue** et la **fréquence** ; toutes les échéances suivantes se calculent automatiquement (le cas « 31 du mois » est géré : 31 janvier + 1 mois → 28/29 février, pas le 3 mars).
- Le champ **préavis en jours** déclenche les alertes. À 0, aucune surveillance.
- Trois niveaux : *préavis dépassé* (rouge, le contrat va se reconduire), *à résilier sous 30 jours* (rouge), *préavis dans moins de 90 jours* (orange).
- Les montants sont **annualisés** pour être comparables entre eux — 95 €/mois et 480 €/an s'additionnent correctement.
- Une charge peut être **désactivée** plutôt que supprimée, pour garder l'historique d'un contrat résilié.

Les alertes urgentes de **toutes les sociétés** remontent en haut de la vue globale d'Arnaud, avec un lien direct vers la charge concernée.

## Vues J-1 / hebdo / mensuel

Bouton **Évolution** sur le dashboard d'un restaurant (`pages/evolution.php`). Trois onglets :
hier, semaine en cours, mois en cours — chacun comparé à l'année précédente.

Aucune migration : la page s'appuie sur `daily_entries`, déjà en place.

### Comment la comparaison N-1 est calculée

Pour les vues **hier** et **semaine**, la référence est prise **364 jours plus tôt** (52 semaines
pile), et non à la même date. Raison : dans un restaurant, le jour de la semaine pèse plus que la
date. Le 7 août 2026 est un vendredi, le 7 août 2025 un jeudi — les comparer fausserait la lecture.
Reculer de 364 jours conserve toujours le jour de la semaine, au prix d'un décalage d'un seul jour
dans le calendrier.

Pour la vue **mois**, on compare au vrai mois calendaire de l'an dernier (août contre août), mais
**tronqué au même nombre de jours écoulés** : comparer 7 jours de août 2026 à 31 jours de août 2025
afficherait une chute spectaculaire et fausse.

### Garde-fous

- Si la période N-1 ne contient aucune saisie, les comparaisons sont **masquées** plutôt qu'affichées à 0 %.
- Une variation à partir de zéro n'affiche pas de pourcentage (« +100 % » à partir de rien n'a pas de sens).
- La méthode employée est rappelée en bas de page, pour qu'un lecteur sache ce qu'il compare.

## Import des rapports de caisse

`009_rapport_caisse.sql` ajoute les champs du rapport quotidien reçu par courriel.
Page **Importer un rapport**, depuis la saisie quotidienne : on colle le texte du courriel,
on vérifie ce qui a été lu, on enregistre.

### Vocabulaire

Le rapport de caisse distingue deux notions que l'outil reprend telles quelles, pour que les
chiffres portent le même nom des deux côtés :

- **Panier moyen** = CA / couvert (recalculé par l'outil et recoupé avec la valeur annoncée)
- **Ticket moyen** = CA / addition (fourni par la caisse, non recalculable)

Sont également repris : CA HT, remises, offerts, corrections après envoi en cuisine, et la
répartition **solide / liquide** issue du tableau des ventes. Les catégories inconnues ne sont pas
rangées arbitrairement : elles sont signalées à l'import.

### Journées atypiques

Le rapport ne couvre que les **services clôturés en caisse**. Un service oublié produit une journée
vide ou partielle, que la journée suivante peut absorber. L'historique signale donc les journées
dont le CA s'écarte fortement de la médiane du **même jour de la semaine** (au moins trois
références nécessaires) : CA nul, moitié moins, ou 1,8 fois plus.

L'outil **signale sans corriger** : il ne peut pas distinguer un oubli de clôture d'une mauvaise
journée ou d'un jour de fermeture. La décision reste humaine.

## Caisses : synchronisation L'Addition et Popina

En attendant les clés API officielles, deux scripts se connectent chaque nuit aux back-offices
avec l'identifiant et le mot de passe du restaurant, récupèrent le détail des ventes et
remplissent la **saisie quotidienne** (source `api`, sans jamais écraser une saisie manuelle).
Le détail reste stocké pour la page **Analyse des ventes** (bouton sur le dashboard d'une société).

Ce que la page montre au dirigeant : CA TTC/HT et variation N-1, couverts, panier et ticket moyens,
part solide / liquide, CA par jour et par heure, midi / soir, jours de la semaine, catégories,
top et flop produits, salles, TVA, et pour Popina seulement : encaissements, serveurs, pourboires,
offerts et annulations. L'Addition ne fournit ni couverts, ni serveurs, ni encaissements.

### Installation

1. Appliquer la migration : `database/011_caisses.sql`.
2. Copier `src/config/caisses.local.php.exemple` en `config/caisses.local.php` (à côté de
   `config.local.php`, `chmod 600`, jamais versionné) et remplir les comptes. La clé
   `etablissements` relie chaque n° d'établissement de la caisse à l'id de la société dans ADHUB.
3. Vérifier la connexion et lire les n° d'établissement :
   `php cron/caisses/test_reel_laddition.php` et `php cron/caisses/test_reel_popina.php`.
4. Lancer une première reprise : `php cron/caisses/popina_sync.php 2026-10-01 2026-10-24`
   (idem `laddition_sync.php`). Ajouter `--force` pour remplacer des saisies non automatiques.
5. Menu **Caisses** (direction) : classer les nouvelles catégories en solide / liquide / autre.
6. Tâches cron cPanel (sans date, le script prend la veille) :

```
30 5 * * * /usr/local/bin/php ~/adhub/cron/caisses/laddition_sync.php >/dev/null 2>&1
40 5 * * * /usr/local/bin/php ~/adhub/cron/caisses/popina_sync.php >/dev/null 2>&1
```

Une journée de L'Addition va jusqu'à 5 h le lendemain (`CAISSE_HEURE_BASCULE`), d'où un passage à 5 h 30.
Les scripts sont idempotents : les relancer sur une période ne crée pas de doublon.
Chaque passage est tracé dans `sync_runs` et visible dans le menu **Caisses**.

### Limites

Ces back-offices n'ont pas d'API publique : un changement de leur site peut casser la
synchronisation (l'erreur apparaît alors dans le menu **Caisses**). Dès que les clés officielles
arrivent, `cron/sync_api.php` prend le relais.

### Tester sans les vrais comptes

`mock/` contient deux faux serveurs :
`php -S 127.0.0.1:8099 mock/laddition/router.php` et `php -S 127.0.0.1:8098 mock/popina/router.php`,
à pointer depuis un `caisses.local.php` de test (comptes `bellevue@test.fr` / `test123` et
`come@test.fr` / `popina123`).

## Comptabilité : import Pennylane

Pas de connexion automatique à Pennylane : la direction exporte les données à la main et les dépose
dans **Administration → Import Pennylane** (migration `013_pennylane.sql`).

- Le fichier Excel doit contenir les feuilles « Factures fournisseurs », « Factures clients » et
  « Transactions bancaires ». Les colonnes sont repérées par leur titre.
- Chaque ligne est reconnue par son identifiant Pennylane : réimporter un export plus récent met à
  jour sans doublon. Le script SQL généré avec l'export peut aussi être passé dans phpMyAdmin.
- Chaque société Pennylane est reliée à une société ADHUB. Les noms évidents le sont d'office ;
  les noms ambigus (ex. « JAURES » : SCI ou SAS) restent à choisir sur la page d'import.
- Une société reliée gagne un onglet **Comptabilité** : fournisseurs à payer (dont échus), achats HT,
  flux bancaires, opérations à justifier, principaux fournisseurs, factures ouvertes.
  La vue d'ensemble affiche le total à payer et les sociétés avec des factures échues.

## Objectifs de CA des restaurants

Migration : `database/016_objectifs.sql` (table `objectifs_ca`). Sans elle, les pages s'affichent sans objectifs.

- La direction fixe un CA TTC par mois et par restaurant (onglet **Objectifs** du restaurant). Aucune saisie pour les équipes : la progression vient des ventes de caisse (`daily_entries`).
- Jauge sur le tableau de bord du restaurant et dans son cadre de la vue d'ensemble, paliers 50 / 75 / 100 / 120 %, série de mois atteints d'affilée, bilan de l'année mois par mois.
- Animation (`assets/adhub.js`) : message à chaque nouveau palier, confettis à 100 %. Le plus haut palier vu est retenu dans le navigateur (localStorage) pour ne pas rejouer l'animation ; clic sur le badge doré pour la revoir. Rien ne bouge si le système demande de réduire les animations (`prefers-reduced-motion`).
- Jamais de classement ni de comparaison entre restaurants : chacun face à son propre objectif.
- Code : `src/inc/objectifs.php`, `src/pages/objectifs.php`.

## Prochaine étape

- Automatisation complète : boîte mail dédiée + cron IMAP appelant le même analyseur.
- Connecteur API Popina officiel (une clé par établissement).
- Déploiement sur l'hébergement.
