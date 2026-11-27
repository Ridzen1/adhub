<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/qalimo_banques.php';
require_once __DIR__ . '/../inc/ui.php';
require_direction();

/**
 * Trésorerie des sociétés : depuis le 27/11/2026 elle vient uniquement des banques lues
 * chaque matin dans Qalimo (cron/qalimo/qalimo_banques_sync.php), plus de Pennylane.
 * La page montre, société par société, les comptes repris et leur état, et les comptes
 * qu'aucune société ne reprend. Aucun total entre sociétés.
 */

$pret = qalimo_banques_disponible();
$comptes = $pret ? get_db()->query(
    "SELECT c.*, p.name AS societe FROM qalimo_comptes c LEFT JOIN projects p ON p.id = c.project_id
     ORDER BY (c.project_id IS NULL), p.name, c.solde DESC"
)->fetchAll() : [];
$parSociete = [];
$nonRelies = [];
foreach ($comptes as $c) {
    if ($c['project_id'] === null) {
        $nonRelies[] = $c;
    } else {
        $parSociete[(int) $c['project_id']]['nom'] = $c['societe'];
        $parSociete[(int) $c['project_id']]['comptes'][] = $c;
    }
}
$sansBanque = $pret ? get_db()->query(
    "SELECT p.id, p.name FROM projects p JOIN project_types pt ON pt.id = p.project_type_id
     WHERE p.status = 'active' AND pt.code <> 'restauration_exploitation'
       AND NOT EXISTS (SELECT 1 FROM qalimo_comptes c WHERE c.project_id = p.id)
     ORDER BY p.name"
)->fetchAll() : [];
$derniere = $pret ? get_db()->query(
    "SELECT run_at, status, message FROM sync_runs WHERE source = 'qalimo_banques' ORDER BY id DESC LIMIT 1"
)->fetch() : null;

$pageTitle = 'Trésorerie';
require __DIR__ . '/../inc/layout_header.php';
?>

<?= page_header('Trésorerie des sociétés', "Soldes et opérations bancaires lus chaque matin dans Qalimo. La trésorerie d'une société est la somme de ses propres comptes.", '', 'Administration') ?>

<?php if (!$pret): ?>
  <div class="alert alert-warning">Passez d'abord la migration <code>028_qalimo_banques.sql</code> dans phpMyAdmin,
    puis lancez <code>php cron/qalimo/qalimo_banques_sync.php</code>.</div>
<?php else: ?>

<?php if ($derniere): ?>
  <div class="alert alert-<?= $derniere['status'] === 'ok' ? 'light' : 'danger' ?> small">
    Dernière lecture Qalimo le <?= date('d/m/Y à H:i', strtotime($derniere['run_at'])) ?> : <?= h($derniere['message']) ?>
  </div>
<?php endif; ?>

<?php if ($nonRelies): ?>
<div class="card mb-4">
  <div class="card-header"><?= count($nonRelies) ?> compte(s) Qalimo reliés à aucune société</div>
  <div class="card-body small border-bottom">
    Ajoutez-les dans <code>config/qalimo.local.php</code>, rubrique <code>'comptes'</code> :
    numéro du compte => id de la société, ou 0 pour l'ignorer.
  </div>
  <div class="card-body p-0">
    <table class="table table-sm mb-0">
      <thead><tr><th>Numéro</th><th>Banque</th><th>Libellé</th><th class="text-end">Solde</th></tr></thead>
      <tbody>
        <?php foreach ($nonRelies as $c): ?>
          <tr>
            <td><code><?= (int) $c['id'] ?></code></td>
            <td><?= h($c['banque'] ?? '') ?></td>
            <td><?= h($c['libelle'] ?? '') ?><?= $c['iban_fin'] ? ' …' . h($c['iban_fin']) : '' ?></td>
            <td class="text-end"><?= $c['solde'] !== null ? eur((float) $c['solde'], 2) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card mb-4">
  <div class="card-header">Comptes par société</div>
  <div class="card-body p-0">
    <table class="table table-sm mb-0 align-middle">
      <thead><tr><th>Société</th><th>Compte</th><th>État</th><th class="text-end">Solde</th></tr></thead>
      <tbody>
        <?php foreach ($parSociete as $pid => $s): ?>
          <?php foreach ($s['comptes'] as $i => $c): [$etat, $couleur] = qalimo_statut_compte($c['statut']); ?>
            <tr>
              <td><?= $i === 0 ? '<a class="fw-semibold" href="/pages/banque.php?id=' . $pid . '">' . h($s['nom']) . '</a>' : '' ?></td>
              <td><?= h(trim(($c['banque'] ?? '') . ' — ' . ($c['libelle'] ?? ''), ' —')) ?><?= $c['iban_fin'] ? ' <span class="text-muted">…' . h($c['iban_fin']) . '</span>' : '' ?></td>
              <td><span class="badge bg-<?= $couleur ?>-subtle text-<?= $couleur ?>-emphasis"><?= h($etat) ?></span></td>
              <td class="text-end"><?= $c['solde'] !== null ? eur((float) $c['solde'], 2) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
        <?php if (!$parSociete): ?><tr><td colspan="4" class="text-center text-muted py-4">Aucun compte lu pour l'instant.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($sansBanque): ?>
<div class="card mb-4">
  <div class="card-body small">
    <strong><?= count($sansBanque) ?> société(s) sans compte bancaire dans Qalimo</strong> (leur trésorerie n'est plus mise à jour) :
    <?= h(implode(', ', array_column($sansBanque, 'name'))) ?>.
  </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
