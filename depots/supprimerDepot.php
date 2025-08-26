<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';

if (!isset($_SESSION['utilisateurs']) || ($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur') {
    header("Location: ../connexion.php");
    exit;
}

$id = (int)($_POST['delete_id'] ?? 0);
if ($id <= 0) {
    header("Location: ./depots_admin.php");
    exit;
}

// Empêcher la suppression s'il reste du stock dans ce dépôt
$check = $pdo->prepare("SELECT COUNT(*) FROM stock_depots WHERE depot_id = ?");
$check->execute([$id]);
if ((int)$check->fetchColumn() > 0) {
    header("Location: ./depots_admin.php?success=error");
    exit;
}

$stmt = $pdo->prepare("DELETE FROM depots WHERE id = ?");
$stmt->execute([$id]);

header("Location: ./depots_admin.php?success=delete");
exit;
