<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';

if (!isset($_SESSION['utilisateurs']) || (($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur')) {
    header("Location: ../connexion.php");
    exit;
}

$entrepriseId = (int)($_SESSION['entreprise_id'] ?? 0);
if ($entrepriseId <= 0) {
    http_response_code(403);
    exit('Entreprise non sélectionnée');
}

/* ID envoyé par le formulaire (name="id" dans la modal) */
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header("Location: ./depots_admin.php");
    exit;
}

/* Vérifier que le dépôt appartient bien à l'entreprise courante */
$own = $pdo->prepare("SELECT id FROM depots WHERE id = :id AND entreprise_id = :eid");
$own->execute([':id' => $id, ':eid' => $entrepriseId]);
if (!$own->fetch()) {
    header("Location: ./depots_admin.php?success=error");
    exit;
}

/* Empêcher la suppression s'il reste du stock dans ce dépôt */
$check = $pdo->prepare("SELECT COUNT(*) FROM stock_depots WHERE depot_id = :id");
$check->execute([':id' => $id]);
if ((int)$check->fetchColumn() > 0) {
    header("Location: ./depots_admin.php?success=error"); // dépôt non vide
    exit;
}

try {
    $stmt = $pdo->prepare("DELETE FROM depots WHERE id = :id AND entreprise_id = :eid");
    $stmt->execute([':id' => $id, ':eid' => $entrepriseId]);

    if ($stmt->rowCount() === 0) {
        header("Location: ./depots_admin.php?success=error");
        exit;
    }
    header("Location: ./depots_admin.php?success=delete");
    exit;
} catch (PDOException $e) {
    header("Location: ./depots_admin.php?success=error");
    exit;
}
