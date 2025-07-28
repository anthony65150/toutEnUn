<?php
require_once "./config/init.php";
file_put_contents('debug_post.txt', var_export($_POST, true));


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

$document = $_FILES['document'] ?? null;
$nom_document = null;
$deleteDoc = ($_POST['deleteDocument'] ?? '0') === '1';


if ($deleteDoc) {
    $stmt = $pdo->prepare("SELECT document FROM stock WHERE id = ?");
    $stmt->execute([$stockId]);
    $ancienDoc = $stmt->fetchColumn();

    if ($ancienDoc && file_exists(__DIR__ . "/uploads/documents/" . $ancienDoc)) {
        unlink(__DIR__ . "/uploads/documents/" . $ancienDoc);
    }

    $stmt = $pdo->prepare("UPDATE stock SET document = NULL WHERE id = ?");
    $stmt->execute([$stockId]);
}

if ($document && $document['error'] === UPLOAD_ERR_OK) {
    $extensionDoc = strtolower(pathinfo($document['name'], PATHINFO_EXTENSION));
    $extensionsAutorisees = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg'];

    if (in_array($extensionDoc, $extensionsAutorisees)) {
        $nom_document = uniqid() . '.' . $extensionDoc;
        move_uploaded_file($document['tmp_name'], __DIR__ . '/uploads/documents/' . $nom_document);
    } else {
        echo json_encode(['success' => false, 'message' => 'Type de fichier non autorisé.']);
        exit;
    }
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT quantite_totale FROM stock WHERE id = ?");
    $stmt->execute([$stockId]);
    $ancienneQuantite = (int)$stmt->fetchColumn();

    $diff = $quantite - $ancienneQuantite;

    if ($nom_document) {
        $stmt = $pdo->prepare("UPDATE stock SET nom = ?, quantite_totale = ?, quantite_disponible = quantite_disponible + ?, document = ? WHERE id = ?");
        $stmt->execute([$nom, $quantite, $diff, $nom_document, $stockId]);
    } else {
        $stmt = $pdo->prepare("UPDATE stock SET nom = ?, quantite_totale = ?, quantite_disponible = quantite_disponible + ? WHERE id = ?");
        $stmt->execute([$nom, $quantite, $diff, $stockId]);
    }

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

    $stmt = $pdo->prepare("SELECT quantite_disponible FROM stock WHERE id = ?");
    $stmt->execute([$stockId]);
    $quantiteDispo = (int)$stmt->fetchColumn();

    if (!$nom_document && !$deleteDoc) {
        $stmt = $pdo->prepare("SELECT document FROM stock WHERE id = ?");
        $stmt->execute([$stockId]);
        $nom_document = $stmt->fetchColumn();
    }

    echo json_encode([
        'success' => true,
        'newNom' => $nom,
        'newQuantiteTotale' => $quantite,
        'quantiteDispo' => $quantiteDispo,
        'newPhotoUrl' => isset($newPhotoUrl) ? $newPhotoUrl : null,
        'newDocument' => $nom_document
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
