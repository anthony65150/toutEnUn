<?php
// /pointage/pointage_camion.php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['utilisateurs'])) {
  http_response_code(403);
  echo json_encode(['success'=>false, 'message'=>'Accès refusé']);
  exit;
}

$user         = $_SESSION['utilisateurs'];
$entrepriseId = (int)($user['entreprise_id'] ?? 0);

$action     = $_POST['action'] ?? 'get';          // get | set | inc | dec
$chantierId = (int)($_POST['chantier_id'] ?? 0);
$dateJour   = $_POST['date_jour'] ?? '';

if (!$entrepriseId || !$chantierId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateJour)) {
  echo json_encode(['success'=>false, 'message'=>'Paramètres invalides']);
  exit;
}

// Table capacités
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

function get_nb(PDO $pdo, int $e, int $c, string $d): int {
  $s = $pdo->prepare("SELECT nb_camions FROM pointages_camions WHERE entreprise_id=? AND chantier_id=? AND date_jour=?");
  $s->execute([$e,$c,$d]);
  $n = $s->fetchColumn();
  return $n ? (int)$n : 1;
}

$nb = get_nb($pdo, $entrepriseId, $chantierId, $dateJour);

if ($action === 'get') {
  echo json_encode(['success'=>true, 'nb'=>$nb]); exit;
}

if ($action === 'inc') $nb++;
if ($action === 'dec') $nb--;

if ($action === 'set') {
  $nb = max(1, (int)($_POST['nb'] ?? 1));
} else {
  $nb = max(1, $nb);
}

$stmt = $pdo->prepare("
  INSERT INTO pointages_camions (entreprise_id, chantier_id, date_jour, nb_camions)
  VALUES (:e,:c,:d,:n)
  ON DUPLICATE KEY UPDATE nb_camions = VALUES(nb_camions)
");
$stmt->execute([':e'=>$entrepriseId, ':c'=>$chantierId, ':d'=>$dateJour, ':n'=>$nb]);

echo json_encode(['success'=>true, 'nb'=>$nb]);
