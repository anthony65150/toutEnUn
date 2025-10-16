<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/init.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['utilisateurs'])) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'msg'=>'Auth requise']); exit;
}
$role = (string)($_SESSION['utilisateurs']['fonction'] ?? '');
if (!in_array($role, ['administrateur','admin'], true)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'msg'=>'Accès refusé']); exit;
}

$alertId = (int)($_POST['id'] ?? 0);
if ($alertId <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'msg'=>'ID invalide']); exit;
}

$entId = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);
$uid   = (int)($_SESSION['utilisateurs']['id'] ?? 0);

// helper: colonne présente ?
function alerts_has_col(PDO $pdo, string $col): bool {
    try {
        $q = $pdo->prepare("SHOW COLUMNS FROM stock_alerts LIKE :c");
        $q->execute([':c'=>$col]);
        return (bool)$q->fetch();
    } catch (Throwable $e) { return false; }
}
$HAS_TARGET = alerts_has_col($pdo, 'target_role');

// base query
$sql = "
    UPDATE stock_alerts sa
    JOIN stock s ON s.id = sa.stock_id
       SET sa.archived_at = NOW(), sa.archived_by = :uid
     WHERE sa.id = :id
       AND s.entreprise_id = :eid
       AND sa.archived_at IS NULL
       AND sa.type = 'incident'
       AND sa.url IN ('problem','maintenance_due')
";

// ne toucher que l’alerte destinée à l’admin (ou anciennes NULL)
if ($HAS_TARGET) {
    $sql .= " AND (sa.target_role = 'admin' OR sa.target_role IS NULL)";
}

$sql .= " LIMIT 1";

$st = $pdo->prepare($sql);
$st->execute([
    ':uid' => $uid,
    ':id'  => $alertId,
    ':eid' => $entId,
]);

echo json_encode([
    'ok'  => $st->rowCount() > 0,
    'msg' => $st->rowCount() ? 'Archivée' : 'Introuvable'
]);
