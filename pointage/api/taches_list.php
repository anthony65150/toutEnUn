<?php

declare(strict_types=1);

ini_set('display_errors','0');           // pas d’erreurs HTML
header('Content-Type: application/json; charset=utf-8');
while (ob_get_level()) ob_end_clean();   // vide tous les buffers
ob_start();                              // on repart propre

require_once dirname(__DIR__, 2) . '/config/init.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Méthode invalide');
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $entrepriseId = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);
    $chantierId   = (int)($input['chantier_id'] ?? 0);

    if (!$entrepriseId || !$chantierId) {
        throw new Exception('Paramètres incomplets');
    }

    // Détecter la colonne libellé dans chantier_taches
    $cols = $pdo->query("SHOW COLUMNS FROM chantier_taches")->fetchAll(PDO::FETCH_COLUMN, 0);
    $hasShortcut = in_array('shortcut', $cols, true);
    $candidates  = ['libelle', 'nom', 'titre', 'intitule', 'label'];
    $labelCol    = null;
    foreach ($candidates as $c) if (in_array($c, $cols, true)) {
        $labelCol = $c;
        break;
    }
    if (!$labelCol && !$hasShortcut) $labelCol = 'id';

    $libExpr = "COALESCE(
  NULLIF(`shortcut`, ''),
  " . ($labelCol ? "NULLIF(`$labelCol`, '')," : "") . "
  CAST(`id` AS CHAR)
)";

    $sql = "
  SELECT id, $libExpr AS libelle
  FROM chantier_taches
  WHERE entreprise_id = :eid AND chantier_id = :cid
  ORDER BY libelle
";
    $st = $pdo->prepare($sql);
    $st->execute([':eid' => $entrepriseId, ':cid' => $chantierId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'taches' => $rows]);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
