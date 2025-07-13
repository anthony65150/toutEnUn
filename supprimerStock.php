<?php
require_once "./config/init.php";

header('Content-Type: application/json');

if (!isset($_SESSION['utilisateurs']) || $_SESSION['utilisateurs']['fonction'] !== 'administrateur') {
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit;
}

$id = $_GET['id'] ?? null;

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'ID manquant']);
    exit;
}

try {
    // Supprimer d'abord les références dans stock_depots
    $pdo->prepare("DELETE FROM stock_depots WHERE stock_id = :id")->execute([':id' => $id]);

    // Puis supprimer les références dans stock_chantiers
    $pdo->prepare("DELETE FROM stock_chantiers WHERE stock_id = :id")->execute([':id' => $id]);

    // Enfin supprimer l'article dans stock
    $pdo->prepare("DELETE FROM stock WHERE id = :id")->execute([':id' => $id]);

    $photoPath = __DIR__ . "/uploads/photos/{$id}.jpg";
    if (file_exists($photoPath)) {
        unlink($photoPath);
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
