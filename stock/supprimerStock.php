<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';
header('Content-Type: application/json');

// Sécurité
if (!isset($_SESSION['utilisateurs']) || ($_SESSION['utilisateurs']['fonction'] ?? null) !== 'administrateur') {
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit;
}

// ID
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID manquant ou invalide']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Récupérer l'info photo AVANT suppression de la ligne stock
    $stmt = $pdo->prepare("SELECT photo FROM stock WHERE id = ?");
    $stmt->execute([$id]);
    $photoRel = $stmt->fetchColumn();

    // Supprimer références (ordre: enfants -> parent)
    $pdo->prepare("DELETE FROM stock_depots WHERE stock_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM stock_chantiers WHERE stock_id = ?")->execute([$id]);

    // (optionnel) nettoyer les transferts en attente liés à cet article
    $pdo->prepare("DELETE FROM transferts_en_attente WHERE article_id = ?")->execute([$id]);

    // On garde généralement l'historique (stock_mouvements) pour la traçabilité.
    // Si tu veux aussi le supprimer, décommente la ligne suivante :
    // $pdo->prepare("DELETE FROM stock_mouvements WHERE stock_id = ?")->execute([$id]);

    // Supprimer l'article
    $pdo->prepare("DELETE FROM stock WHERE id = ?")->execute([$id]);

    // Supprimer la photo si une URL/chemin est stocké
    if (!empty($photoRel)) {
        // Normaliser: retire le / initial éventuel
        $rel = ltrim($photoRel, '/'); // ex: uploads/photos/xxx.jpg
        $abs = $_SERVER['DOCUMENT_ROOT'] . '/' . $rel;

        // Sécurités minimales: dans /uploads/
        if (str_starts_with($rel, 'uploads/') && file_exists($abs) && is_file($abs)) {
            @unlink($abs);
        }
    }

    // (optionnel) si tu avais un pattern fixe id.jpg à nettoyer en plus (fallback)
    // $fallback = realpath($_SERVER['DOCUMENT_ROOT'] . '/../') . "/uploads/photos/{$id}.jpg"; // pas recommandé si storage par chemin BDD

    $pdo->commit();
    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
