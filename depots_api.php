<?php
require_once "./config/init.php";
header('Content-Type: application/json');

if (!isset($_SESSION['utilisateurs']) || $_SESSION['utilisateurs']['fonction'] !== 'administrateur') {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'forbidden']);
  exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? null;
if (!$action) { echo json_encode(['ok'=>false,'error'=>'no action']); exit; }

try {
  if ($action === 'create') {
    $nom = trim($_POST['nom'] ?? '');
    if ($nom === '') throw new Exception('Nom obligatoire');
    $responsable_id = !empty($_POST['responsable_id']) ? (int)$_POST['responsable_id'] : null;

    // unicité optionnelle
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM depots WHERE nom = ?");
    $stmt->execute([$nom]);
    if ($stmt->fetchColumn() > 0) throw new Exception("Un dépôt '$nom' existe déjà");

    $stmt = $pdo->prepare("INSERT INTO depots (nom, responsable_id) VALUES (?, ?)");
    $stmt->execute([$nom, $responsable_id]);

    echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]);
    exit;
  }

  if ($action === 'update') {
    $id  = (int)($_POST['id'] ?? 0);
    $nom = trim($_POST['nom'] ?? '');
    if (!$id) throw new Exception('ID manquant');
    if ($nom === '') throw new Exception('Nom obligatoire');
    $responsable_id = !empty($_POST['responsable_id']) ? (int)$_POST['responsable_id'] : null;

    $stmt = $pdo->prepare("UPDATE depots SET nom=?, responsable_id=? WHERE id=?");
    $stmt->execute([$nom, $responsable_id, $id]);

    echo json_encode(['ok'=>true]);
    exit;
  }

  if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) throw new Exception('ID manquant');

    // Si tu as une table stock_depots, on empêche la suppression s'il reste du stock
    $hasStock = $pdo->prepare("SELECT COUNT(*) FROM stock_depots WHERE depot_id = ?");
    if ($hasStock->execute([$id]) && $hasStock->fetchColumn() > 0) {
      throw new Exception("Impossible: des articles sont encore rattachés à ce dépôt.");
    }

    $stmt = $pdo->prepare("DELETE FROM depots WHERE id=?");
    $stmt->execute([$id]);

    echo json_encode(['ok'=>true]);
    exit;
  }

  echo json_encode(['ok'=>false,'error'=>'unknown action']);
} catch (Exception $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
