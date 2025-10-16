<?php

declare(strict_types=1);
require_once __DIR__ . '/../../config/init.php';

header('Content-Type: application/json; charset=utf-8');

$ENT_ID = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);

$sql = "SELECT COUNT(*) FROM stock_alerts WHERE entreprise_id = :eid AND is_archived = 0";
$stmt = $pdo->prepare($sql);
$stmt->execute([':eid' => $ENT_ID]);
$count = (int)$stmt->fetchColumn();

echo json_encode(['count' => $count]);
