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

        // Nom de fichier basé sur l'id du stock pour écraser l'ancienne photo
        $photoFilename = $stockId . '.' . $ext;
        $photoPath = $uploadDir . $photoFilename;

        if (!move_uploaded_file($_FILES['photo']['tmp_name'], $photoPath)) {
            throw new Exception('Erreur lors de l\'upload de la photo.');
        }

        $newPhotoUrl = '/uploads/photos/' . $photoFilename;

        // Mise à jour du champ photo dans la base
        $stmt = $pdo->prepare("UPDATE stock SET photo = ? WHERE id = ?");
        $stmt->execute([$photoFilename, $stockId]);
    }

    // Mise à jour du nom et quantité totale
    $stmt = $pdo->prepare("UPDATE stock SET nom = ?, quantite_totale = ?, quantite_disponible = quantite_disponible + (? - quantite_totale) WHERE id = ?");
    // On ajuste quantite_disponible en fonction de la différence entre nouvelle et ancienne quantite_totale
    // Cette requête suppose que la quantité disponible est ajustée automatiquement.
    $stmt->execute([$nom, $quantite, $quantite, $stockId]);

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
