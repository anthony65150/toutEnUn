<?php
// config/init.php

// ===== 1) Session & debug =====
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['entreprise_id']) && isset($_SESSION['utilisateurs']['entreprise_id'])) {
    $_SESSION['entreprise_id'] = (int) $_SESSION['utilisateurs']['entreprise_id'];
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


// Active les erreurs en DEV (optionnel, via .env: APP_ENV=dev)
function isDev(): bool
{
    return isset($_ENV['APP_ENV']) ? ($_ENV['APP_ENV'] === 'dev') : false;
}

// ===== 2) Charger .env (.ini) =====
$envCandidates = [
    $_SERVER['DOCUMENT_ROOT'] . '/.env',
    dirname(__DIR__) . '/.env',          // /config/.. -> racine projet
    dirname(__DIR__, 2) . '/.env',       // sécurité si projet déplacé
];
$envPath = null;
foreach ($envCandidates as $p) {
    if (is_readable($p)) {
        $envPath = $p;
        break;
    }
}
if (!$envPath) {
    die("Erreur : Impossible de lire le fichier .env");
}
$config = parse_ini_file($envPath, false, INI_SCANNER_TYPED);
if ($config === false) {
    die("Erreur : Lecture .env invalide");
}
$_ENV = array_merge($_ENV, $config);

if (!empty($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'dev') {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

// ===== 3) Connexion PDO =====
try {
    $pdo = new PDO(
        "mysql:host={$_ENV['db_host']};port={$_ENV['db_port']};dbname={$_ENV['db_name']};charset=utf8mb4",
        $_ENV['db_user'],
        $_ENV['db_password'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die("Erreur de connexion à la base : " . $e->getMessage());
}

// ===== 4) Helpers multi-entreprise =====

// Renvoie l’ID d’entreprise courant (obligatoire pour toutes les requêtes)
function eid(bool $strict = true): int
{
    if (isset($_SESSION['entreprise_id'])) {
        return (int) $_SESSION['entreprise_id'];
    }
    if (isset($_SESSION['utilisateurs']['entreprise_id'])) {
        $_SESSION['entreprise_id'] = (int) $_SESSION['utilisateurs']['entreprise_id'];
        return (int) $_SESSION['entreprise_id'];
    }
    if ($strict) {
        // On ne fait pas de echo/exit ici : on jette une exception que l'API ou la page gèrera.
        throw new RuntimeException('Entreprise non définie dans la session');
    }
    return 0;
}


// À appeler juste après le login (voir snippet plus bas)
function setEntrepriseSessionFromUser(array $user): void
{
    // $user doit contenir 'entreprise_id'
    if (!isset($user['entreprise_id'])) {
        http_response_code(500);
        exit('Login: entreprise_id manquant sur l’utilisateur');
    }
    $_SESSION['utilisateurs']  = $user;                     // tu le fais déjà
    $_SESSION['entreprise_id'] = (int) $user['entreprise_id'];
}

// Aide pratique pour construire rapidement des WHERE
function whereEid(string $alias = ''): string
{
    $col = $alias ? "{$alias}.entreprise_id" : "entreprise_id";
    return " {$col} = :eid ";
}

// Ajoute un AND ... entreprise_id = :eid à une clause existante
function andEid(string $alias = ''): string
{
    return " AND " . whereEid($alias) . " ";
}

// Petit guard pour les opérations sensibles
function requireAuthPage(): void
{
    if (empty($_SESSION['utilisateurs']['id'])) {
        header('Location: /connexion.php');
        exit;
    }
    try {
        eid(true);
    } catch (RuntimeException $e) {
        header('Location: /connexion.php');
        exit;
    }
}

function requireAuthApi(): void
{
    if (empty($_SESSION['utilisateurs']['id'])) {
        http_response_code(401);
        exit('Non authentifié');
    }
    try {
        eid(true);
    } catch (RuntimeException $e) {
        http_response_code(400);
        exit('Entreprise manquante');
    }
}


// ===== 5) Tes fonctions existantes =====
require_once __DIR__ . '/../fonctions/utilisateurs.php';
