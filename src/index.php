<?php
// Point d'entrée : redirige vers la connexion ou l'accueil selon l'état de session.
require_once __DIR__ . '/inc/access.php';

if (is_logged_in()) {
    header('Location: /pages/accueil.php');
} else {
    header('Location: /pages/login.php');
}
exit;
