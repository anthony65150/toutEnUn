<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/init.php';
header('Content-Type: application/json');

if (!isset($_SESSION['utilisateurs'])) { http_response_code(401); echo json_encode(['ok'=>false,'msg'=>'Auth requise']); exit; }

$entId = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);
$aid   = (int)($_GET['article_id'] ?? 0);

$st = $pdo->prepare("
  SELECT e.id, e.action, e.valeur_int, e.commentaire, e.fichier, e.created_at,
         u.prenom, u.nom
  FROM article_etats e
  JOIN utilisateurs u ON u.id = e.created_by
  WHERE e.article_id=:aid AND (:eid=0 OR e.entreprise_id=:eid)
  ORDER BY e.created_at DESC, e.id DESC
  LIMIT 20
");
$st->execute([':aid'=>$aid, ':eid'=>$entId]);

echo json_encode(['ok'=>true, 'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
