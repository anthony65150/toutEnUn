<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';

if (!isset($_SESSION['utilisateurs']) || ($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur') {
    header("Location: ../connexion.php");
    exit;
}

$depotId        = isset($_POST['depot_id']) ? (int)$_POST['depot_id'] : 0;
$nom            = trim($_POST['nom'] ?? '');
$responsable_id = (isset($_POST['responsable_id']) && $_POST['responsable_id'] !== '')
    ? (int)$_POST['responsable_id']
    : null;

if ($nom === '') {
    header("Location: ./depots_admin.php?success=error");
    exit;
}

if ($depotId > 0) {
    // ===== UPDATE =====
    $stmt = $pdo->prepare("UPDATE depots SET nom = :nom, responsable_id = :resp WHERE id = :id");
    $stmt->bindValue(':nom', $nom, PDO::PARAM_STR);
    if ($responsable_id === null) $stmt->bindValue(':resp', null, PDO::PARAM_NULL);
    else                          $stmt->bindValue(':resp', $responsable_id, PDO::PARAM_INT);
    $stmt->bindValue(':id', $depotId, PDO::PARAM_INT);
    $stmt->execute();

    header("Location: ./depots_admin.php?success=update&highlight={$depotId}");
    exit;
} else {
    // ===== CREATE =====
    $stmt = $pdo->prepare("INSERT INTO depots (nom, responsable_id) VALUES (:nom, :resp)");
    $stmt->bindValue(':nom', $nom, PDO::PARAM_STR);
    if ($responsable_id === null) $stmt->bindValue(':resp', null, PDO::PARAM_NULL);
    else                          $stmt->bindValue(':resp', $responsable_id, PDO::PARAM_INT);
    $stmt->execute();

    $newId = (int)$pdo->lastInsertId();
    header("Location: ./depots_admin.php?success=create&highlight={$newId}");
    exit;
}
