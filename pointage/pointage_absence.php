<?php
// /pointage/pointage_absence.php
declare(strict_types=1);

ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/init.php';
header('Content-Type: application/json; charset=utf-8');

try {
  // --- Sécurité requête & session
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_SESSION['utilisateurs'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accès refusé'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $user         = $_SESSION['utilisateurs'];
  $entrepriseId = (int)($user['entreprise_id'] ?? 0);
  $role         = (string)($user['fonction'] ?? '');
  $uidParam     = (int)($_POST['utilisateur_id'] ?? 0);

  // Seuls les admins peuvent agir pour quelqu’un d’autre
  $uid = ($role === 'administrateur' && $uidParam > 0) ? $uidParam : (int)($user['id'] ?? 0);

  $date   = (string)($_POST['date'] ?? '');
  $reason = strtolower(trim((string)($_POST['reason'] ?? ''))); // 'conges'|'maladie'|'injustifie'|''

  // remove peut arriver en '1', 'true', 'on'...
  $remove = filter_var($_POST['remove'] ?? '0', FILTER_VALIDATE_BOOLEAN);

  // heures d’absence (optionnel). Si non fourni → journée (8.25)
  $hours = isset($_POST['hours']) ? (float)$_POST['hours'] : null;

  // Validations communes
  if ($entrepriseId <= 0 || $uid <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Paramètres invalides'], JSON_UNESCAPED_UNICODE);
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

  /* ==========================
     Retrait / décochage
  ========================== */
  if ($remove || $reason === '') {
    $del = $pdo->prepare("
      DELETE FROM pointages_absences
      WHERE entreprise_id = :e AND utilisateur_id = :u AND date_jour = :d
    ");
    $del->execute([':e' => $entrepriseId, ':u' => $uid, ':d' => $date]);

    echo json_encode(['success' => true, 'removed' => true], JSON_UNESCAPED_UNICODE);
    exit;
  }

  /* ==========================
     Pose / mise à jour
  ========================== */
  $validReasons = ['conges','maladie','injustifie'];
  if (!in_array($reason, $validReasons, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Motif invalide'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Heures : par défaut journée complète si non fourni
  if ($hours === null) $hours = 8.25;

  // Normalisation & bornes (min 0.25h, max 8.25h, pas de négatif)
  if ($hours < 0.25 || $hours > 8.25) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Heures d’absence invalides (0.25 à 8.25).'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  // aligner sur des quarts d’heure (0.25) pour éviter les flottants bizarres
  $hours = round($hours / 0.25) * 0.25;
  $hours = min(8.25, max(0.25, $hours));
  $hoursStr = number_format($hours, 2, '.', ''); // pour DECIMAL

  // Cohérence: poser une absence supprime présence & conduites du jour
  $pdo->beginTransaction();
  try {
    $pdo->prepare("
      DELETE FROM pointages_jour
      WHERE entreprise_id = ? AND utilisateur_id = ? AND date_jour = ?
    ")->execute([$entrepriseId, $uid, $date]);

    $pdo->prepare("
      DELETE FROM pointages_conduite
      WHERE entreprise_id = ? AND utilisateur_id = ? AND date_pointage = ?
    ")->execute([$entrepriseId, $uid, $date]);

    // UPSERT absence
    // NOTE: si MySQL ≥ 8.0.20 et VALUES() pose souci, remplace par la variante AS new
    $up = $pdo->prepare("
      INSERT INTO pointages_absences (entreprise_id, utilisateur_id, date_jour, motif, heures, created_at, updated_at)
      VALUES (:e, :u, :d, :m, :h, NOW(), NOW())
      ON DUPLICATE KEY UPDATE
        motif = VALUES(motif),
        heures = VALUES(heures),
        updated_at = NOW()
    ");

    $up->bindValue(':e', $entrepriseId, PDO::PARAM_INT);
    $up->bindValue(':u', $uid,          PDO::PARAM_INT);
    $up->bindValue(':d', $date,         PDO::PARAM_STR);
    $up->bindValue(':m', $reason,       PDO::PARAM_STR);
    $up->bindValue(':h', $hoursStr,     PDO::PARAM_STR);
    $up->execute();

    $pdo->commit();
  } catch (Throwable $txe) {
    $pdo->rollBack();
    throw $txe;
  }

  echo json_encode(['success' => true, 'reason' => $reason, 'hours' => (float)$hours], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  error_log(basename(__FILE__) . ': ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['success' => false, 'message' => 'Erreur serveur'], JSON_UNESCAPED_UNICODE);
}
