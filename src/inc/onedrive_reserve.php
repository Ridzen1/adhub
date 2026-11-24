<?php
/**
 * Onglet « OneDrive » de la barre du haut, réservé à quelques comptes.
 *
 * Seuls les comptes listés ci-dessous voient l'onglet (demande de Kader du
 * 2026-11-24) : Laetitia Mastrantuono, Arnaud Duvivier et le compte admin de
 * Kader. Tous les autres utilisateurs ne le voient pas.
 *
 * Pour ajouter ou retirer quelqu'un : modifier ONEDRIVE_RESERVE_EMAILS
 * (adresse de connexion du compte).
 */
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/../config/db.php';

const ONEDRIVE_RESERVE_URL = 'https://1drv.ms/f/c/09B0C8419127BD50/IgBePQ9Wa-DdRqsUKTmBBujfAda04HYrECC_fOE_A9tIMbM?e=HOGEyJ';

/** Adresses e-mail autorisées (en minuscules). */
const ONEDRIVE_RESERVE_EMAILS = [
    'ryad.haddad10@gmail.com',   // compte admin (Direction) de Kader
    'scihoteldieu@gmail.com',    // Arnaud Duvivier
    'laetitia.mdb@outlook.com',  // Laetitia Mastrantuono
];

/** Vrai si l'utilisateur connecté doit voir l'onglet OneDrive. */
function voit_onedrive_reserve(): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = false;

    $u = current_user();
    if ($u === null) {
        return false;
    }

    try {
        $st = get_db()->prepare('SELECT email, name FROM users WHERE id = :id');
        $st->execute(['id' => $u['id']]);
        $row = $st->fetch() ?: null;
    } catch (Throwable $e) {
        return false;
    }
    if (!$row) {
        return false;
    }

    $email = mb_strtolower(trim((string) $row['email']));
    return $cache = in_array($email, ONEDRIVE_RESERVE_EMAILS, true);
}
