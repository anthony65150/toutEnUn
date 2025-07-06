<?php
require_once '../config/init.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

$stockId = (int)($data['stockId'] ?? 0);
$destination = $data['destination'] ?? '';
$qty = (int)($data['qty'] ?? 0);

if ($stockId <= 0 || $qty <= 0 || !$destination) {
    echo json_encode(['success' => false, 'message' => 'Données invalides.']);
    exit;
}

// Exemple de logique : destination = 'depot' ou ID chantier
try {
    if ($destination === 'depot') {
        // Revenir au dépôt
        $stmt = $pdo->prepare("UPDATE stock SET quantite_disponible = quantite_disponible + :qty WHERE id = :stockId");
        $stmt->execute(['qty' => $qty, 'stockId' => $stockId]);

        // Supprimer/ajuster dans stock_chantiers ?
    } else {
        // Aller vers un chantier
        $pdo->beginTransaction();

        // Déduire du disponible
        $stmt = $pdo->prepare("UPDATE stock SET quantite_disponible = quantite_disponible - :qty WHERE id = :stockId");
        $stmt->execute(['qty' => $qty, 'stockId' => $stockId]);

        // Insérer ou mettre à jour l'entrée du chantier
        $stmt = $pdo->prepare("
            INSERT INTO stock_chantiers (stock_id, chantier_id, quantite)
            VALUES (:stockId, :chantierId, :qty)
            ON DUPLICATE KEY UPDATE quantite = quantite + :qty
        ");
        $stmt->execute(['stockId' => $stockId, 'chantierId' => $destination, 'qty' => $qty]);

        $pdo->commit();
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
