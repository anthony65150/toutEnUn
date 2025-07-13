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

    // Gestion photo si upload
    $newPhotoUrl = null;
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/uploads/photos/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($ext, $allowedExt)) {
            throw new Exception('Format de photo non autorisé.');
        }

        $photoFilename = $stockId . '.' . $ext;
        $photoPath = $uploadDir . $photoFilename;

        if (!move_uploaded_file($_FILES['photo']['tmp_name'], $photoPath)) {
            throw new Exception('Erreur lors de l\'upload de la photo.');
        }

        $newPhotoUrl = '/uploads/photos/' . $photoFilename;

        $stmt = $pdo->prepare("UPDATE stock SET photo = ? WHERE id = ?");
        $stmt->execute([$photoFilename, $stockId]);
    }

    // Récupérer l'ancienne quantité totale
    $stmt = $pdo->prepare("SELECT quantite_totale FROM stock WHERE id = ?");
    $stmt->execute([$stockId]);
    $ancienneQuantite = (int)$stmt->fetchColumn();

    // Calculer la différence
    $diff = $quantite - $ancienneQuantite;

    // Mettre à jour nom, quantite_totale et ajuster quantite_disponible dans stock
    $stmt = $pdo->prepare("UPDATE stock SET nom = ?, quantite_totale = ?, quantite_disponible = quantite_disponible + ? WHERE id = ?");
    $stmt->execute([$nom, $quantite, $diff, $stockId]);

    // Mettre à jour la quantité dans stock_depots (ajuste la quantité en fonction de la différence)
    // On considère ici depot_id = 1, adapte si besoin
    $stmtDepotCheck = $pdo->prepare("SELECT COUNT(*) FROM stock_depots WHERE stock_id = ? AND depot_id = 1");
    $stmtDepotCheck->execute([$stockId]);
    $existsDepot = (int)$stmtDepotCheck->fetchColumn();

    if ($existsDepot) {
        $stmtDepotUpdate = $pdo->prepare("UPDATE stock_depots SET quantite = quantite + ? WHERE stock_id = ? AND depot_id = 1");
        $stmtDepotUpdate->execute([$diff, $stockId]);
    } else {
        // Si jamais pas d'entrée dans stock_depots, on l'insère
        $stmtDepotInsert = $pdo->prepare("INSERT INTO stock_depots (stock_id, depot_id, quantite) VALUES (?, 1, ?)");
        $stmtDepotInsert->execute([$stockId, $quantite]);
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
        'newPhotoUrl' => $newPhotoUrl,
        'quantiteDispo' => $quantiteDispo
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
