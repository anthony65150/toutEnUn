<?php
require_once "./config/init.php";

if (!isset($_SESSION['utilisateurs']) || $_SESSION['utilisateurs']['fonction'] !== 'administrateur') {
    header("Location: connexion.php");
    exit;
}

$id = isset($_POST['delete_id']) ? (int)$_POST['delete_id'] : 0;
if ($id <= 0) {
    header("Location: depots_admin.php");
    exit;
}

// Sécurité : empêcher la suppression s'il reste du stock dans ce dépôt (si tu as la table stock_depots)
$check = $pdo->prepare("SELECT COUNT(*) FROM stock_depots WHERE depot_id = ?");
if ($check->execute([$id]) && $check->fetchColumn() > 0) {
    // Tu peux passer un message d'erreur si tu veux
    header("Location: depots_admin.php?success=error");
    exit;
}

$stmt = $pdo->prepare("DELETE FROM depots WHERE id = ?");
$stmt->execute([$id]);

header("Location: depots_admin.php?success=delete");
exit;
