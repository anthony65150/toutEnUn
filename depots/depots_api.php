<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';
header('Content-Type: application/json; charset=utf-8');

// Méthode et autorisation
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée']); exit;
}
if (!isset($_SESSION['utilisateurs']) || ($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur') {
  echo json_encode(['ok' => false, 'error' => 'Non autorisé']); exit;
}

$action = $_POST['action'] ?? '';

try {
  switch ($action) {
    case 'create': {
      $nom  = trim($_POST['nom'] ?? '');
      $resp = $_POST['responsable_id'] ?? '';
      $resp = ($resp === '' ? null : (int)$resp);

      if ($nom === '') throw new Exception('Nom obligatoire');

      $stmt = $pdo->prepare(
        "INSERT INTO depots (nom, responsable_id, created_at)
         VALUES (:nom, :resp, NOW())"
      );
      $stmt->bindValue(':nom', $nom, PDO::PARAM_STR);
      if ($resp === null) $stmt->bindValue(':resp', null, PDO::PARAM_NULL);
      else                $stmt->bindValue(':resp', $resp, PDO::PARAM_INT);
      $stmt->execute();

      echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
      break;
    }

    case 'update': {
      $id   = (int)($_POST['id'] ?? 0);
      $nom  = trim($_POST['nom'] ?? '');
      $resp = $_POST['responsable_id'] ?? '';
      $resp = ($resp === '' ? null : (int)$resp);

      if ($id <= 0)    throw new Exception('ID manquant');
      if ($nom === '') throw new Exception('Nom obligatoire');

      $stmt = $pdo->prepare(
        "UPDATE depots SET nom = :nom, responsable_id = :resp WHERE id = :id"
      );
      $stmt->bindValue(':nom', $nom, PDO::PARAM_STR);
      if ($resp === null) $stmt->bindValue(':resp', null, PDO::PARAM_NULL);
      else                $stmt->bindValue(':resp', $resp, PDO::PARAM_INT);
      $stmt->bindValue(':id', $id, PDO::PARAM_INT);
      $stmt->execute();

      echo json_encode(['ok' => true, 'id' => $id, 'rows' => $stmt->rowCount()]);
      break;
    }

    case 'delete': {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new Exception('ID manquant');

      // (Option) vérifier des contraintes avant suppression si nécessaire
      $pdo->prepare("DELETE FROM depots WHERE id = ?")->execute([$id]);

      echo json_encode(['ok' => true]);
      break;
    }

    default:
      echo json_encode(['ok' => false, 'error' => 'Action inconnue']);
  }
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
