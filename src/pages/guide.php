<?php
/**
 * Guide d'utilisation d'ADHUB.
 *
 * Tout le contenu est dans le tableau $sections ci-dessous : pour documenter une
 * nouvelle fonctionnalité, il suffit d'ajouter (ou de modifier) une entrée.
 *   'pour' => 'tous'      : visible par tout le monde
 *   'pour' => 'direction' : visible seulement par la direction (administration, vue d'ensemble)
 */
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/ui.php';
require_login();

$sections = [
    [
        'id' => 'bienvenue', 'icon' => 'grid', 'pour' => 'tous',
        'titre' => "À quoi sert ADHUB",
        'html' => <<<HTML
<p>ADHUB réunit au même endroit les chiffres et les documents des sociétés du groupe : restaurants,
immobilier locatif (SCI), marchands de biens et holding. Ce n'est pas un logiciel de plus à remplir :
les ventes arrivent toutes seules depuis les caisses, la comptabilité depuis Pennylane, et les
documents restent dans OneDrive.</p>
<p>Chacun ne voit que les sociétés qui lui ont été attribuées par la direction.</p>
HTML,
    ],
    [
        'id' => 'connexion', 'icon' => 'key', 'pour' => 'tous',
        'titre' => "Se connecter et protéger son compte",
        'html' => <<<HTML
<ol class="guide-steps">
  <li>Connectez-vous avec l'adresse e-mail et le mot de passe reçus.</li>
  <li>Changez ce mot de passe dès la première connexion : menu de votre nom en haut à droite →
      <a href="/pages/mon_compte.php">Mon compte</a>.</li>
  <li>Activez la <a href="/pages/double_authentification.php">double authentification</a> :
      installez une application d'authentification sur votre téléphone (Google Authenticator,
      Microsoft Authenticator…), scannez le QR code, puis tapez le code à 6 chiffres affiché.</li>
  <li>Gardez précieusement les 10 <strong>codes de secours</strong> affichés à ce moment-là
      (ils ne sont montrés qu'une fois) : ils servent si vous perdez votre téléphone.</li>
</ol>
<p class="guide-note">La double authentification est obligatoire pour la direction et conseillée pour tous.
Téléphone perdu et plus de code de secours : demandez à la direction de la réinitialiser.</p>
HTML,
    ],
    [
        'id' => 'navigation', 'icon' => 'menu', 'pour' => 'tous',
        'titre' => "Se repérer",
        'html' => <<<HTML
<ul>
  <li><strong>Vue d'ensemble</strong> (direction) ou <strong>Mes sociétés</strong> : la page d'accueil,
      avec toutes vos sociétés.</li>
  <li><strong>Sociétés</strong> : la liste de vos sociétés, classées par pôle, avec une recherche.
      Tapez quelques lettres du nom pour la retrouver.</li>
  <li>Une fois dans une société, les <strong>onglets</strong> sous son nom donnent accès à ses pages :
      Tableau de bord, Analyse des ventes, Évolution, Charges fixes, Comptabilité, Documents, Fiche société.
      Seuls les onglets auxquels vous avez droit apparaissent.</li>
  <li>Sur téléphone, le menu s'ouvre avec le bouton ☰ en haut à droite.</li>
</ul>
HTML,
    ],
    [
        'id' => 'vue-ensemble', 'icon' => 'layers', 'pour' => 'direction',
        'titre' => "La vue d'ensemble",
        'html' => <<<HTML
<p>C'est la page d'accueil de la direction. En haut, les indicateurs du groupe :</p>
<ul>
  <li><strong>Trésorerie consolidée</strong> : la somme des soldes bancaires, avec le nombre de comptes à
      découvert. Un solde qui date de plus de 30 jours est signalé comme <em>ancien</em>.</li>
  <li><strong>Taux d'occupation</strong> des logements et lots, avec le nombre de vacants.</li>
  <li><strong>Résultat net</strong> de l'année et <strong>fournisseurs à payer</strong> (d'après Pennylane).</li>
</ul>
<p>Juste en dessous, un cadre rouge liste les <strong>échéances de contrats à traiter</strong> dans
toutes les sociétés.</p>
<p>Viennent ensuite les <strong>restaurants, chacun dans son propre cadre</strong> : CA d'hier, CA du mois
et ses 30 derniers jours en graphique. Leurs chiffres ne sont jamais additionnés ni mis dans un même
graphique : chaque restaurant a son concept et ses services, il n'est comparé qu'à lui-même l'année
précédente. Chaque graphique a sa propre échelle, la hauteur des barres ne se compare donc pas d'un
restaurant à l'autre.</p>
<p>Plus bas, les autres pôles ont chacun leur tableau : cliquez sur une société pour ouvrir son détail.</p>
HTML,
    ],
    [
        'id' => 'tableau-de-bord', 'icon' => 'trend', 'pour' => 'tous',
        'titre' => "Le tableau de bord d'une société",
        'html' => <<<HTML
<p>Pour un <strong>restaurant</strong>, la partie <em>Activité</em> montre le CA d'hier, de la semaine
et du mois (avec une projection en fin de mois), les couverts, le panier moyen et la part
solide / liquide. Le graphique compare chaque jour au même jour de l'année précédente.</p>
<p>Pour toutes les sociétés, le <em>Suivi mensuel</em> donne le résultat net du dernier mois, le cumul
de l'année, les revenus et les charges, et pour l'immobilier le nombre de lots loués.</p>
<p class="guide-note">Les pastilles vertes et rouges indiquent la variation par rapport à l'année
précédente. Pour les charges, une hausse est affichée en rouge.</p>
HTML,
    ],
    [
        'id' => 'analyse-ventes', 'icon' => 'utensils', 'pour' => 'tous',
        'titre' => "Restaurants : analyse des ventes",
        'html' => <<<HTML
<p>L'onglet <strong>Analyse des ventes</strong> détaille ce qui s'est vendu. Choisissez la période en
haut : hier, 7 derniers jours, mois en cours, mois dernier, 30 derniers jours, ou des dates précises.</p>
<ul>
  <li>CA par jour et par heure, midi / soir, moyenne par jour de la semaine.</li>
  <li>Répartition par catégorie, <strong>meilleures ventes</strong> et <strong>produits les moins vendus</strong> :
      la base pour un débrief de service ou pour retravailler la carte.</li>
  <li>Remises, offerts, annulations, TVA, ventes par salle.</li>
  <li>Restaurants sous Popina uniquement : encaissements, ventes par serveur et pourboires.</li>
</ul>
<p class="guide-note">L'Addition ne transmet ni les couverts, ni les serveurs, ni les encaissements :
ces cadres n'apparaissent donc pas pour les restaurants équipés de L'Addition.</p>
HTML,
    ],
    [
        'id' => 'evolution', 'icon' => 'calendar', 'pour' => 'tous',
        'titre' => "Restaurants : évolution par rapport à l'an dernier",
        'html' => <<<HTML
<p>L'onglet <strong>Évolution</strong> compare hier, la semaine en cours et le mois en cours à l'année précédente.</p>
<ul>
  <li>Pour hier et la semaine, on compare au <strong>même jour de la semaine</strong> l'an dernier
      (un vendredi avec un vendredi), car c'est ce qui compte en restauration.</li>
  <li>Pour le mois, on compare au même mois l'an dernier, <strong>sur le même nombre de jours</strong> :
      le 10 du mois, on compare 10 jours à 10 jours.</li>
  <li>S'il n'y a pas de chiffres l'an dernier, aucune comparaison n'est affichée.</li>
</ul>
HTML,
    ],
    [
        'id' => 'donnees', 'icon' => 'clock', 'pour' => 'tous',
        'titre' => "D'où viennent les chiffres",
        'html' => <<<HTML
<table class="table table-sm guide-table">
  <thead><tr><th>Donnée</th><th>Source</th><th>Mise à jour</th></tr></thead>
  <tbody>
    <tr><td>Ventes des restaurants</td><td>Caisses L'Addition et Popina</td><td>Automatique, chaque nuit vers 5 h 30 (la journée de la veille)</td></tr>
    <tr><td>Factures fournisseurs, banque, trésorerie</td><td>Export Pennylane déposé par la direction</td><td>À chaque nouvel export</td></tr>
    <tr><td>Revenus et charges du mois</td><td>Calculés à partir des caisses et de Pennylane</td><td>Après chaque synchro ou import</td></tr>
    <tr><td>Logements loués, valeur, surface</td><td>Fiche société</td><td>À la main, quand ça change</td></tr>
    <tr><td>Documents</td><td>OneDrive</td><td>Directement dans OneDrive</td></tr>
  </tbody>
</table>
<p class="guide-note">Les restaurants reliés à une caisse n'ont rien à saisir. L'onglet <strong>Saisie quotidienne</strong>
ne sert qu'en secours (caisse pas encore reliée, ou qui n'a pas pu être lue) : une journée saisie à la main n'est jamais écrasée
par la synchro automatique.</p>
HTML,
    ],
    [
        'id' => 'charges', 'icon' => 'receipt', 'pour' => 'tous',
        'titre' => "Charges fixes et échéances de contrats",
        'html' => <<<HTML
<p>L'onglet <strong>Charges fixes</strong> liste les contrats récurrents d'une société (loyer, assurance,
maintenance, abonnements…). Son vrai intérêt : <strong>prévenir avant la date limite de résiliation</strong>
d'un contrat qui se renouvelle tout seul.</p>
<ol class="guide-steps">
  <li>Ajoutez la charge avec son montant, sa fréquence et sa <strong>prochaine échéance</strong>.</li>
  <li>Indiquez le <strong>préavis en jours</strong> prévu au contrat (0 = pas de surveillance).</li>
  <li>ADHUB calcule les échéances suivantes et prévient : orange à moins de 90 jours du préavis,
      rouge à moins de 30 jours ou si le préavis est dépassé.</li>
</ol>
<p>Les montants sont ramenés à l'année pour pouvoir les comparer. Un contrat résilié peut être
<em>désactivé</em> plutôt que supprimé, pour garder son historique.</p>
HTML,
    ],
    [
        'id' => 'comptabilite', 'icon' => 'bank', 'pour' => 'tous',
        'titre' => "Comptabilité",
        'html' => <<<HTML
<p>L'onglet <strong>Comptabilité</strong> apparaît pour les sociétés reliées à Pennylane. Il montre les
fournisseurs à payer (dont les factures en retard), les achats du mois, les encaissements et
décaissements, les opérations bancaires à justifier et les principaux fournisseurs.</p>
<p class="guide-note">Ces chiffres datent du dernier export Pennylane : la date est indiquée sur la page.</p>
HTML,
    ],
    [
        'id' => 'documents', 'icon' => 'file', 'pour' => 'tous',
        'titre' => "Documents",
        'html' => <<<HTML
<p>ADHUB ne stocke aucun fichier. L'onglet <strong>Documents</strong> ouvre le <strong>dossier OneDrive</strong>
de la société avec le bouton <em>Ouvrir le dossier</em> ; les sous-dossiers (juridique, compta, contrats…)
se parcourent ensuite dans OneDrive. Des catégories plus précises peuvent être ajoutées sous ce bouton.</p>
<ul>
  <li>Chaque ouverture est enregistrée dans le journal des accès, consultable par la direction.</li>
  <li>Une catégorie marquée <span class="badge bg-warning text-dark">Sensible</span> contient des données
      personnelles (RH…) : à n'ouvrir qu'en cas de besoin.</li>
  <li>Ce qui se passe une fois dans OneDrive (téléchargement, partage du lien) dépend des réglages de partage OneDrive.</li>
</ul>
HTML,
    ],
    [
        'id' => 'fiche', 'icon' => 'building', 'pour' => 'tous',
        'titre' => "Fiche société",
        'html' => <<<HTML
<p>La <strong>Fiche société</strong> regroupe l'identité (SIREN, adresse), le patrimoine (valeur, surface),
les indicateurs de pilotage saisis à la main (comme le nombre de logements loués), les associés et les
liens avec les autres sociétés du groupe.</p>
HTML,
    ],
    [
        'id' => 'admin-acces', 'icon' => 'users', 'pour' => 'direction',
        'titre' => "Administration : utilisateurs et accès",
        'html' => <<<HTML
<p>Menu <a href="/pages/admin_users.php">Administration → Utilisateurs et accès</a>.</p>
<ol class="guide-steps">
  <li>Créez le compte (nom, e-mail, mot de passe provisoire, rôle) et transmettez le mot de passe
      à la personne, qui le changera.</li>
  <li>Attribuez-lui une ou plusieurs sociétés. Pour chacune, choisissez le <strong>droit</strong>
      (lecture seule, ou lecture + modification) et la <strong>portée</strong> (tout, tableau de bord
      seulement, ou documents seulement).</li>
</ol>
<ul>
  <li>Le rôle <strong>Direction</strong> voit toutes les sociétés et l'administration, sans attribution.</li>
  <li>Les autres rôles (Associé, Gestionnaire, Externe) ne voient <strong>que</strong> les sociétés attribuées.
      Sans attribution, le compte ne voit rien.</li>
  <li>Un compte peut être désactivé à tout moment. La double authentification d'un utilisateur se
      réinitialise depuis cette même page.</li>
</ul>
HTML,
    ],
    [
        'id' => 'admin-caisses', 'icon' => 'utensils', 'pour' => 'direction',
        'titre' => "Administration : caisses",
        'html' => <<<HTML
<p>Menu <a href="/pages/admin_caisses.php">Administration → Caisses</a>.</p>
<ul>
  <li><strong>Dernières synchronisations</strong> : si une nuit s'est mal passée (mot de passe changé,
      site de la caisse modifié), l'erreur s'affiche ici.</li>
  <li>Quand une nouvelle catégorie de produits apparaît dans une caisse, classez-la en
      <strong>solide</strong>, <strong>liquide</strong> ou <strong>autre</strong> : c'est ce qui alimente la part solide / liquide.</li>
