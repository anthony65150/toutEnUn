<?php
// /pointage/pointage_present.php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
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
  $date         = (string)($_POST['date'] ?? '');
  $hours        = (float)($_POST['hours'] ?? 0);
  $chantierId   = isset($_POST['chantier_id']) && $_POST['chantier_id'] !== '' ? (int)$_POST['chantier_id'] : null;

  // --- Seuls les admins peuvent pointer pour quelqu’un d’autre
  $uid = ($role === 'administrateur' && $uidParam > 0) ? $uidParam : (int)($user['id'] ?? 0);

  // --- Validations de base
  if ($entrepriseId <= 0 || $uid <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Paramètres invalides'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Heures (0 accepté pour décocher)
  if ($hours < 0 || $hours > 24) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Bornes heures invalides'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $hours = round($hours, 2); // 8.25, etc.

  // (Option) si chantierId fourni, vérifier qu’il appartient bien à l’entreprise
  if ($chantierId !== null) {
    $stChk = $pdo->prepare("SELECT 1 FROM chantiers WHERE id = ? AND entreprise_id = ? LIMIT 1");
    $stChk->execute([$chantierId, $entrepriseId]);
    if (!$stChk->fetchColumn()) {
      http_response_code(404);
      echo json_encode(['success' => false, 'message' => 'Chantier introuvable'], JSON_UNESCAPED_UNICODE);
      exit;
    }
  }

  // --- Table cible
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS pointages_jour (
      id INT AUTO_INCREMENT PRIMARY KEY,
      entreprise_id INT NOT NULL,
      utilisateur_id INT NOT NULL,
      date_jour DATE NOT NULL,
      chantier_id INT NULL,
      heures DECIMAL(5,2) NOT NULL DEFAULT 0,
      updated_at DATETIME NULL,
      UNIQUE KEY uq (entreprise_id, utilisateur_id, date_jour),
      KEY idx_ent_date (entreprise_id, date_jour)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  // --- Si hours == 0 → suppression de la présence (décocher)
  if ($hours == 0.0) {
    $del = $pdo->prepare("
      DELETE FROM pointages_jour
      WHERE entreprise_id = :e AND utilisateur_id = :u AND date_jour = :d
    ");
    $del->execute([':e' => $entrepriseId, ':u' => $uid, ':d' => $date]);

    echo json_encode(['success' => true, 'hours' => 0, 'deleted' => true], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // --- Si heures > 0 → enlever une éventuelle absence ce jour-là
  $stmt = $pdo->prepare("
    DELETE FROM pointages_absences
    WHERE entreprise_id = ? AND utilisateur_id = ? AND date_jour = ?
  ");
  $stmt->execute([$entrepriseId, $uid, $date]);

  // --- UPSERT présence
  // NOTE: VALUES() est déprécié sur MySQL ≥ 8.0.20.
  // Si besoin, je te fournis la variante compatible (`AS new`).
  $up = $pdo->prepare("
    INSERT INTO pointages_jour (entreprise_id, utilisateur_id, date_jour, chantier_id, heures, updated_at)
    VALUES (:e, :u, :d, :c, :h, NOW())
    ON DUPLICATE KEY UPDATE
      chantier_id = VALUES(chantier_id),
      heures      = VALUES(heures),
      updated_at  = NOW()
  ");

  $up->bindValue(':e', $entrepriseId, PDO::PARAM_INT);
  $up->bindValue(':u', $uid,          PDO::PARAM_INT);
  $up->bindValue(':d', $date,         PDO::PARAM_STR);
  if ($chantierId === null) {
    $up->bindValue(':c', null, PDO::PARAM_NULL);
  } else {
    $up->bindValue(':c', $chantierId, PDO::PARAM_INT);
  }
  // DECIMAL → bind en string
  $up->bindValue(':h', number_format($hours, 2, '.', ''), PDO::PARAM_STR);

  $up->execute();

  echo json_encode([
    'success'     => true,
    'hours'       => (float)$hours,
    'chantier_id' => $chantierId
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  error_log(basename(__FILE__) . ': ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['success' => false, 'message' => 'Erreur serveur'], JSON_UNESCAPED_UNICODE);
}
