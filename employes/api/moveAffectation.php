<?php
require_once __DIR__ . '/../../config/init.php';
header('Content-Type: application/json');

if (!isset($_SESSION['utilisateurs']) || (($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur')) {
  http_response_code(403); echo json_encode(['ok'=>false,'message'=>'Non autorisé']); exit;
}

$entrepriseId = (int)($_SESSION['entreprise_id'] ?? 0);
$uid  = (int)($_POST['emp_id'] ?? 0);
$cidR = $_POST['chantier_id'] ?? null;
$cid  = ($cidR === '0' || $cidR === 0) ? null : (int)$cidR;
$jour = $_POST['date'] ?? (new DateTime('today'))->format('Y-m-d');

if ($uid <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'message'=>'Employé manquant']); exit; }

$pdo->beginTransaction();
try {
  // contrôle employé
  $st = $pdo->prepare("SELECT COUNT(*) FROM utilisateurs WHERE id=:u".($entrepriseId?" AND entreprise_id=:e":""));
  $st->execute(array_filter([':u'=>$uid, ':e'=>$entrepriseId]));
  if(!$st->fetchColumn()) throw new Exception("Employé introuvable");

  // contrôle chantier si défini
  if ($cid !== null) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM chantiers WHERE id=:c".($entrepriseId?" AND entreprise_id=:e":""));
    $st->execute(array_filter([':c'=>$cid, ':e'=>$entrepriseId]));
    if(!$st->fetchColumn()) throw new Exception("Chantier introuvable");
  }

  // UPSERT : 1 ligne max / employé / jour
  $sql = "INSERT INTO planning_affectations (utilisateur_id, chantier_id, date_jour, entreprise_id)
          VALUES (:u, :c, :d, :e)
          ON DUPLICATE KEY UPDATE chantier_id = VALUES(chantier_id)";
  $st = $pdo->prepare($sql);
  $st->bindValue(':u',$uid,PDO::PARAM_INT);
  $st->bindValue(':d',$jour,PDO::PARAM_STR);
  $st->bindValue(':e',$entrepriseId,PDO::PARAM_INT);
  if ($cid===null) $st->bindValue(':c', null, PDO::PARAM_NULL); else $st->bindValue(':c', $cid, PDO::PARAM_INT);
  $st->execute();

  $pdo->commit();
  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
}