</ul>
HTML,
    ],
    [
        'id' => 'admin-pennylane', 'icon' => 'receipt', 'pour' => 'direction',
        'titre' => "Administration : import Pennylane",
        'html' => <<<HTML
<p>Menu <a href="/pages/admin_pennylane.php">Administration → Import Pennylane</a>.</p>
<ol class="guide-steps">
  <li>Exportez depuis Pennylane le fichier Excel avec les feuilles « Factures fournisseurs »,
      « Factures clients » et « Transactions bancaires ».</li>
  <li>Déposez-le dans <em>Déposer un export</em>. Réimporter un export plus récent met à jour sans créer de doublon.</li>
  <li>Vérifiez la correspondance entre les sociétés Pennylane et les sociétés ADHUB, et choisissez
      celles qui sont restées sans correspondance.</li>
</ol>
<p class="guide-note">Le tableau de bord, la trésorerie et l'onglet Comptabilité se mettent à jour juste après l'import.
Un export par mois, vers le 10, suffit à garder des chiffres fiables.</p>
HTML,
    ],
    [
        'id' => 'admin-tresorerie', 'icon' => 'bank', 'pour' => 'direction',
        'titre' => "Administration : trésorerie",
        'html' => <<<HTML
<p>Menu <a href="/pages/admin_tresorerie.php">Administration → Trésorerie</a>.</p>
<ol class="guide-steps">
  <li>Une seule fois par société : saisissez son solde bancaire réel à une date (écran Trésorerie de Pennylane
      ou relevé de fin de mois ; plusieurs comptes s'additionnent).</li>
  <li>Ensuite, chaque import Pennylane ajoute les opérations bancaires postérieures : la trésorerie de la
      vue d'ensemble et du tableau de bord se met à jour sans autre saisie.</li>
  <li>Si un écart apparaît un jour avec la banque, ressaisissez simplement le solde du jour.</li>
</ol>
HTML,
    ],
    [
        'id' => 'admin-autres', 'icon' => 'settings', 'pour' => 'direction',
        'titre' => "Administration : sociétés et journal",
        'html' => <<<HTML
<ul>
  <li><a href="/pages/projects.php">Gérer les sociétés</a> : créer ou archiver une société. Son type
      (restaurant, SCI, marchand de biens…) se corrige depuis sa Fiche société.</li>
  <li><a href="/pages/admin_logs.php">Journal des accès</a> : qui a ouvert quel dossier de documents, et quand.</li>
</ul>
HTML,
    ],
    [
        'id' => 'faq', 'icon' => 'alert', 'pour' => 'tous',
        'titre' => "Questions fréquentes",
        'html' => <<<HTML
<dl class="guide-faq">
  <dt>Le CA d'hier n'apparaît pas.</dt>
  <dd>La synchro passe vers 5 h 30. Si le chiffre manque encore dans la matinée, la caisse n'a pas pu être
      lue ou le service n'a pas été clôturé : prévenez la direction.</dd>
  <dt>Le chiffre est différent de celui de la caisse.</dt>
  <dd>Vérifiez la période : une journée de caisse va jusqu'à 5 h du matin le lendemain. ADHUB affiche le
      CA TTC sauf mention HT.</dd>
  <dt>Je ne vois pas une société, ou pas un onglet.</dt>
  <dd>Votre accès ne la couvre pas. Demandez à la direction de vous l'attribuer.</dd>
  <dt>J'ai changé de téléphone.</dt>
  <dd>Connectez-vous avec un code de secours, puis <em>Double authentification → Changer de téléphone</em>.</dd>
</dl>
HTML,
    ],
];

