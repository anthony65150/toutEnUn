<?php
require_once "./config/init.php";

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["success" => false, "message" => "Méthode non autorisée"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

$stockId = (int)($data['stockId'] ?? 0);
$source = $data['source'] ?? null; // peut être un id ou "depot"
$destination = $data['destination'] ?? null;
$qty = (int)($data['qty'] ?? 0);

if (!$stockId || !$qty || !$destination || $destination === $source) {
    echo json_encode(["success" => false, "message" => "Données manquantes ou invalides."]);
    exit;
}

try {
    $pdo->beginTransaction();

    // Vérification de stock disponible
    if ($source === "depot") {
        $stmt = $pdo->prepare("SELECT quantite_disponible FROM stock WHERE id = :id");
        $stmt->execute([':id' => $stockId]);
        $available = (int)($stmt->fetchColumn() ?? 0);

        if ($available < $qty) {
            echo json_encode(["success" => false, "message" => "Stock insuffisant en dépôt."]);
            exit;
        }

        // Retirer du dépôt
        $stmt = $pdo->prepare("UPDATE stock SET quantite_disponible = quantite_disponible - :q WHERE id = :id");
        $stmt->execute([':q' => $qty, ':id' => $stockId]);
    } else {
        // chantier → ?
        $stmt = $pdo->prepare("SELECT quantite FROM stock_chantiers WHERE chantier_id = :cid AND stock_id = :sid");
        $stmt->execute([':cid' => $source, ':sid' => $stockId]);
        $currentQty = (int)($stmt->fetchColumn() ?? 0);

        if ($currentQty < $qty) {
            echo json_encode(["success" => false, "message" => "Stock insuffisant sur le chantier source."]);
            exit;
        }

        // Retirer du chantier
        $stmt = $pdo->prepare("UPDATE stock_chantiers SET quantite = quantite - :q WHERE chantier_id = :cid AND stock_id = :sid");
        $stmt->execute([':q' => $qty, ':cid' => $source, ':sid' => $stockId]);
    }

    // Ajouter à la destination
    if ($destination === "depot") {
        $stmt = $pdo->prepare("UPDATE stock SET quantite_disponible = quantite_disponible + :q WHERE id = :id");
        $stmt->execute([':q' => $qty, ':id' => $stockId]);
    } else {
        // chantier destination
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_chantiers WHERE chantier_id = :cid AND stock_id = :sid");
        $stmt->execute([':cid' => $destination, ':sid' => $stockId]);
        $exists = $stmt->fetchColumn();

        if ($exists) {
            $stmt = $pdo->prepare("UPDATE stock_chantiers SET quantite = quantite + :q WHERE chantier_id = :cid AND stock_id = :sid");
        } else {
            $stmt = $pdo->prepare("INSERT INTO stock_chantiers (chantier_id, stock_id, quantite) VALUES (:cid, :sid, :q)");
        }
        $stmt->execute([':cid' => $destination, ':sid' => $stockId, ':q' => $qty]);
    }

    // Recalcul pour retour JSON
    $stmt = $pdo->prepare("SELECT quantite_disponible, quantite_totale FROM stock WHERE id = :id");
    $stmt->execute([':id' => $stockId]);
    $s = $stmt->fetch(PDO::FETCH_ASSOC);
    $dispo = (int)($s['quantite_disponible'] ?? 0);
    $total = (int)($s['quantite_totale'] ?? 0);
    $surChantier = $total - $dispo;

    // Préparer HTML admin col
    $stmt = $pdo->prepare("SELECT c.nom, sc.quantite FROM stock_chantiers sc JOIN chantiers c ON sc.chantier_id = c.id WHERE sc.stock_id = :id");
    $stmt->execute([':id' => $stockId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    foreach ($rows as $row) {
        echo "<div>" . htmlspecialchars($row['nom']) . " (" . (int)$row['quantite'] . ")</div>";
    }
    $chantiersHtml = ob_get_clean();

    $pdo->commit();
    echo json_encode([
        "success" => true,
        "disponible" => $dispo,
        "surChantier" => $surChantier,
        "chantiersHtml" => $chantiersHtml
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(["success" => false, "message" => "Erreur serveur."]);
}
