<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/init.php';
header('Content-Type: application/json');

if (!isset($_SESSION['utilisateurs'])) { http_response_code(401); echo json_encode(['ok'=>false,'msg'=>'Auth requise']); exit; }

$uid   = (int)$_SESSION['utilisateurs']['id'];
$entId = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);

$articleId   = (int)($_POST['article_id'] ?? 0);
$action      = (string)($_POST['action'] ?? 'compteur_maj'); // compteur_maj | declarer_ok | declarer_panne
$valeurInt   = isset($_POST['valeur_int']) ? (int)$_POST['valeur_int'] : null;
$commentaire = trim((string)($_POST['commentaire'] ?? ''));

// Récup article (et profil/entreprise)
$st = $pdo->prepare("SELECT id, entreprise_id, maintenance_mode FROM stock WHERE id=:id AND (:eid=0 OR entreprise_id=:eid) LIMIT 1");
$st->execute([':id'=>$articleId, ':eid'=>$entId]);
$art = $st->fetch(PDO::FETCH_ASSOC);
if (!$art) { echo json_encode(['ok'=>false,'msg'=>'Article inconnu']); exit; }

$profil = ($art['maintenance_mode'] === 'hour_meter') ? 'compteur_heures'
        : (($art['maintenance_mode'] === 'electrical') ? 'autre' : 'aucun');

// Upload optionnel
$fichierPath = null;
if (!empty($_FILES['fichier']['name'])) {
  $baseDir = __DIR__ . '/../uploads/etat/';
  if (!is_dir($baseDir)) @mkdir($baseDir, 0777, true);
  $safe = date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9._-]/','_', (string)$_FILES['fichier']['name']);
  $dest = $baseDir . $safe;
  if (move_uploaded_file($_FILES['fichier']['tmp_name'], $dest)) {
    $fichierPath = 'uploads/etat/' . $safe;
  }
}

// Enregistrer le journal
$ins = $pdo->prepare("
  INSERT INTO article_etats (entreprise_id, article_id, profil_qr, action, valeur_int, commentaire, fichier, created_by)
  VALUES (:eid, :aid, :profil, :action, :val, :com, :file, :uid)
");
$ins->execute([
  ':eid'=>$entId, ':aid'=>$articleId, ':profil'=>$profil, ':action'=>$action,
  ':val'=>$valeurInt, ':com'=>($commentaire!==''?$commentaire:null),
  ':file'=>$fichierPath, ':uid'=>$uid
]);

// Effets de bord utiles dans stock (facultatif mais pratique)
if ($art['maintenance_mode'] === 'hour_meter' && $action === 'compteur_maj' && $valeurInt !== null) {
  $pdo->prepare("UPDATE stock SET compteur_heures=:v WHERE id=:id")->execute([':v'=>$valeurInt, ':id'=>$articleId]);
}
if ($art['maintenance_mode'] === 'electrical') {
  if ($action === 'declarer_ok') {
    $pdo->prepare("UPDATE stock SET panne=0 WHERE id=:id")->execute([':id'=>$articleId]);
  } elseif ($action === 'declarer_panne') {
    $pdo->prepare("UPDATE stock SET panne=1 WHERE id=:id")->execute([':id'=>$articleId]);
  }
}

echo json_encode(['ok'=>true]);
