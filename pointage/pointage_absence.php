<?php
declare(strict_types=1);
ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/init.php';
header('Content-Type: application/json; charset=utf-8');

try {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_SESSION['utilisateurs'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accès refusé']);
    exit;
  }

  $user         = $_SESSION['utilisateurs'];
  $entrepriseId = (int)($user['entreprise_id'] ?? 0);
  $role         = $user['fonction'] ?? '';
  $uidParam     = (int)($_POST['utilisateur_id'] ?? 0);
  $date         = $_POST['date'] ?? '';
  $reason       = $_POST['reason'] ?? ''; // 'conges'|'maladie'|'injustifie' | '' (pour retirer)
  $hours        = isset($_POST['hours']) ? (float)$_POST['hours'] : null; // heures d'absence (optionnel)
  $remove       = isset($_POST['remove']) ? (bool)$_POST['remove'] : false;

  // Seuls les admins peuvent poser pour quelqu’un d’autre
  $uid = ($role === 'administrateur' && $uidParam > 0) ? $uidParam : (int)($user['id'] ?? 0);

  // Validations communes
  if (!$entrepriseId || !$uid || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Paramètres invalides']);
    exit;
  }

  // Table (avec updated_at)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS pointages_absences (
      id INT AUTO_INCREMENT PRIMARY KEY,
      entreprise_id INT NOT NULL,
      utilisateur_id INT NOT NULL,
      date_jour DATE NOT NULL,
      motif ENUM('conges','maladie','injustifie') NOT NULL,
      heures DECIMAL(5,2) DEFAULT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      UNIQUE KEY uq (entreprise_id, utilisateur_id, date_jour)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  // --- Cas retrait / décochage de l'absence ---
  if ($remove || $reason === '') {
    $del = $pdo->prepare("
      DELETE FROM pointages_absences
      WHERE entreprise_id=:e AND utilisateur_id=:u AND date_jour=:d
    ");
    $del->execute([':e' => $entrepriseId, ':u' => $uid, ':d' => $date]);

    echo json_encode(['success' => true, 'removed' => true]);
    exit;
  }

  // --- Pose / maj d'une absence ---
  $validReasons = ['conges','maladie','injustifie'];
  if (!in_array($reason, $validReasons, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Motif invalide']);
    exit;
  }

  // Heures d’absence : si fourni, bornes ; sinon par défaut journée complète 8.25
  if ($hours === null) {
    $hours = 8.25; // défaut : journée
  }
  if ($hours <= 0 || $hours > 8.25) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Heures d’absence invalides']);
    exit;
  }
  $hours = round($hours, 2);

  // Lorsqu'on pose une absence, on supprime présence & conduites du jour (cohérence)
  $pdo->beginTransaction();
  try {
    $pdo->prepare("
      DELETE FROM pointages_jour
      WHERE entreprise_id=? AND utilisateur_id=? AND date_jour=?
    ")->execute([$entrepriseId, $uid, $date]);

    $pdo->prepare("
      DELETE FROM pointages_conduite
      WHERE entreprise_id=? AND utilisateur_id=? AND date_pointage=?
    ")->execute([$entrepriseId, $uid, $date]);

    // UPSERT absence
    $up = $pdo->prepare("
      INSERT INTO pointages_absences (entreprise_id, utilisateur_id, date_jour, motif, heures, created_at, updated_at)
      VALUES (:e, :u, :d, :m, :h, NOW(), NOW())
      ON DUPLICATE KEY UPDATE
        motif=VALUES(motif),
        heures=VALUES(heures),
        updated_at=NOW()
    ");
    $up->bindValue(':e', $entrepriseId, PDO::PARAM_INT);
    $up->bindValue(':u', $uid,          PDO::PARAM_INT);
    $up->bindValue(':d', $date,         PDO::PARAM_STR);
    $up->bindValue(':m', $reason,       PDO::PARAM_STR);
    // DECIMAL => bind en string
    $up->bindValue(':h', number_format($hours, 2, '.', ''), PDO::PARAM_STR);
    $up->execute();

    $pdo->commit();
  } catch (\Throwable $txe) {
    $pdo->rollBack();
    throw $txe;
  }

  echo json_encode(['success' => true, 'reason' => $reason, 'hours' => (float)$hours]);

} catch (Throwable $e) {
  error_log(basename(__FILE__) . ': ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
}
