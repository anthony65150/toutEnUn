<?php
require_once "./config/init.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

$input = json_decode(file_get_contents("php://input"), true);
$stockId = isset($input['stockId']) ? (int)$input['stockId'] : null;
$destination = $input['destination'] ?? null;
$quantite = isset($input['qty']) ? (int)$input['qty'] : null;

$utilisateur = $_SESSION['utilisateurs'] ?? null;
$chantierId = $utilisateur['chantier_id'] ?? null;

if (!$stockId || !$destination || !$quantite || !$chantierId) {
    echo json_encode(["success" => false, "message" => "Données manquantes"]);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT quantite FROM stock_chantiers WHERE chantier_id = :chantier_id AND stock_id = :stock_id");
    $stmt->execute([':chantier_id' => $chantierId, ':stock_id' => $stockId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $quantiteActuelle = (int)($row['quantite'] ?? 0);

    if ($quantiteActuelle < $quantite) {
        echo json_encode(["success" => false, "message" => "Stock insuffisant"]);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE stock_chantiers SET quantite = quantite - :quantite WHERE chantier_id = :chantier_id AND stock_id = :stock_id");
    $stmt->execute([':quantite' => $quantite, ':chantier_id' => $chantierId, ':stock_id' => $stockId]);

    if ($destination === "depot") {
        $stmt = $pdo->prepare("UPDATE stock SET quantite_disponible = quantite_disponible + :quantite WHERE id = :stock_id");
        $stmt->execute([':quantite' => $quantite, ':stock_id' => $stockId]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_chantiers WHERE chantier_id = :chantier_id AND stock_id = :stock_id");
        $stmt->execute([':chantier_id' => $destination, ':stock_id' => $stockId]);
        $exists = $stmt->fetchColumn();

        if ($exists) {
            $stmt = $pdo->prepare("UPDATE stock_chantiers SET quantite = quantite + :quantite WHERE chantier_id = :chantier_id AND stock_id = :stock_id");
        } else {
            $stmt = $pdo->prepare("INSERT INTO stock_chantiers (chantier_id, stock_id, quantite) VALUES (:chantier_id, :stock_id, :quantite)");
        }
        $stmt->execute([':chantier_id' => $destination, ':stock_id' => $stockId, ':quantite' => $quantite]);
    }

    $pdo->commit();

    // Retourne la nouvelle quantité pour mise à jour JS
    $stmt = $pdo->prepare("SELECT quantite FROM stock_chantiers WHERE chantier_id = :chantier_id AND stock_id = :stock_id");
    $stmt->execute([':chantier_id' => $chantierId, ':stock_id' => $stockId]);
    $updatedQty = (int)($stmt->fetchColumn() ?? 0);

    $stmt = $pdo->prepare("SELECT quantite_disponible FROM stock WHERE id = :stock_id");
    $stmt->execute([':stock_id' => $stockId]);
    $dispo = (int)($stmt->fetchColumn() ?? 0);

    $stmt = $pdo->prepare("SELECT quantite_totale FROM stock WHERE id = :stock_id");
    $stmt->execute([':stock_id' => $stockId]);
    $total = (int)($stmt->fetchColumn() ?? 0);
    $surChantier = $total - $dispo;

    echo json_encode([
        "success" => true,
        "chantierQuantite" => $updatedQty,
        "disponible" => $dispo,
        "surChantier" => $surChantier
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(["success" => false, "message" => "Erreur serveur"]);
}
