<?php
require_once "./config/init.php";
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["success" => false, "message" => "Méthode non autorisée"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$stockId = (int)($data['stockId'] ?? 0);
$destinationType = $data['destinationType'] ?? null;
$destinationId = (int)($data['destinationId'] ?? 0);
$qty = (int)($data['qty'] ?? 0);
$chefId = $_SESSION['utilisateurs']['id'] ?? null;
$chantierId = $_SESSION['utilisateurs']['chantier_id'] ?? null;

if (!$stockId || !$qty || !$destinationType || !$destinationId || !$chefId || !$chantierId) {
    echo json_encode(["success" => false, "message" => "Données invalides."]);
    exit;
}

if (!in_array($destinationType, ['depot', 'chantier'])) {
    echo json_encode(["success" => false, "message" => "Type de destination invalide."]);
    exit;
}

if ($destinationType === 'chantier' && $destinationId === $chantierId) {
    echo json_encode(["success" => false, "message" => "Destination identique au chantier source."]);
    exit;
}

try {
    $pdo->beginTransaction();

    // Vérifier stock disponible sur chantier du chef
    $stmt = $pdo->prepare("SELECT quantite FROM stock_chantiers WHERE stock_id = ? AND chantier_id = ?");
    $stmt->execute([$stockId, $chantierId]);
    $dispo = (int)$stmt->fetchColumn();

    if ($dispo < $qty) {
        throw new Exception("Stock insuffisant sur ton chantier.");
    }

    // Insérer transfert en attente
    $stmt = $pdo->prepare("
        INSERT INTO transferts_en_attente 
        (article_id, source_type, source_id, destination_type, destination_id, quantite, demandeur_id, statut)
        VALUES (?, 'chantier', ?, ?, ?, ?, ?, 'en_attente')
    ");
    $stmt->execute([
        $stockId,
        $chantierId,
        $destinationType,
        $destinationId,
        $qty,
        $chefId
    ]);

    // 🔻 Retirer du chantier
    $stmt = $pdo->prepare("UPDATE stock_chantiers SET quantite = quantite - :qte WHERE chantier_id = :chantier AND stock_id = :article");
    $stmt->execute(['qte' => $qty, 'chantier' => $chantierId, 'article' => $stockId]);

    // 🔻 Retirer du stock global dispo
    $stmt = $pdo->prepare("UPDATE stock SET quantite_disponible = quantite_disponible - :qte WHERE id = :article");
    $stmt->execute(['qte' => $qty, 'article' => $stockId]);

    $pdo->commit();

    echo json_encode(["success" => true, "message" => "Transfert enregistré, en attente de validation."]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
