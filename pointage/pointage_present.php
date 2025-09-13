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
  $hours        = (float)($_POST['hours'] ?? 0);
  $chantierId   = isset($_POST['chantier_id']) && $_POST['chantier_id'] !== '' ? (int)$_POST['chantier_id'] : null;

  // Seuls les admins peuvent pointer pour un autre utilisateur
  $uid = $role === 'administrateur' && $uidParam > 0 ? $uidParam : (int)($user['id'] ?? 0);

  // validations de base
  if (!$entrepriseId || !$uid || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Paramètres invalides']);
    exit;
  }

  // Normalise les heures (0 autorisé pour "décocher")
  if ($hours < 0 || $hours > 24) { // borne large ; mets 8.25 si tu veux verrouiller la journée
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Bornes heures invalides']);
    exit;
  }
  $hours = round($hours, 2);

  // Table cible (avec clé unique) + colonne de suivi
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

  // Si hours == 0 => on supprime l'enregistrement de présence (décocher)
  if ($hours == 0.0) {
    $del = $pdo->prepare("
      DELETE FROM pointages_jour
      WHERE entreprise_id = :e AND utilisateur_id = :u AND date_jour = :d
    ");
    $del->execute([':e' => $entrepriseId, ':u' => $uid, ':d' => $date]);

    echo json_encode(['success' => true, 'hours' => 0, 'deleted' => true]);
    exit;
  }

  // Si on met des heures > 0, on retire une éventuelle absence ce jour-là
  $stmt = $pdo->prepare("
    DELETE FROM pointages_absences
    WHERE entreprise_id = ? AND utilisateur_id = ? AND date_jour = ?
  ");
  $stmt->execute([$entrepriseId, $uid, $date]);

  // UPSERT présence
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
  // pour DECIMAL, bind en string (évite les surprises PDO)
  $up->bindValue(':h', number_format($hours, 2, '.', ''), PDO::PARAM_STR);

  $up->execute();

  echo json_encode([
    'success'     => true,
    'hours'       => (float)$hours,
    'chantier_id' => $chantierId
  ]);

} catch (Throwable $e) {
  error_log(basename(__FILE__) . ': ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
}

