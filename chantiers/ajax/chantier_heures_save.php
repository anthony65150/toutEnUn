<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/init.php';
header('Content-Type: application/json');

if (!isset($_SESSION['utilisateurs'])) {
  http_response_code(401);
  echo json_encode(['success' => false, 'message' => 'Non connecté']);
  exit;
}

$user         = $_SESSION['utilisateurs'];
$entrepriseId = (int)($user['entreprise_id'] ?? 0);
if (!$entrepriseId) {
  http_response_code(403);
  echo json_encode(['success' => false, 'message' => 'Entreprise inconnue']);
  exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];

if (empty($body['csrf_token']) || $body['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => 'CSRF invalide']);
  exit;
}

$tacheId     = (int)($body['tache_id'] ?? 0);
$chantierId  = (int)($body['chantier_id'] ?? 0);
$quantite   = (float)str_replace(',', '.', (string)($body['quantite'] ?? 0));
$tu_heures  = (float)str_replace(',', '.', (string)($body['tu_heures'] ?? 0));
$avancement = (float)str_replace(',', '.', (string)($body['avancement_pct'] ?? 0));

$avancement = max(0.0, min(100.0, $avancement));
$pct01      = $avancement / 100.0;

// (option) récup des heures pointées réelles pour cette tâche (à adapter)
$hpH = 0.0;
// Exemple si tu stockes les pointages par tache_id :
/*
$stHP = $pdo->prepare("SELECT COALESCE(SUM(heures),0) FROM pointages WHERE tache_id=? AND chantier_id=? AND entreprise_id=?");
$stHP->execute([$tacheId, $chantierId, $entrepriseId]);
$hpH = (float)$stHP->fetchColumn();
*/

// À défaut, laisse 0.0 ou envoie-le depuis le front si tu préfères

$temps_total = max(0.0, $quantite * $tu_heures);
$temps_stade = $temps_total * $pct01;

$denom = ($quantite > 0 && $pct01 > 0) ? ($quantite * $pct01) : 0.0;
$nouveau_tu = ($denom > 0) ? ($hpH / $denom) : 0.0;

// ... UPDATE existant (quantite, tu_heures, avancement_pct) ...

echo json_encode([
  'success' => (bool)$ok,
  'computed' => [
    'heures_pointees' => round($hpH, 2),
    'temps_total'     => round($temps_total, 2),
    'temps_stade'     => round($temps_stade, 2),
    'nouveau_tu'      => round($nouveau_tu, 2),
  ]
]);

echo json_encode(['success' => (bool)$ok]);
