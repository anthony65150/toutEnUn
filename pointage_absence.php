<?php
declare(strict_types=1);
require_once __DIR__ . '/config/init.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_SESSION['utilisateurs'])) {
  http_response_code(403); echo json_encode(['success'=>false]); exit;
}

$user = $_SESSION['utilisateurs'];
$entrepriseId = (int)($user['entreprise_id'] ?? 0);
$role = $user['fonction'] ?? '';
$uid  = (int)($_POST['utilisateur_id'] ?? 0);
$date = $_POST['date'] ?? '';
$reason = $_POST['reason'] ?? '';

if ($role !== 'administrateur' || $uid <= 0) $uid = (int)($user['id'] ?? 0);
if (!$entrepriseId || !$uid || !in_array($reason, ['conges','maladie','injustifie'], true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) {
  echo json_encode(['success'=>false,'message'=>'Paramètres invalides']); exit;
}

$pdo->exec("CREATE TABLE IF NOT EXISTS pointages_absences (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entreprise_id INT NOT NULL,
  utilisateur_id INT NOT NULL,
  date_jour DATE NOT NULL,
  motif ENUM('conges','maladie','injustifie') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq (entreprise_id, utilisateur_id, date_jour)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->prepare("DELETE FROM pointages_jour WHERE entreprise_id=? AND utilisateur_id=? AND date_jour=?")
    ->execute([$entrepriseId, $uid, $date]);

$pdo->prepare("DELETE FROM pointages_conduite WHERE entreprise_id=? AND utilisateur_id=? AND date_pointage=?")
    ->execute([$entrepriseId, $uid, $date]);

$stmt = $pdo->prepare("
  INSERT INTO pointages_absences (entreprise_id, utilisateur_id, date_jour, motif)
  VALUES (:e,:u,:d,:m)
  ON DUPLICATE KEY UPDATE motif=VALUES(motif), created_at=VALUES(created_at)
");
$stmt->execute([':e'=>$entrepriseId, ':u'=>$uid, ':d'=>$date, ':m'=>$reason]);

echo json_encode(['success'=>true, 'reason'=>$reason]);
