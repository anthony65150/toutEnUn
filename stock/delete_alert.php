<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/init.php';
header('Content-Type: application/json');

// Vérif session
if (!isset($_SESSION['utilisateurs'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Non autorisé']);
    exit;
}

$role = $_SESSION['utilisateurs']['fonction'] ?? '';
if ($role !== 'administrateur') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Accès refusé']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'ID invalide']);
    exit;
}

// ✅ Correction : bonne table
$stmt = $pdo->prepare("DELETE FROM stock_alerts WHERE id = :id LIMIT 1");
$ok = $stmt->execute([':id' => $id]);

echo json_encode(['ok' => $ok]);
