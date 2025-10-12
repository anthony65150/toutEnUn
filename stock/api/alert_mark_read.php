<?php
// /stock/api/alert_mark_read.php
declare(strict_types=1);
require_once __DIR__ . '/../../config/init.php';

header('Content-Type: application/json; charset=utf-8');

function out(int $code, array $p){ http_response_code($code); echo json_encode($p, JSON_UNESCAPED_UNICODE); exit; }

if (!isset($_SESSION['utilisateurs']) || (($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur')) {
  out(401, ['ok'=>false,'msg'=>'Non autorisé']);
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) out(400, ['ok'=>false,'msg'=>'id manquant']);

try {
  $st = $pdo->prepare("UPDATE stock_alerts SET is_read=1 WHERE id=:id");
  $st->execute([':id'=>$id]);
  out(200, ['ok'=>true]);
} catch (Throwable $e) {
  out(500, ['ok'=>false,'msg'=>'Erreur interne']);
}
