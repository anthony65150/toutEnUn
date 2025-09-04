<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/init.php';
header('Content-Type: application/json; charset=utf-8');

/* ===== Auth ===== */
if (
  !isset($_SESSION['utilisateurs']) ||
  (($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur')
) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'message' => 'Non autorisé']);
  exit;
}

/* ===== Contexte multi-entreprise ===== */
$entrepriseId = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);
if ($entrepriseId <= 0) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'message' => 'Entreprise introuvable en session']);
  exit;
}

/* ===== Inputs ===== */
$uid  = (int)($_POST['emp_id'] ?? 0);
$jour = $_POST['date'] ?? (new DateTime('today'))->format('Y-m-d');

/* Validations basiques */
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

  /* ===== Stratégie 1 : supprimer la ligne du jour ===== */
  $del = $pdo->prepare("
    DELETE FROM planning_affectations
    WHERE utilisateur_id = :u
      AND date_jour      = :d
      AND entreprise_id  = :e
  ");
  $del->execute([':u' => $uid, ':d' => $jour, ':e' => $entrepriseId]);

  // Optionnel : vérifier si une ligne a été supprimée
  // if ($del->rowCount() === 0) { /* rien à supprimer */ }

  /* ===== Stratégie 2 (alternative) : mettre à NULL sans supprimer =====
  $upd = $pdo->prepare("
    UPDATE planning_affectations
    SET chantier_id = NULL
    WHERE utilisateur_id = :u
      AND date_jour      = :d
      AND entreprise_id  = :e
  ");
  $upd->execute([':u' => $uid, ':d' => $jour, ':e' => $entrepriseId]);
  */

  echo json_encode(['ok' => true, 'date' => $jour]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
