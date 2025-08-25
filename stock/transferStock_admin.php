<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';
header('Content-Type: application/json');

// Sécurité : admin uniquement
if (!isset($_SESSION['utilisateurs']) || ($_SESSION['utilisateurs']['fonction'] ?? null) !== 'administrateur') {
    echo json_encode(["success" => false, "message" => "Accès refusé."]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode(["success" => false, "message" => "Méthode non autorisée"]);
    exit;
}

// Récup JSON
$raw   = file_get_contents("php://input");
$data  = json_decode($raw ?: '[]', true);

$stockId         = isset($data['stockId']) ? (int)$data['stockId'] : 0;
$sourceType      = $data['sourceType'] ?? null;         // 'depot' | 'chantier'
$sourceId        = isset($data['sourceId']) ? (int)$data['sourceId'] : 0;
$destinationType = $data['destinationType'] ?? null;    // 'depot' | 'chantier'
$destinationId   = isset($data['destinationId']) ? (int)$data['destinationId'] : 0;
$qty             = isset($data['qty']) ? (int)$data['qty'] : 0;

$adminId = $_SESSION['utilisateurs']['id'] ?? null;

$allowedTypes = ['depot', 'chantier'];

// Validations rapides
if ($stockId <= 0 || $qty <= 0 || !$sourceType || !$destinationType || !$adminId) {
    echo json_encode(["success" => false, "message" => "Données invalides."]);
    exit;
}
if (!in_array($sourceType, $allowedTypes, true) || !in_array($destinationType, $allowedTypes, true)) {
    echo json_encode(["success" => false, "message" => "Type source/destination invalide."]);
    exit;
}
if ($sourceId <= 0 || $destinationId <= 0) {
    echo json_encode(["success" => false, "message" => "IDs source/destination invalides."]);
    exit;
}
if ($sourceType === $destinationType && $sourceId === $destinationId) {
    echo json_encode(["success" => false, "message" => "Source et destination identiques."]);
    exit;
}

try {
    $pdo->beginTransaction();

    // --- 1) Vérifier le stock disponible à la source ---
    if ($sourceType === 'depot') {
        $stmt = $pdo->prepare("SELECT quantite FROM stock_depots WHERE stock_id = ? AND depot_id = ? FOR UPDATE");
        $stmt->execute([$stockId, $sourceId]);
        $quantiteSource = (int)($stmt->fetchColumn() ?: 0);
    } else {
        $stmt = $pdo->prepare("SELECT quantite FROM stock_chantiers WHERE stock_id = ? AND chantier_id = ? FOR UPDATE");
        $stmt->execute([$stockId, $sourceId]);
        $quantiteSource = (int)($stmt->fetchColumn() ?: 0);
    }

    if ($quantiteSource < $qty) {
        throw new Exception("Stock insuffisant à la source. Disponible : $quantiteSource.");
    }

    // --- 2) Prendre en compte les transferts déjà en attente depuis cette source ---
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantite), 0)
        FROM transferts_en_attente
        WHERE article_id = ?
          AND source_type = ?
          AND source_id = ?
          AND statut = 'en_attente'
        FOR UPDATE
    ");
    $stmt->execute([$stockId, $sourceType, $sourceId]);
    $enAttente = (int)$stmt->fetchColumn();

    $disponibleApresAttente = $quantiteSource - $enAttente;
    if ($disponibleApresAttente < $qty) {
        throw new Exception("Stock insuffisant (après transferts en attente). Disponible : $disponibleApresAttente.");
    }

    // --- 3) Insérer le transfert en attente ---
    $stmt = $pdo->prepare("
        INSERT INTO transferts_en_attente
            (article_id, source_type, source_id, destination_type, destination_id, quantite, demandeur_id, statut)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, 'en_attente')
    ");
    $stmt->execute([$stockId, $sourceType, $sourceId, $destinationType, $destinationId, $qty, $adminId]);

    // --- 4) Décrément immédiat SI la source est un dépôt ---
    //     (Pour les chantiers, on ne décrémente qu'à la validation — ta table d'affichage soustrait déjà l'en_attente)
    if ($sourceType === 'depot') {
        $update = $pdo->prepare("
            UPDATE stock_depots
            SET quantite = quantite - ?
            WHERE stock_id = ? AND depot_id = ?
        ");
        $update->execute([$qty, $stockId, $sourceId]);
    }

    $pdo->commit();
    echo json_encode(["success" => true, "message" => "Transfert enregistré et en attente de validation."]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
