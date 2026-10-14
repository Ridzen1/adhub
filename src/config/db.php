<?php

/**
 * Connexion PDO unique, réutilisée dans tout le projet.
 *
 * Ordre de priorité pour les identifiants :
 *   1. config/config.local.php   → production (fichier non versionné)
 *   2. variables d'environnement → développement local (docker-compose.yml)
 *
 * Ce fonctionnement permet de déployer le même code partout : sur l'hébergement,
 * seul config.local.php diffère, et il n'est jamais copié depuis le poste de dev.
 */
function get_db_config(): array
{
    $fichierLocal = __DIR__ . '/config.local.php';

    if (is_readable($fichierLocal)) {
        $conf = require $fichierLocal;
        if (is_array($conf) && isset($conf['db'])) {
            return $conf['db'];
        }
    }

    return [
        'host'   => getenv('DB_HOST') ?: 'db',
        'name'   => getenv('DB_NAME') ?: 'dashboard',
        'user'   => getenv('DB_USER') ?: 'root',
        'pass'   => getenv('DB_PASS') ?: 'root',
    ];
}

function est_en_production(): bool
{
    return is_readable(__DIR__ . '/config.local.php');
}

function get_db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $c = get_db_config();

        try {
            $pdo = new PDO(
                "mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4",
                $c['user'],
                $c['pass'],
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            // En production, ne jamais exposer host, utilisateur ou mot de passe
            // dans le message d'erreur affiché au visiteur.
            if (est_en_production()) {
                error_log('Connexion base de données impossible : ' . $e->getMessage());
                http_response_code(503);
                exit('Service momentanément indisponible.');
            }
            throw $e;
        }
    }

    return $pdo;
}
