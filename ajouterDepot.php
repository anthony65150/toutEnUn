<?php
require_once "./config/init.php";

if (!isset($_SESSION['utilisateurs']) || $_SESSION['utilisateurs']['fonction'] !== 'administrateur') {
    header("Location: connexion.php");
    exit;
}

$depotId = isset($_POST['depot_id']) ? (int)$_POST['depot_id'] : 0;
$nom = trim($_POST['nom'] ?? '');
$responsable_id = isset($_POST['responsable_id']) && $_POST['responsable_id'] !== '' ? (int)$_POST['responsable_id'] : null;

if ($nom === '') {
    header("Location: depots_admin.php?success=error");
    exit;
}

if ($depotId > 0) {
    // UPDATE
    $stmt = $pdo->prepare("UPDATE depots SET nom = ?, responsable_id = ? WHERE id = ?");
    $stmt->execute([$nom, $responsable_id, $depotId]);

    header("Location: depots_admin.php?success=update&highlight={$depotId}");
    exit;
} else {
    // CREATE
    $stmt = $pdo->prepare("INSERT INTO depots (nom, responsable_id) VALUES (?, ?)");
    $stmt->execute([$nom, $responsable_id]);
    $newId = (int)$pdo->lastInsertId();

    header("Location: depots_admin.php?success=create&highlight={$newId}");
    exit;
}
