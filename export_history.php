<?php
require_once "./config/init.php";

if (!isset($_SESSION['utilisateurs']) || ($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur') {
  http_response_code(403);
  exit("Accès refusé.");
}

$stockId = (int)($_GET['stock_id'] ?? 0);
$format  = strtolower($_GET['format'] ?? 'xlsx'); // xlsx | pdf

if ($stockId <= 0) {
  http_response_code(400);
  exit("stock_id manquant.");
}

/* ========= Requête historique (reprend la logique affichée sur ta page) ========= */
$sql = "
  SELECT
      sm.*,

      /* VALIDATEUR */
      us.prenom    AS user_prenom,
      us.fonction  AS user_fonction,
      d_us.nom     AS validateur_depot_nom,
      c_us.nom     AS validateur_chantier_nom,

      /* DEMANDEUR */
      dem.prenom   AS dem_prenom,
      dem.fonction AS dem_fonction,

      /* Lieux */
      cs.nom       AS source_chantier_nom,
      cd.nom       AS dest_chantier_nom,
      ds.nom       AS source_depot_nom,
      dd.nom       AS dest_depot_nom,

      /* Resp standard (source/dest) */
      us_src.prenom   AS src_respo_prenom,
      uc_src_u.prenom AS src_chef_prenom,
      us_dst.prenom   AS dst_respo_prenom,
      uc_dst_u.prenom AS dst_chef_prenom,

      /* Clés d’affichage */
      CASE
        WHEN dem.fonction = 'administrateur' THEN dem.prenom
        WHEN sm.source_type = 'depot'        THEN us_src.prenom
        WHEN sm.source_type = 'chantier'     THEN uc_src_u.prenom
        ELSE NULL
      END AS src_actor_prenom,

      CASE
        WHEN sm.dest_type = 'depot'      THEN us_dst.prenom
        WHEN sm.dest_type = 'chantier'   THEN uc_dst_u.prenom
        ELSE NULL
      END AS dst_actor_prenom

  FROM stock_mouvements sm

  LEFT JOIN utilisateurs us   ON us.id  = sm.utilisateur_id
  LEFT JOIN depots d_us       ON (us.fonction = 'depot' AND d_us.responsable_id = us.id)
  LEFT JOIN (
      SELECT uc.utilisateur_id, MIN(uc.chantier_id) AS chantier_id
      FROM utilisateur_chantiers uc GROUP BY uc.utilisateur_id
  ) uc_us ON (us.fonction = 'chef' AND uc_us.utilisateur_id = us.id)
  LEFT JOIN chantiers c_us ON (c_us.id = uc_us.chantier_id)

  LEFT JOIN utilisateurs dem ON dem.id = sm.demandeur_id

  LEFT JOIN chantiers cs ON (sm.source_type = 'chantier' AND cs.id = sm.source_id)
  LEFT JOIN chantiers cd ON (sm.dest_type   = 'chantier' AND cd.id = sm.dest_id)
  LEFT JOIN depots    ds ON (sm.source_type = 'depot'    AND ds.id = sm.source_id)
  LEFT JOIN depots    dd ON (sm.dest_type   = 'depot'    AND dd.id = sm.dest_id)

  LEFT JOIN utilisateurs us_src ON (sm.source_type='depot'    AND us_src.id = ds.responsable_id)
  LEFT JOIN (
      SELECT uc.chantier_id, MIN(uc.utilisateur_id) AS chef_id
      FROM utilisateur_chantiers uc GROUP BY uc.chantier_id
  ) uc_src  ON (sm.source_type='chantier' AND uc_src.chantier_id = sm.source_id)
  LEFT JOIN utilisateurs uc_src_u ON (uc_src_u.id = uc_src.chef_id)

  LEFT JOIN utilisateurs us_dst ON (sm.dest_type='depot'     AND us_dst.id = dd.responsable_id)
  LEFT JOIN (
      SELECT uc.chantier_id, MIN(uc.utilisateur_id) AS chef_id
      FROM utilisateur_chantiers uc GROUP BY uc.chantier_id
  ) uc_dst  ON (sm.dest_type='chantier' AND uc_dst.chantier_id = sm.dest_id)
  LEFT JOIN utilisateurs uc_dst_u ON (uc_dst_u.id = uc_dst.chef_id)

  WHERE sm.stock_id = :sid
  ORDER BY sm.created_at DESC, sm.id DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':sid' => $stockId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ========= Helpers pour fabriquer les 3 colonnes ========= */
function buildLieu(string $type = null, array $r = [], string $prefix = 'source'): string {
  $actor = $r[$prefix === 'source' ? 'src_actor_prenom' : 'dst_actor_prenom'] ?? null;
  if ($type === 'depot') {
    $dep = $r[$prefix === 'source' ? 'source_depot_nom' : 'dest_depot_nom'] ?? '';
    return trim(($actor ? "$actor (dépôt $dep)" : ($dep ? "Dépôt ($dep)" : "Dépôt")));
  }
  if ($type === 'chantier') {
    $ch = $r[$prefix === 'source' ? 'source_chantier_nom' : 'dest_chantier_nom'] ?? '';
    return trim(($actor ? "$actor (chantier $ch)" : ($ch ? "Chantier : $ch" : "Chantier")));
  }
  return '-';
}
function buildPar(array $r): string {
  $prenom = trim($r['user_prenom'] ?? '');
  if ($prenom === '') return '-';
  $role = $r['user_fonction'] ?? '';
  if ($role === 'administrateur') return $prenom; // prénom seul
  if ($role === 'depot' && !empty($r['validateur_depot_nom'])) return $prenom . ' (dépôt ' . $r['validateur_depot_nom'] . ')';
  if ($role === 'chef') {
    $ch = $r['validateur_chantier_nom'] ?? $r['source_chantier_nom'] ?? $r['dest_chantier_nom'] ?? null;
    return $ch ? ($prenom . ' (chantier ' . $ch . ')') : $prenom;
  }
  return $prenom;
}

/* ========= Dataset formaté ========= */
$data = [];
foreach ($rows as $r) {
  $data[] = [
    'Date'   => date('d/m/Y H:i', strtotime($r['created_at'])),
    'De'     => buildLieu($r['source_type'] ?? null, $r, 'source'),
    'Vers'   => buildLieu($r['dest_type']   ?? null, $r, 'dest'),
    'Qté'    => (int)$r['quantite'],
    'Statut' => strtoupper($r['statut']),
    'Par'    => buildPar($r),
  ];
}

/* ========= EXPORT ========= */
if ($format === 'xlsx') {
  // Excel via PhpSpreadsheet
  if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
    // Autoload composer si pas encore chargé
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload)) require_once $autoload;
  }
  if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
    // Fallback CSV si lib absente
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="historique_stock_'.$stockId.'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array_keys($data[0] ?? ['Date','De','Vers','Qté','Statut','Par']), ';');
    foreach ($data as $row) fputcsv($out, $row, ';');
    fclose($out);
    exit;
  }
  // Génération XLSX
  $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
  $sheet = $spreadsheet->getActiveSheet();
  $sheet->setTitle('Historique');
  // Entêtes
  $col = 1;
  foreach (array_keys($data[0] ?? ['Date','De','Vers','Qté','Statut','Par']) as $h) {
    $sheet->setCellValueByColumnAndRow($col, 1, $h);
    $col++;
  }
  // Lignes
  $rowIndex = 2;
  foreach ($data as $row) {
    $col = 1;
    foreach ($row as $cell) {
      $sheet->setCellValueByColumnAndRow($col, $rowIndex, $cell);
      $col++;
    }
    $rowIndex++;
  }
  // Style simple
  $sheet->getStyle('A1:F1')->getFont()->setBold(true);
  foreach (range('A','F') as $c) $sheet->getColumnDimension($c)->setAutoSize(true);

  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Disposition: attachment; filename="historique_stock_'.$stockId.'.xlsx"');
  $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
  $writer->save('php://output');
  exit;
}

