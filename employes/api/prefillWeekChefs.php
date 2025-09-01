<?php
require_once __DIR__ . '/../../config/init.php';
header('Content-Type: application/json');

try {
  if (!isset($_SESSION['utilisateurs']) || (($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur')) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'message'=>'Non autorisé']); exit;
  }

  $entrepriseId = (int)($_SESSION['entreprise_id'] ?? 0);
  $startRaw = $_POST['start'] ?? null;
  if (!$startRaw) throw new Exception("Paramètre 'start' manquant.");
  $monday = (new DateTime($startRaw))->format('Y-m-d');

  /** @var PDO $pdo */

  // Combien de chantiers ont un responsable ?
  $sqlCount = "SELECT COUNT(*) FROM chantiers WHERE responsable_id IS NOT NULL"
            . ($entrepriseId ? " AND entreprise_id = :eid" : "");
  $stc = $pdo->prepare($sqlCount);
  $bind = [];
  if ($entrepriseId) $bind[':eid'] = $entrepriseId;
  $stc->execute($bind);
  $nbResp = (int)$stc->fetchColumn();
  if ($nbResp === 0) {
    echo json_encode(['ok'=>true, 'inserted'=>0, 'skipped'=>0, 'hint'=>"Aucun responsable défini sur les chantiers."]); exit;
  }

  // On choisit un chantier préféré par responsable (MIN(id) si plusieurs)
  $sql = "
    INSERT INTO planning_affectations (utilisateur_id, chantier_id, date_jour, entreprise_id)
    SELECT cc.responsable_id, cc.pref_ch_id, d.jour, :eid
    FROM (
      SELECT responsable_id, MIN(id) AS pref_ch_id
      FROM chantiers
      WHERE responsable_id IS NOT NULL " . ($entrepriseId ? " AND entreprise_id = :eid " : "") . "
      GROUP BY responsable_id
    ) cc
    JOIN (
      SELECT DATE(:monday) AS jour
      UNION ALL SELECT DATE_ADD(DATE(:monday), INTERVAL 1 DAY)
      UNION ALL SELECT DATE_ADD(DATE(:monday), INTERVAL 2 DAY)
      UNION ALL SELECT DATE_ADD(DATE(:monday), INTERVAL 3 DAY)
      UNION ALL SELECT DATE_ADD(DATE(:monday), INTERVAL 4 DAY)
    ) d
    WHERE NOT EXISTS (
      SELECT 1 FROM planning_affectations pa
      WHERE pa.utilisateur_id = cc.responsable_id
        AND pa.date_jour      = d.jour
        AND pa.entreprise_id  = :eid
    )
  ";

  $st = $pdo->prepare($sql);
  $st->execute([':eid'=>$entrepriseId, ':monday'=>$monday]);

  $inserted = $st->rowCount();
  $target   = $nbResp * 5; // 5 jours ouvrés

  echo json_encode([
    'ok'       => true,
    'inserted' => $inserted,
    'skipped'  => max(0, $target - $inserted),
    'hint'     => ($inserted === 0 ? "Aucune insertion (déjà rempli ou responsables non définis pour cette entreprise)." : null)
  ]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
}
