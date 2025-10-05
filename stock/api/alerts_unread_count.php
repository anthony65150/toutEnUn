<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/init.php';
header('Content-Type: application/json');

if (!isset($_SESSION['utilisateurs'])) { echo json_encode(['ok'=>false,'count'=>0]); exit; }

$entId = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);
try{
  $sql = "SELECT COUNT(*) FROM stock_alerts a
          JOIN stock s ON s.id=a.stock_id
          WHERE a.is_read=0";
  $params = [];
  if ($entId>0){ $sql .= " AND s.entreprise_id=:eid"; $params[':eid']=$entId; }

  $st = $pdo->prepare($sql); $st->execute($params);
  echo json_encode(['ok'=>true,'count'=>(int)$st->fetchColumn()]);
}catch(Throwable $e){
  echo json_encode(['ok'=>false,'count'=>0]);
}
