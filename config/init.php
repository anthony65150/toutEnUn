<?php
// config/init.php

// Démarrer la session si elle n'existe pas
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Charger les variables d'environnement depuis .env
$config = parse_ini_file($_SERVER["DOCUMENT_ROOT"] . "/.env");

if (!$config) {
    die("Erreur : Impossible de lire le fichier .env");
}

// Connexion PDO avec les infos de .env
try {
    $pdo = new PDO(
        "mysql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_password']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erreur de connexion à la base de données : " . $e->getMessage());
}

// Inclusion de tes fonctions
require_once __DIR__ . '/../fonctions/utilisateurs.php';
