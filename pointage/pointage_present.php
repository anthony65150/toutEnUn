<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/init.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_SESSION['utilisateurs'])) {
  http_response_code(403);
  echo json_encode(['success' => false]);
  exit;
}

$user = $_SESSION['utilisateurs'];
$entrepriseId = (int)($user['entreprise_id'] ?? 0);
$role = $user['fonction'] ?? '';
$uid  = (int)($_POST['utilisateur_id'] ?? 0);
$date = $_POST['date'] ?? '';
$hours= (float)($_POST['hours'] ?? 0);
$chantierId = (int)($_POST['chantier_id'] ?? 0);

if ($role !== 'administrateur' || $uid <= 0) {
  $uid = (int)($user['id'] ?? 0);
}

if (
  !$entrepriseId || !$uid || $hours <= 0
  || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
) {
  echo json_encode(['success' => false, 'message' => 'Paramètres invalides']);
  exit;
}

$pdo->exec("CREATE TABLE IF NOT EXISTS pointages_jour (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entreprise_id INT NOT NULL,
  utilisateur_id INT NOT NULL,
  date_jour DATE NOT NULL,
  chantier_id INT NULL,
  heures DECIMAL(5,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq (entreprise_id, utilisateur_id, date_jour)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->prepare("DELETE FROM pointages_absences WHERE entreprise_id=? AND utilisateur_id=? AND date_jour=?")
    ->execute([$entrepriseId, $uid, $date]);

$stmt = $pdo->prepare("
  INSERT INTO pointages_jour (entreprise_id, utilisateur_id, date_jour, chantier_id, heures)
  VALUES (:e,:u,:d,:c,:h)
  ON DUPLICATE KEY UPDATE chantier_id=VALUES(chantier_id), heures=VALUES(heures)
");
$stmt->execute([
  ':e' => $entrepriseId,
  ':u' => $uid,
  ':d' => $date,
  ':c' => $chantierId ?: null,
  ':h' => $hours
]);

echo json_encode(['success' => true, 'hours' => $hours]);
