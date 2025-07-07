<?php
require_once "./config/init.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$stockId = isset($input['stockId']) ? (int)$input['stockId'] : null;
$destination = $input['destination'] ?? null;
$quantite = isset($input['qty']) ? (int)$input['qty'] : null;

if (!$stockId || !$destination || !$quantite || $quantite < 1) {
    echo json_encode(["success" => false, "message" => "Paramètres invalides."]);
    exit;
}

$stmt = $pdo->prepare("SELECT quantite_disponible, nom FROM stock WHERE id = ?");
$stmt->execute([$stockId]);
$stock = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$stock) {
    echo json_encode(["success" => false, "message" => "Article introuvable."]);
    exit;
}

if ($quantite > $stock['quantite_disponible']) {
    echo json_encode(["success" => false, "message" => "Quantité disponible insuffisante."]);
    exit;
}

// Déduction du stock disponible
$update = $pdo->prepare("UPDATE stock SET quantite_disponible = quantite_disponible - :qte WHERE id = :id");
$update->execute([':qte' => $quantite, ':id' => $stockId]);

// Si vers chantier
if ($destination !== "depot") {
    $check = $pdo->prepare("SELECT quantite FROM stock_chantiers WHERE stock_id = ? AND chantier_id = ?");
    $check->execute([$stockId, $destination]);
    $exist = $check->fetchColumn();

    if ($exist !== false) {
        $upd = $pdo->prepare("UPDATE stock_chantiers SET quantite = quantite + ? WHERE stock_id = ? AND chantier_id = ?");
        $upd->execute([$quantite, $stockId, $destination]);
    } else {
        $insert = $pdo->prepare("INSERT INTO stock_chantiers (stock_id, chantier_id, quantite) VALUES (?, ?, ?)");
        $insert->execute([$stockId, $destination, $quantite]);
    }
}

// Récupération des nouvelles données
$stmt = $pdo->prepare("SELECT quantite_disponible FROM stock WHERE id = ?");
$stmt->execute([$stockId]);
$dispo = $stmt->fetchColumn();

// Liste des chantiers mise à jour
$chantierStmt = $pdo->prepare("
    SELECT c.nom, sc.quantite
    FROM stock_chantiers sc
    JOIN chantiers c ON sc.chantier_id = c.id
    WHERE sc.stock_id = ?
");
$chantierStmt->execute([$stockId]);
$chantierRows = $chantierStmt->fetchAll(PDO::FETCH_ASSOC);

$chantiersHtml = "";
foreach ($chantierRows as $chantier) {
    $chantiersHtml .= "<div>" . htmlspecialchars($chantier['nom']) . " (" . (int)$chantier['quantite'] . ")</div>";
}

echo json_encode([
    "success" => true,
    "disponible" => $dispo,
    "chantiersHtml" => $chantiersHtml
]);
exit;
