<?php
/**
 * Ancien onglet Comptabilité (export Pennylane déposé à la main), retiré le 28/11/2026 :
 * la banque, la trésorerie et les charges viennent désormais de Qalimo (onglet Banque).
 * Les anciens liens arrivent sur l'onglet Banque.
 */
$id = (int) ($_GET['id'] ?? 0);
header('Location: /pages/banque.php?id=' . $id, true, 301);
exit;
