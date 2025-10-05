<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/init.php';
header('Content-Type: application/json');

if (!isset($_SESSION['utilisateurs'])) { http_response_code(401); echo json_encode(['ok'=>false,'msg'=>'Auth requise']); exit; }
if (($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur') { http_response_code(403); echo json_encode(['ok'=>false,'msg'=>'Accès refusé']); exit; }

$alertId = (int)($_POST['id'] ?? 0);
if ($alertId <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'msg'=>'ID invalide']); exit; }

$entId = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);
$uid   = (int)($_SESSION['utilisateurs']['id'] ?? 0);

// Sécurise par l'entreprise
$sql = "UPDATE stock_alerts sa
        JOIN stock s ON s.id = sa.stock_id
        SET sa.archived_at = NOW(), sa.archived_by = :uid
        WHERE sa.id = :id AND s.entreprise_id = :eid AND sa.archived_at IS NULL
        LIMIT 1";
$st = $pdo->prepare($sql);
$st->execute([':uid'=>$uid, ':id'=>$alertId, ':eid'=>$entId]);

echo json_encode(['ok' => $st->rowCount() > 0, 'msg' => $st->rowCount() ? 'Archivée' : 'Introuvable']);
