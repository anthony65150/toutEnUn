<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/init.php';
header('Content-Type: application/json');

if (!isset($_SESSION['utilisateurs'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Non connecté']);
    exit;
}
$user = $_SESSION['utilisateurs'];
$entrepriseId = (int)($user['entreprise_id'] ?? 0);
$role = (string)($user['fonction'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Méthode']);
    exit;
}

$csrf = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'CSRF']);
    exit;
}

$tacheId    = (int)($_POST['tache_id'] ?? 0);
$chantierId = (int)($_POST['chantier_id'] ?? 0);
$pct        = (float)($_POST['avancement_pct'] ?? 0.0);
$pct = max(0, min(100, $pct));

// Vérif tâche appartient à l’entreprise
$sql = "SELECT ct.id, ct.chantier_id, ct.quantite, ct.tu_heures, c.entreprise_id
        FROM chantier_taches ct
        JOIN chantiers c ON c.id = ct.chantier_id
        WHERE ct.id = :tid AND ct.chantier_id = :cid AND c.entreprise_id = :eid";
$st = $pdo->prepare($sql);
$st->execute([':tid' => $tacheId, ':cid' => $chantierId, ':eid' => $entrepriseId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'msg' => 'Introuvable']);
    exit;
}

// Vérif rôle
$allowed = false;
if ($role === 'administrateur') {
    $allowed = true;
} elseif ($role === 'chef') {
    $chk = $pdo->prepare("SELECT 1 FROM utilisateur_chantiers WHERE utilisateur_id=? AND chantier_id=? AND entreprise_id=? LIMIT 1");
    $chk->execute([(int)$user['id'], $chantierId, $entrepriseId]);
    $allowed = (bool)$chk->fetchColumn();
}
if (!$allowed) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Accès refusé']);
    exit;
}

// MAJ (date du jour, sans l'heure)
$upd = $pdo->prepare("UPDATE chantier_taches
                      SET avancement_pct = :p, updated_at = CURDATE()
                      WHERE id = :id");
$upd->execute([':p'=>$pct, ':id'=>$tacheId]);

// -------- Recalculs --------
$qte  = (float)$row['quantite'];
$tuH  = (float)$row['tu_heures'];
$ttH  = $qte * $tuH;  // temps total théorique

// heures pointées déjà effectuées
$hpq = $pdo->prepare("SELECT COALESCE(SUM(heures),0) 
                      FROM pointages_jour 
                      WHERE entreprise_id=? AND chantier_id=? AND tache_id=?");
$hpq->execute([$entrepriseId, $chantierId, $tacheId]);
$heuresPointees = (float)$hpq->fetchColumn();

$tsH   = $ttH * ($pct / 100.0);                       // temps au stade
$ecH   = $tsH - $heuresPointees;                      // écart
$newTU = ($qte > 0 && $pct > 0) ? ($heuresPointees / ($qte * ($pct/100))) : 0.0;

$fmt = fn($x)=>number_format((float)$x, 2, '.', '');
// date formatée JJ-MM-AAAA
$updatedOn = date('d-m-Y');

echo json_encode([
  'ok'         => true,
  'pct'        => $pct,
  'ts'         => $fmt($tsH),
  'hp'         => $fmt($heuresPointees),
  'ec'         => $fmt($ecH),
  'new_tu'     => $fmt($newTU),
  'updated_on' => $updatedOn,
]);
exit;