if ($format === 'pdf') {
  // PDF via Dompdf
  if (!class_exists(\Dompdf\Dompdf::class)) {
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload)) require_once $autoload;
  }
  if (!class_exists(\Dompdf\Dompdf::class)) {
    // Fallback HTML si lib absente
    header('Content-Type: text/html; charset=utf-8');
    echo "<h3>Historique stock #{$stockId}</h3>";
    echo "<table border='1' cellspacing='0' cellpadding='6'><tr>";
    foreach (array_keys($data[0] ?? []) as $h) echo "<th>{$h}</th>";
    echo "</tr>";
    foreach ($data as $row) {
      echo "<tr>";
      foreach ($row as $cell) echo "<td>".htmlspecialchars((string)$cell)."</td>";
      echo "</tr>";
    }
    echo "</table>";
    exit;
  }
  $html = '<html><head><meta charset="utf-8">
  <style>
    body{font-family: DejaVu Sans, sans-serif; font-size:12px}
    h3{margin:0 0 8px 0}
    table{width:100%; border-collapse:collapse}
    th,td{border:1px solid #ddd; padding:6px}
    th{background:#f5f5f5}
    .right{text-align:right}
  </style></head><body>';
  $html .= '<h3>Historique du stock #'.$stockId.'</h3>';
  $html .= '<table><thead><tr>';
  foreach (array_keys($data[0] ?? ['Date','De','Vers','Qté','Statut','Par']) as $h) {
    $html .= '<th>'.htmlspecialchars($h).'</th>';
  }
  $html .= '</tr></thead><tbody>';
  foreach ($data as $row) {
    $html .= '<tr>';
    $html .= '<td>'.htmlspecialchars($row['Date']).'</td>';
    $html .= '<td>'.htmlspecialchars($row['De']).'</td>';
    $html .= '<td>'.htmlspecialchars($row['Vers']).'</td>';
    $html .= '<td class="right">'.(int)$row['Qté'].'</td>';
    $html .= '<td>'.htmlspecialchars($row['Statut']).'</td>';
    $html .= '<td>'.htmlspecialchars($row['Par']).'</td>';
    $html .= '</tr>';
  }
  $html .= '</tbody></table></body></html>';

  $dompdf = new \Dompdf\Dompdf();
  $dompdf->loadHtml($html);
  $dompdf->setPaper('A4', 'landscape');
  $dompdf->render();
  $dompdf->stream('historique_stock_'.$stockId.'.pdf', ['Attachment' => true]);
  exit;
}

http_response_code(400);
echo "Format inconnu.";
