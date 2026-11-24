<?php
/**
 * Onglet « OneDrive » de la barre du haut, réservé à quelques comptes.
 *
 * Seuls les comptes listés ci-dessous voient l'onglet (demande de Kader du
 * 2026-11-24) : Laetitia Mastrantuono, Arnaud Duvivier et le compte admin de
 * Kader. Tous les autres utilisateurs ne le voient pas.
 *
 * Pour ajouter ou retirer quelqu'un : modifier ONEDRIVE_RESERVE_EMAILS
 * (adresse de connexion) ou ONEDRIVE_RESERVE_NOMS (nom complet du compte).
 */
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/../config/db.php';

const ONEDRIVE_RESERVE_URL = 'https://1drv.ms/f/c/09B0C8419127BD50/IgBePQ9Wa-DdRqsUKTmBBujfAda04HYrECC_fOE_A9tIMbM?e=HOGEyJ';

/** Adresses e-mail autorisées (en minuscules). */
const ONEDRIVE_RESERVE_EMAILS = [
    'ryad.haddad10@gmail.com',   // compte admin (Direction) de Kader
    'scihoteldieu@gmail.com',    // Arnaud Duvivier
];

/**
 * Noms complets autorisés, utilisés quand l'adresse n'est pas connue
 * (comparaison sans accents ni majuscules).
 */
const ONEDRIVE_RESERVE_NOMS = [
    'Laetitia Mastrantuono',
    'Arnaud Duvivier',
];

function onedrive_normaliser_nom(string $nom): string
{
    $nom = mb_strtolower(trim($nom));
    $nom = strtr($nom, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ï' => 'i', 'î' => 'i', 'ô' => 'o', 'à' => 'a', 'â' => 'a', 'ç' => 'c', 'ü' => 'u', 'û' => 'u']);
    return preg_replace('/[\s\-]+/', ' ', $nom);
}

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
    if (in_array($email, ONEDRIVE_RESERVE_EMAILS, true)) {
        return $cache = true;
    }

    $nom   = onedrive_normaliser_nom((string) $row['name']);
    $noms  = array_map('onedrive_normaliser_nom', ONEDRIVE_RESERVE_NOMS);
    return $cache = in_array($nom, $noms, true);
}
