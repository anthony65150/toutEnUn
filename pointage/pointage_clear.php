<?php
// /pointage/pointage_clear.php
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
$date         = $_POST['date'] ?? '';
$action       = $_POST['action'] ?? '';      // 'presence' | 'absence' | 'conduite'
$which        = $_POST['which'] ?? null;     // 'A' | 'R' (pour conduite)

if ($role !== 'administrateur' || $uid <= 0) {
  $uid = (int)($user['id'] ?? 0);
}

if (!$entrepriseId || !$uid || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
  echo json_encode(['success' => false, 'message' => 'Paramètres invalides']);
  exit;
}

switch ($action) {
  case 'presence':
    $stmt = $pdo->prepare("DELETE FROM pointages_jour WHERE entreprise_id=? AND utilisateur_id=? AND date_jour=?");
    $stmt->execute([$entrepriseId, $uid, $date]);
    break;

  case 'absence':
    $stmt = $pdo->prepare("DELETE FROM pointages_absences WHERE entreprise_id=? AND utilisateur_id=? AND date_jour=?");
    $stmt->execute([$entrepriseId, $uid, $date]);
    break;

  case 'conduite':
    if (!in_array($which, ['A','R'], true)) {
      echo json_encode(['success' => false, 'message' => 'Type conduite invalide']);
      exit;
    }
    $stmt = $pdo->prepare("DELETE FROM pointages_conduite WHERE entreprise_id=? AND utilisateur_id=? AND date_pointage=? AND type=?");
    $stmt->execute([$entrepriseId, $uid, $date, $which]);
    break;

  default:
    echo json_encode(['success' => false, 'message' => 'Action inconnue']);
    exit;
}

echo json_encode(['success' => true]);
