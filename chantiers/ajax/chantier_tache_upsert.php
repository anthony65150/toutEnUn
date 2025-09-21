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

$tacheId    = (int)($body['tache_id'] ?? 0);
$chantierId = (int)($body['chantier_id'] ?? 0);
$nom        = trim((string)($body['nom'] ?? ''));
$shortcut   = trim((string)($body['shortcut'] ?? ''));   // <<< NOUVEAU
$unite      = trim((string)($body['unite'] ?? ''));
$quantite   = (float)($body['quantite'] ?? 0);
$tu_heures  = (float)($body['tu_heures'] ?? 0); // heures décimales

if ($chantierId <= 0 || $nom === '') {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => 'Paramètres manquants']);
  exit;
}

/* Vérifier que le chantier appartient bien à l’entreprise */
$stc = $pdo->prepare("SELECT 1 FROM chantiers WHERE id = ? AND entreprise_id = ? LIMIT 1");
$stc->execute([$chantierId, $entrepriseId]);
if (!$stc->fetchColumn()) {
  http_response_code(403);
  echo json_encode(['success' => false, 'message' => 'Accès interdit']);
  exit;
}

/* Insert si nouveau, sinon Update */
if ($tacheId <= 0) {
  $ins = $pdo->prepare("
    INSERT INTO chantier_taches
      (entreprise_id, chantier_id, nom, shortcut, unite, quantite, tu_heures, avancement_pct, created_at, updated_at)
    VALUES
      (:eid, :cid, :nom, :shortcut, :unite, :qte, :tuh, 0, NOW(), NOW())
  ");
  $ok = $ins->execute([
    ':eid'      => $entrepriseId,
    ':cid'      => $chantierId,
    ':nom'      => $nom,
    ':shortcut' => $shortcut ?: null,
    ':unite'    => $unite,
    ':qte'      => $quantite,
    ':tuh'      => $tu_heures,
  ]);
} else {
  $upd = $pdo->prepare("
    UPDATE chantier_taches
    SET nom        = :nom,
        shortcut   = :shortcut,
        unite      = :unite,
        quantite   = :qte,
        tu_heures  = :tuh,
        updated_at = NOW()
    WHERE id = :tid AND chantier_id = :cid AND entreprise_id = :eid
    LIMIT 1
  ");
  $ok = $upd->execute([
    ':nom'      => $nom,
    ':shortcut' => $shortcut ?: null,
    ':unite'    => $unite,
    ':qte'      => $quantite,
    ':tuh'      => $tu_heures,
    ':tid'      => $tacheId,
    ':cid'      => $chantierId,
    ':eid'      => $entrepriseId,
  ]);
}

echo json_encode(['success' => (bool)$ok]);
