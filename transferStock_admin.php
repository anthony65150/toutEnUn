<?php
require_once "./config/init.php";
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["success" => false, "message" => "Méthode non autorisée"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$stockId = (int)($data['stockId'] ?? 0);
$sourceType = $data['sourceType'] ?? null;
$sourceId = (int)($data['sourceId'] ?? 0);
$destinationType = $data['destinationType'] ?? null;
$destinationId = (int)($data['destinationId'] ?? 0);
$qty = (int)($data['qty'] ?? 0);
$adminId = $_SESSION['utilisateurs']['id'] ?? null;

$allowedTypes = ['depot', 'chantier'];

if (!$stockId || !$qty || !$sourceType || !$destinationType || !$adminId) {
    echo json_encode(["success" => false, "message" => "Données invalides."]);
    exit;
}
if (!in_array($sourceType, $allowedTypes) || !in_array($destinationType, $allowedTypes)) {
    echo json_encode(["success" => false, "message" => "Type source/destination invalide."]);
    exit;
}
if ($sourceType === $destinationType && $sourceId === $destinationId) {
    echo json_encode(["success" => false, "message" => "Source et destination identiques."]);
    exit;
}

try {
    $pdo->beginTransaction();

    // Vérifier stock à la source
    if ($sourceType === 'depot') {
        $stmt = $pdo->prepare("SELECT quantite FROM stock_depots WHERE stock_id = ? AND depot_id = ?");
        $stmt->execute([$stockId, $sourceId]);
        $quantiteSource = (int)$stmt->fetchColumn();
    } else {
        $stmt = $pdo->prepare("SELECT quantite FROM stock_chantiers WHERE stock_id = ? AND chantier_id = ?");
        $stmt->execute([$stockId, $sourceId]);
        $quantiteSource = (int)$stmt->fetchColumn();
    }

    if ($quantiteSource < $qty) {
        throw new Exception("Stock insuffisant à la source. Disponible : $quantiteSource.");
    }

    // Vérifier les transferts déjà en attente depuis cette source
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantite),0) 
        FROM transferts_en_attente
        WHERE article_id = ? AND source_type = ? AND source_id = ? AND statut = 'en_attente'
    ");
    $stmt->execute([$stockId, $sourceType, $sourceId]);
    $enAttente = (int)$stmt->fetchColumn();

    $disponibleApresAttente = $quantiteSource - $enAttente;
    if ($disponibleApresAttente < $qty) {
        throw new Exception("Stock insuffisant (après prise en compte des transferts en attente). Disponible : $disponibleApresAttente.");
    }

    // Insérer le transfert en attente
    $stmt = $pdo->prepare("
        INSERT INTO transferts_en_attente (article_id, source_type, source_id, destination_type, destination_id, quantite, demandeur_id, statut)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'en_attente')
    ");
    $stmt->execute([
        $stockId,
        $sourceType,
        $sourceId,
        $destinationType,
        $destinationId,
        $qty,
        $adminId
    ]);

    $pdo->commit();
    echo json_encode(["success" => true, "message" => "Transfert enregistré et en attente de validation."]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
