<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/init.php';
header('Content-Type: application/json; charset=utf-8');

try {
  if (!isset($_SESSION['utilisateurs'])) { http_response_code(403); echo json_encode(['ok'=>false,'message'=>'Non autorisé']); exit; }
  $entrepriseId = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);
  if ($entrepriseId<=0) throw new Exception('Entreprise invalide');

  // 1 valeur par chantier
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS pointage_camions_cfg (
      entreprise_id INT NOT NULL,
      chantier_id   INT NOT NULL,
      nb_camions    INT NOT NULL DEFAULT 1,
      PRIMARY KEY (entreprise_id, chantier_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ");

  if ($_SERVER['REQUEST_METHOD']==='GET') {
    $cid=(int)($_GET['chantier_id']??0); if($cid<=0) throw new Exception('chantier_id manquant');
    $st=$pdo->prepare("SELECT nb_camions FROM pointage_camions_cfg WHERE entreprise_id=:e AND chantier_id=:c LIMIT 1");
    $st->execute([':e'=>$entrepriseId,':c'=>$cid]);
    echo json_encode(['ok'=>true,'nb'=>(int)($st->fetchColumn()?:1)]); exit;
  }

  if ($_SERVER['REQUEST_METHOD']==='POST') {
    $cid=(int)($_POST['chantier_id']??0); $nb=max(0,(int)($_POST['nb']??1));
    if($cid<=0) throw new Exception('chantier_id manquant');
    $st=$pdo->prepare("INSERT INTO pointage_camions_cfg (entreprise_id,chantier_id,nb_camions)
                       VALUES (:e,:c,:n) ON DUPLICATE KEY UPDATE nb_camions=VALUES(nb_camions)");
    $st->execute([':e'=>$entrepriseId,':c'=>$cid,':n'=>$nb]);
    echo json_encode(['ok'=>true,'nb'=>$nb]); exit;
  }

  throw new Exception('Méthode non supportée');
} catch(Throwable $e){ http_response_code(400); echo json_encode(['ok'=>false,'message'=>$e->getMessage()]); }
