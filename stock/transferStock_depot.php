<?php
require_once __DIR__ . '/../config/init.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["success" => false, "message" => "Méthode non autorisée"]);
    exit;
}

// ---- 1) RÉCUP INPUT: JSON ou POST ----
$raw = file_get_contents("php://input");
$asJson = json_decode($raw, true);

// Helper: récupère depuis JSON puis depuis POST
$in = function(string $jsonKey, string $postKey = null, $default = null) use ($asJson) {
    if (is_array($asJson) && array_key_exists($jsonKey, $asJson)) return $asJson[$jsonKey];
    if ($postKey !== null && isset($_POST[$postKey])) return $_POST[$postKey];
    return $default;
};

// Certains usages ont un seul select "depot_2" / "chantier_5"
$destCombo = $in('destination', 'destination', null);
if (!$destCombo && isset($_POST['destinationChantier'])) {
    $destCombo = $_POST['destinationChantier'];
}
if ($destCombo && !isset($_POST['destination_type']) && !isset($asJson['destinationType'])) {
    // split "chantier_5" -> type, id
    if (preg_match('/^(depot|chantier)_(\d+)$/', $destCombo, $m)) {
        $_POST['destination_type'] = $m[1];
        $_POST['destination_id']   = $m[2];
    }
}

// Normalisations: on accepte les 2 conventions
$stockId         = (int) ($in('stockId', 'article_id', 0));
$sourceType      =       $in('sourceType', 'source_type', null);
$sourceDepotId   = (int) ($in('sourceId', 'source_depot_id', 0)); // côté dépôt on passe souvent 'source_depot_id'
$destinationType =       $in('destinationType', 'destination_type', null);
$destinationId   = (int) ($in('destinationId', 'destination_id', 0));
$qty             = (int) ($in('qty', 'quantity', 0));
$userId          = $_SESSION['utilisateurs']['id'] ?? null;

// Si on a un source_depot_id et pas de sourceType => c'est un dépôt
if (!$sourceType && $sourceDepotId) {
    $sourceType = 'depot';
}
$sourceId = $sourceDepotId ?: (int)$in('sourceId', 'source_id', 0);

$allowedTypes = ['depot', 'chantier'];

// ---- 2) VALIDATIONS ----
if (!$stockId || !$qty || !$sourceType || !$destinationType || !$userId || !$sourceId || !$destinationId) {
    echo json_encode(["success" => false, "message" => "Données invalides."]);
    exit;
}
if (!in_array($sourceType, $allowedTypes, true) || !in_array($destinationType, $allowedTypes, true)) {
    echo json_encode(["success" => false, "message" => "Type source/destination invalide."]);
    exit;
}
if ($sourceType === $destinationType && $sourceId === $destinationId) {
    echo json_encode(["success" => false, "message" => "Source et destination identiques."]);
    exit;
}

try {
    $pdo->beginTransaction();

    // ---- 3) LECTURE STOCK SOURCE ----
    if ($sourceType === 'depot') {
        $stmt = $pdo->prepare("SELECT quantite FROM stock_depots WHERE stock_id = ? AND depot_id = ?");
    } else {
        $stmt = $pdo->prepare("SELECT quantite FROM stock_chantiers WHERE stock_id = ? AND chantier_id = ?");
    }
    $stmt->execute([$stockId, $sourceId]);
    $quantiteSource = (int)$stmt->fetchColumn();

    // ---- 4) PRISE EN COMPTE DES TRANSFERTS EN ATTENTE ----
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantite),0)
        FROM transferts_en_attente
        WHERE article_id = ? AND source_type = ? AND source_id = ? AND statut = 'en_attente'
    ");
    $stmt->execute([$stockId, $sourceType, $sourceId]);
    $enAttente = (int)$stmt->fetchColumn();

    $dispoApres = $quantiteSource - $enAttente;
    if ($dispoApres < $qty) {
        throw new Exception("Stock insuffisant (après transferts en attente). Disponible : $dispoApres.");
    }

    // ---- 5) INSÉRER TRANSFERT EN ATTENTE ----
    $stmt = $pdo->prepare("
        INSERT INTO transferts_en_attente
        (article_id, source_type, source_id, destination_type, destination_id, quantite, demandeur_id, statut)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'en_attente')
    ");
    $stmt->execute([$stockId, $sourceType, $sourceId, $destinationType, $destinationId, $qty, $userId]);

    // ---- 6) DÉCRÉMENT IMMÉDIATEMENT SI SOURCE = DEPOT ----
    $newDepotQty = null;
    if ($sourceType === 'depot') {
        $upd = $pdo->prepare("
            UPDATE stock_depots
               SET quantite = quantite - :qte
             WHERE stock_id = :sid AND depot_id = :did
        ");
        $upd->execute(['qte' => $qty, 'sid' => $stockId, 'did' => $sourceId]);

        // Relire la nouvelle quantité pour renvoi à l'UI
        $reRead = $pdo->prepare("SELECT quantite FROM stock_depots WHERE stock_id = ? AND depot_id = ?");
        $reRead->execute([$stockId, $sourceId]);
        $newDepotQty = (int)$reRead->fetchColumn();
    }

    $pdo->commit();
    echo json_encode([
        "success" => true,
        "message" => "Transfert enregistré et en attente de validation.",
        "new_quantite_depot" => $newDepotQty
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
