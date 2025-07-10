<?php
require_once "./config/init.php";

header('Content-Type: application/json');

$id = isset($_POST['stockId']) ? (int)$_POST['stockId'] : null;
$newNom = $_POST['nom'] ?? null;
$newTotal = isset($_POST['quantite']) ? (int)$_POST['quantite'] : null;

if (!$id || !$newNom || !$newTotal) {
    echo json_encode(['success' => false, 'message' => 'Données manquantes']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Récupérer les anciennes valeurs
    $stmt = $pdo->prepare("SELECT quantite_totale, quantite_disponible FROM stock WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $stock = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$stock) {
        throw new Exception('Article introuvable');
    }

    $oldTotal = (int)$stock['quantite_totale'];
    $oldDispo = (int)$stock['quantite_disponible'];
    $diff = $newTotal - $oldTotal;
    $newDispo = $oldDispo + $diff;
    if ($newDispo < 0) $newDispo = 0;

    // Mettre à jour nom et quantités
    $stmt = $pdo->prepare("UPDATE stock SET nom = :nom, quantite_totale = :total, quantite_disponible = :dispo WHERE id = :id");
    $stmt->execute([
        ':nom' => $newNom,
        ':total' => $newTotal,
        ':dispo' => $newDispo,
        ':id' => $id
    ]);

    // Gérer la photo si fournie
    if (!empty($_FILES['photo']['name'])) {
        $uploadDir = __DIR__ . '/uploads/photos/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $targetPath = $uploadDir . $id . '.jpg';
        if (!move_uploaded_file($_FILES['photo']['tmp_name'], $targetPath)) {
            throw new Exception('Erreur lors de l’enregistrement de la photo.');
        }
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'newNom' => $newNom,
        'newTotal' => $newTotal,
        'newDispo' => $newDispo
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
