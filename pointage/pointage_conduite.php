<?php
// /pointage/pointage_conduite.php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_SESSION['utilisateurs'])) {
  http_response_code(403);
  echo json_encode(['success' => false, 'message' => 'Accès refusé']);
  exit;
}

$user         = $_SESSION['utilisateurs'];
$entrepriseId = (int)($user['entreprise_id'] ?? 0);
$role         = $user['fonction'] ?? '';
$uid          = (int)($_POST['utilisateur_id'] ?? 0);
$date         = $_POST['date_pointage'] ?? '';
$type         = $_POST['type'] ?? ''; // 'A' ou 'R'
$chantierId   = (int)($_POST['chantier_id'] ?? 0);

if ($role !== 'administrateur' || $uid <= 0) { $uid = (int)($user['id'] ?? 0); }

if (!$entrepriseId || !$uid || !$chantierId
    || !in_array($type, ['A','R'], true)
    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
  echo json_encode(['success' => false, 'message' => 'Paramètres invalides']);
  exit;
}

// Tables
$pdo->exec("
  CREATE TABLE IF NOT EXISTS pointages_conduite (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entreprise_id INT NOT NULL,
    utilisateur_id INT NOT NULL,
    chantier_id INT NOT NULL,
    date_pointage DATE NOT NULL,
    type ENUM('A','R') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_unique (entreprise_id, utilisateur_id, date_pointage, type)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
$pdo->exec("
  CREATE TABLE IF NOT EXISTS pointages_camions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entreprise_id INT NOT NULL,
    chantier_id INT NOT NULL,
    date_jour DATE NOT NULL,
    nb_camions INT NOT NULL DEFAULT 1,
    UNIQUE KEY uq (entreprise_id, chantier_id, date_jour)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Capacité camions pour ce chantier/jour
$capStmt = $pdo->prepare("SELECT nb_camions FROM pointages_camions WHERE entreprise_id=? AND chantier_id=? AND date_jour=?");
$capStmt->execute([$entrepriseId, $chantierId, $date]);
$cap = (int)($capStmt->fetchColumn() ?: 1);

// Nombre actuel d'enregistrements de ce type (A ou R) pour ce chantier/jour
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM pointages_conduite WHERE entreprise_id=? AND chantier_id=? AND date_pointage=? AND type=?");
$countStmt->execute([$entrepriseId, $chantierId, $date, $type]);
$currentCount = (int)$countStmt->fetchColumn();

// Si capacité = 1 : on supprime tous les autres (du même type) puis on insère
if ($cap <= 1) {
  $pdo->prepare("DELETE FROM pointages_conduite WHERE entreprise_id=? AND chantier_id=? AND date_pointage=? AND type=? AND utilisateur_id<>?")
      ->execute([$entrepriseId, $chantierId, $date, $type, $uid]);
} else {
  // Capacité > 1 : si déjà plein et que l'utilisateur n'a pas encore d'entrée, on bloque
  $hasStmt = $pdo->prepare("SELECT 1 FROM pointages_conduite WHERE entreprise_id=? AND chantier_id=? AND date_pointage=? AND type=? AND utilisateur_id=?");
  $hasStmt->execute([$entrepriseId, $chantierId, $date, $type, $uid]);
  $alreadyHas = (bool)$hasStmt->fetchColumn();
  if (!$alreadyHas && $currentCount >= $cap) {
    echo json_encode(['success'=>false, 'message'=>'Capacité de camions atteinte pour ce jour.']);
    exit;
  }
}

// Upsert
$stmt = $pdo->prepare("
  INSERT INTO pointages_conduite (entreprise_id, utilisateur_id, chantier_id, date_pointage, type)
  VALUES (:e, :u, :c, :d, :t)
  ON DUPLICATE KEY UPDATE chantier_id = VALUES(chantier_id), created_at = CURRENT_TIMESTAMP
");
$stmt->execute([':e'=>$entrepriseId, ':u'=>$uid, ':c'=>$chantierId, ':d'=>$date, ':t'=>$type]);

echo json_encode(['success' => true, 'cap' => $cap]);
