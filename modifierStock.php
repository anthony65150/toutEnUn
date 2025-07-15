<?php
require_once "./config/init.php";

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
    exit;
}

$stockId = $_POST['stockId'] ?? null;
$nom = trim($_POST['nom'] ?? '');
$quantite = isset($_POST['quantite']) ? (int)$_POST['quantite'] : null;

if (!$stockId || $nom === '' || $quantite === null || $quantite < 0) {
    echo json_encode(['success' => false, 'message' => 'Données invalides']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Récupérer l'ancienne quantité totale
    $stmt = $pdo->prepare("SELECT quantite_totale FROM stock WHERE id = ?");
    $stmt->execute([$stockId]);
    $ancienneQuantite = (int)$stmt->fetchColumn();

    $diff = $quantite - $ancienneQuantite;

    // Mettre à jour nom et quantite_totale
    $stmt = $pdo->prepare("UPDATE stock SET nom = ?, quantite_totale = ?, quantite_disponible = quantite_disponible + ? WHERE id = ?");
    $stmt->execute([$nom, $quantite, $diff, $stockId]);

    // Mettre à jour la quantité dans stock_depots (dépôt principal id = 1)
    $stmtDepotCheck = $pdo->prepare("SELECT quantite FROM stock_depots WHERE stock_id = ? AND depot_id = 1");
    $stmtDepotCheck->execute([$stockId]);
    $quantiteDepot = $stmtDepotCheck->fetchColumn();

    if ($quantiteDepot !== false) {
        $nouvelleQuantiteDepot = max(0, (int)$quantiteDepot + $diff);
        $stmtDepotUpdate = $pdo->prepare("UPDATE stock_depots SET quantite = ? WHERE stock_id = ? AND depot_id = 1");
        $stmtDepotUpdate->execute([$nouvelleQuantiteDepot, $stockId]);
    } else {
        $nouvelleQuantiteDepot = max(0, $quantite);
        $stmtDepotInsert = $pdo->prepare("INSERT INTO stock_depots (stock_id, depot_id, quantite) VALUES (?, 1, ?)");
        $stmtDepotInsert->execute([$stockId, $nouvelleQuantiteDepot]);
    }

    $pdo->commit();

    // Récupérer quantité disponible mise à jour
    $stmt = $pdo->prepare("SELECT quantite_disponible FROM stock WHERE id = ?");
    $stmt->execute([$stockId]);
    $quantiteDispo = (int)$stmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'newNom' => $nom,
        'newQuantiteTotale' => $quantite,
        'quantiteDispo' => $quantiteDispo,
        'newPhotoUrl' => isset($newPhotoUrl) ? $newPhotoUrl : null
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
