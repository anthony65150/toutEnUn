<?php
// /stock/api/alert_mark_read.php
declare(strict_types=1);
require_once __DIR__ . '/../../config/init.php';

header('Content-Type: application/json; charset=utf-8');

function out(int $code, array $p){
  http_response_code($code);
  echo json_encode($p, JSON_UNESCAPED_UNICODE);
  exit;
}

if (empty($_SESSION['utilisateurs'])) {
  out(401, ['ok'=>false,'msg'=>'Non authentifié']);
}

$role = (string)($_SESSION['utilisateurs']['fonction'] ?? '');
if (!in_array($role, ['administrateur','admin'], true)) {
  out(403, ['ok'=>false,'msg'=>'Accès refusé']);
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) out(400, ['ok'=>false,'msg'=>'id manquant']);

$entId = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);

/** helper: la colonne existe ? */
function alerts_has_col(PDO $pdo, string $col): bool {
  try {
    $q = $pdo->prepare("SHOW COLUMNS FROM stock_alerts LIKE :c");
    $q->execute([':c'=>$col]);
    return (bool)$q->fetch();
  } catch (Throwable $e) {
    return false;
  }
}
$HAS_TARGET = alerts_has_col($pdo, 'target_role');

try {
  // borne par entreprise + type/url + non archivée
  $sql = "
    UPDATE stock_alerts sa
    JOIN stock s ON s.id = sa.stock_id
       SET sa.is_read = 1
     WHERE sa.id = :id
       AND s.entreprise_id = :eid
       AND sa.archived_at IS NULL
       AND sa.type = 'incident'
       AND sa.url IN ('maintenance_due','problem')
  ";

  // ne lire que la ligne admin (et anciennes NULL si tu veux les garder visibles)
  if ($HAS_TARGET) {
    $sql .= " AND (sa.target_role = 'admin' OR sa.target_role IS NULL)";
  }

  $sql .= " LIMIT 1";

  $st = $pdo->prepare($sql);
  $st->execute([':id'=>$id, ':eid'=>$entId]);

  out(200, ['ok' => $st->rowCount() > 0]);
} catch (Throwable $e) {
  error_log('alert_mark_read: '.$e->getMessage());
  out(500, ['ok'=>false,'msg'=>'Erreur interne']);
}
