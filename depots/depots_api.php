<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';

header('Content-Type: application/json; charset=utf-8');

/* =========================
   Garde-fous
   ========================= */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée'], JSON_UNESCAPED_UNICODE);
  exit;
}

if (!isset($_SESSION['utilisateurs']) || (($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur')) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'Non autorisé'], JSON_UNESCAPED_UNICODE);
  exit;
}

$entrepriseId = (int)($_SESSION['entreprise_id'] ?? 0);
if ($entrepriseId <= 0) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Entreprise non sélectionnée'], JSON_UNESCAPED_UNICODE);
  exit;
}

/* Helper erreur JSON */
$fail = function (int $code, string $msg): never {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
};

$action = strtolower(trim((string)($_POST['action'] ?? '')));

try {
  /* =========================
     CREATE
     ========================= */
  if ($action === 'create') {
    $nom  = trim((string)($_POST['nom'] ?? ''));
    $resp = ($_POST['responsable_id'] ?? '') === '' ? null : (int)$_POST['responsable_id'];

    if ($nom === '') $fail(422, 'Nom obligatoire');

    // Responsable doit appartenir à la même entreprise
    if ($resp !== null) {
      $chk = $pdo->prepare("SELECT id FROM utilisateurs WHERE id = :uid AND entreprise_id = :eid");
      $chk->execute([':uid' => $resp, ':eid' => $entrepriseId]);
      if (!$chk->fetch()) $fail(422, "Responsable hors de l'entreprise");
    }

    $st = $pdo->prepare("
      INSERT INTO depots (nom, responsable_id, entreprise_id, created_at)
      VALUES (:nom, :resp, :eid, NOW())
    ");
    $st->bindValue(':nom', $nom, PDO::PARAM_STR);
    $st->bindValue(':resp', $resp, $resp === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue(':eid', $entrepriseId, PDO::PARAM_INT);
    $st->execute();

    echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
    exit;
  }

  /* =========================
     UPDATE
     ========================= */
  if ($action === 'update') {
    $id   = (int)($_POST['id'] ?? 0);
    $nom  = trim((string)($_POST['nom'] ?? ''));
    $resp = ($_POST['responsable_id'] ?? '') === '' ? null : (int)$_POST['responsable_id'];

    if ($id <= 0)    $fail(422, 'ID invalide');
    if ($nom === '') $fail(422, 'Nom obligatoire');

    // Ownership du dépôt
    $own = $pdo->prepare("SELECT id FROM depots WHERE id = :id AND entreprise_id = :eid");
    $own->execute([':id' => $id, ':eid' => $entrepriseId]);
    if (!$own->fetch()) $fail(404, 'Dépôt introuvable');

    // Responsable doit appartenir à la même entreprise
    if ($resp !== null) {
      $chk = $pdo->prepare("SELECT id FROM utilisateurs WHERE id = :uid AND entreprise_id = :eid");
      $chk->execute([':uid' => $resp, ':eid' => $entrepriseId]);
      if (!$chk->fetch()) $fail(422, "Responsable hors de l'entreprise");
    }

    $st = $pdo->prepare("
      UPDATE depots
         SET nom = :nom,
             responsable_id = :resp
       WHERE id = :id
         AND entreprise_id = :eid
    ");
    $st->bindValue(':nom', $nom, PDO::PARAM_STR);
    $st->bindValue(':resp', $resp, $resp === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue(':id', $id, PDO::PARAM_INT);
    $st->bindValue(':eid', $entrepriseId, PDO::PARAM_INT);
    $st->execute();

    echo json_encode(['ok' => true, 'id' => $id, 'rows' => $st->rowCount()], JSON_UNESCAPED_UNICODE);
    exit;
  }

  /* =========================
     DELETE
     ========================= */
  if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) $fail(422, 'ID invalide');

    // Ownership du dépôt
    $own = $pdo->prepare("SELECT id FROM depots WHERE id = :id AND entreprise_id = :eid");
    $own->execute([':id' => $id, ':eid' => $entrepriseId]);
    if (!$own->fetch()) $fail(404, 'Dépôt introuvable');

    // Empêcher suppression si dépôt non vide
    $chk = $pdo->prepare("SELECT COUNT(*) FROM stock_depots WHERE depot_id = :id");
    $chk->execute([':id' => $id]);
    if ((int)$chk->fetchColumn() > 0) $fail(409, 'Impossible de supprimer un dépôt non vide');

    $st = $pdo->prepare("DELETE FROM depots WHERE id = :id AND entreprise_id = :eid");
    $st->execute([':id' => $id, ':eid' => $entrepriseId]);

    if ($st->rowCount() === 0) $fail(500, 'Suppression non effectuée');

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $fail(400, 'Action inconnue');

} catch (PDOException $e) {
  // Doublon si vous avez un index UNIQUE (entreprise_id, nom)
  if (($e->errorInfo[1] ?? 0) == 1062) {
    $fail(409, "Un dépôt avec ce nom existe déjà dans cette entreprise");
  }
  $fail(500, 'Erreur serveur');
} catch (Throwable $e) {
  $fail(500, $e->getMessage());
}
