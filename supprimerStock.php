<?php
require_once "./config/init.php";

if (!isset($_SESSION['utilisateurs']) || $_SESSION['utilisateurs']['fonction'] !== 'administrateur') {
    header("Location: connexion.php");
    exit;
}

$id = $_GET['id'] ?? null;

if ($id) {
    $pdo->prepare("DELETE FROM stock_chantiers WHERE stock_id = :id")->execute([':id' => $id]);
    $pdo->prepare("DELETE FROM stock WHERE id = :id")->execute([':id' => $id]);

    $photoPath = "uploads/photos/{$id}.jpg";
    if (file_exists($photoPath)) {
        unlink($photoPath);
    }
}

header("Location: stock_admin.php");
exit;
