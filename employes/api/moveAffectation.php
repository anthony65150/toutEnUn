<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/init.php';
header('Content-Type: application/json; charset=utf-8');

/* ====== Auth ====== */
if (
  !isset($_SESSION['utilisateurs']) ||
  (($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur')
) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'message' => 'Non autorisé']);
  exit;
}

/* ====== Contexte multi-entreprise ====== */
$entrepriseId = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);
if ($entrepriseId <= 0) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'message' => 'Entreprise introuvable en session']);
  exit;
}

/* ====== Inputs ====== */
$uid  = (int)($_POST['emp_id'] ?? 0);
$cidR = $_POST['chantier_id'] ?? null;            // peut être "0" (=> null)
$cid  = ($cidR === '0' || $cidR === 0) ? null : (int)$cidR;
$jour = $_POST['date'] ?? (new DateTime('today'))->format('Y-m-d');

/* Validations */
if ($uid <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'Employé manquant']);
  exit;
}
$dt = DateTime::createFromFormat('Y-m-d', $jour);
$validDate = $dt && $dt->format('Y-m-d') === $jour;
if (!$validDate) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'Date invalide (format attendu YYYY-MM-DD)']);
  exit;
}

$pdo->beginTransaction();
try {
  /* Vérifier que l’employé appartient à l’entreprise */
  $st = $pdo->prepare("
    SELECT 1
    FROM utilisateurs
    WHERE id = :u AND entreprise_id = :e
    LIMIT 1
  ");
  $st->execute([':u' => $uid, ':e' => $entrepriseId]);
  if (!$st->fetchColumn()) {
    throw new Exception("Employé introuvable dans votre entreprise");
  }

  /* Vérifier que le chantier (si défini) appartient à l’entreprise */
  if ($cid !== null) {
    $st = $pdo->prepare("
      SELECT 1
      FROM chantiers
      WHERE id = :c AND entreprise_id = :e
      LIMIT 1
    ");
    $st->execute([':c' => $cid, ':e' => $entrepriseId]);
    if (!$st->fetchColumn()) {
      throw new Exception("Chantier introuvable dans votre entreprise");
    }
  }

  /* UPSERT : 1 ligne max / (entreprise, employé, date) */
  // ⚠️ Assure-toi d’avoir un index/clé unique sur (entreprise_id, utilisateur_id, date_jour)
  $sql = "
    INSERT INTO planning_affectations (entreprise_id, utilisateur_id, chantier_id, date_jour)
    VALUES (:e, :u, :c, :d)
    ON DUPLICATE KEY UPDATE chantier_id = VALUES(chantier_id)
  ";
  $st = $pdo->prepare($sql);
  $st->bindValue(':e', $entrepriseId, PDO::PARAM_INT);
  $st->bindValue(':u', $uid,          PDO::PARAM_INT);
  $st->bindValue(':d', $jour,         PDO::PARAM_STR);
  if ($cid === null) {
    $st->bindValue(':c', null, PDO::PARAM_NULL);
  } else {
    $st->bindValue(':c', $cid, PDO::PARAM_INT);
  }
  $st->execute();

  $pdo->commit();
  echo json_encode(['ok' => true, 'date' => $jour]);
} catch (Throwable $e) {
  $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