$sections = array_values(array_filter($sections, fn($s) => $s['pour'] === 'tous' || is_direction()));

$pageTitle = 'Guide';
require __DIR__ . '/../inc/layout_header.php';
?>

<style>
  .guide-toc .card-body { display: flex; flex-wrap: wrap; gap: 8px; }
  .guide-toc a { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border: 1px solid var(--ad-border);
    border-radius: 999px; color: var(--ad-text); text-decoration: none; font-size: .82rem; background: var(--ad-surface); }
  .guide-toc a:hover { border-color: var(--ad-gold); background: var(--ad-gold-soft); }
  .guide-section { scroll-margin-top: 84px; }
  .guide-section .card-body { max-width: 860px; line-height: 1.6; }
  .guide-section .card-body > :last-child { margin-bottom: 0; }
  .guide-section li { margin-bottom: 4px; }
  .guide-note { background: var(--ad-blue-soft); border-left: 3px solid var(--ad-blue); padding: 10px 14px;
    border-radius: var(--ad-radius-sm); font-size: .85rem; }
  .guide-table { font-size: .85rem; }
  .guide-faq dt { font-weight: 600; margin-top: 12px; }
  .guide-faq dt:first-child { margin-top: 0; }
  .guide-faq dd { color: var(--ad-muted); margin-bottom: 0; }
</style>

<?= page_header("Guide d'utilisation", "Comment se servir d'ADHUB, page par page.") ?>

<nav class="guide-toc card mb-4">
  <div class="card-body">
    <?php foreach ($sections as $s): ?>
      <a href="#<?= h($s['id']) ?>"><?= icon($s['icon'], 15) ?> <?= h($s['titre']) ?></a>
    <?php endforeach; ?>
  </div>
</nav>

<?php foreach ($sections as $s): ?>
  <section class="card mb-4 guide-section" id="<?= h($s['id']) ?>">
    <div class="card-header">
      <span class="d-flex align-items-center gap-2"><?= icon($s['icon'], 16) ?> <?= h($s['titre']) ?></span>
      <?php if ($s['pour'] === 'direction'): ?><span class="badge bg-secondary">Direction</span><?php endif; ?>
    </div>
    <div class="card-body"><?= $s['html'] ?></div>
  </section>
<?php endforeach; ?>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
