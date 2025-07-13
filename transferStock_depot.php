<?php
require_once "./config/init.php";
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["success" => false, "message" => "Méthode non autorisée"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$stockId = (int)($data['stockId'] ?? 0);
$destinationId = (int)($data['destination'] ?? 0);
$qty = (int)($data['qty'] ?? 0);
$userId = $_SESSION['utilisateurs']['id'] ?? null;

if (!$stockId || !$qty || !$destinationId || !$userId) {
    echo json_encode(["success" => false, "message" => "Données invalides."]);
    exit;
}

// Récupérer l'id du dépôt lié à cet utilisateur
$stmtDepot = $pdo->prepare("SELECT id FROM depots WHERE responsable_id = ?");
$stmtDepot->execute([$userId]);
$depot = $stmtDepot->fetch(PDO::FETCH_ASSOC);

if (!$depot) {
    echo json_encode(["success" => false, "message" => "Aucun dépôt associé à cet utilisateur."]);
    exit;
}

$depotId = (int)$depot['id'];

try {
    $pdo->beginTransaction();

    // Vérifier stock disponible dans stock_depots pour ce dépôt
    $stmt = $pdo->prepare("SELECT quantite FROM stock_depots WHERE stock_id = ? AND depot_id = ?");
    $stmt->execute([$stockId, $depotId]);
    $dispo = (int)$stmt->fetchColumn();

    if ($dispo < $qty) {
        throw new Exception("Stock insuffisant au dépôt.");
    }

    // Insérer transfert en attente avec source_type = depot, destination_type = chantier
    $stmt = $pdo->prepare("
        INSERT INTO transferts_en_attente (article_id, source_type, source_id, destination_type, destination_id, quantite, demandeur_id, statut)
        VALUES (?, 'depot', ?, 'chantier', ?, ?, ?, 'en_attente')
    ");
    $stmt->execute([
        $stockId,
        $depotId,
        $destinationId,
        $qty,
        $userId
    ]);

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message" => "Transfert enregistré, en attente de validation.",
        "quantiteDispo" => $dispo - $qty
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
