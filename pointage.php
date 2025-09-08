<?php
require_once "./config/init.php";
require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/navigation/navigation.php';
setlocale(LC_TIME, 'fr_FR.UTF-8','fr_FR','French_France.1252');

if (!isset($_SESSION['utilisateurs'])) { header("Location: connexion.php"); exit; }

$user         = $_SESSION['utilisateurs'];
$role         = $user['fonction'] ?? null;
$userId       = (int)($user['id'] ?? 0);
$entrepriseId = (int)($user['entreprise_id'] ?? 0);

// ----- Semaine affichée (lundi -> dimanche)
$weekStart = isset($_GET['start']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start'])
  ? new DateTime($_GET['start'])
  : new DateTime('monday this week');

$days = [];
for ($i=0; $i<7; $i++) {
  $d = (clone $weekStart)->modify("+$i day");
  $days[] = [
    'iso' => $d->format('Y-m-d'),
    'label' => ucfirst(strftime('%A %e %B', $d->getTimestamp())),
  ];
}
$weekNum = (int)$weekStart->format('W');
$monthTitle = ucfirst(strftime('%B %Y', $weekStart->getTimestamp()));

// ----- Chantiers visibles
if ($role === 'administrateur') {
  $stmt = $pdo->prepare("SELECT id, nom FROM chantiers WHERE entreprise_id=? ORDER BY nom");
  $stmt->execute([$entrepriseId]);
  $visibleChantiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
  $stmt = $pdo->prepare("
    SELECT DISTINCT c.id, c.nom
    FROM utilisateur_chantiers uc
    JOIN chantiers c ON c.id = uc.chantier_id
    WHERE uc.utilisateur_id = ? AND c.entreprise_id = ?
    ORDER BY c.nom
  ");
  $stmt->execute([$userId, $entrepriseId]);
  $visibleChantiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ----- Employés + leurs chantiers (pour menus dans cellules)
$stmt = $pdo->prepare("
  SELECT u.id, u.prenom, u.nom, u.fonction,
         GROUP_CONCAT(DISTINCT uc.chantier_id ORDER BY uc.chantier_id SEPARATOR ',') AS chantier_ids,
         GROUP_CONCAT(DISTINCT c.nom ORDER BY c.nom SEPARATOR '||') AS chantier_noms
  FROM utilisateurs u
  LEFT JOIN utilisateur_chantiers uc ON uc.utilisateur_id = u.id
  LEFT JOIN chantiers c ON c.id = uc.chantier_id AND c.entreprise_id = :eid2
  WHERE u.entreprise_id = :eid1
    AND u.fonction IN ('employe','chef')
  GROUP BY u.id, u.prenom, u.nom, u.fonction
  ORDER BY u.nom, u.prenom
");
$stmt->execute([':eid1' => $entrepriseId, ':eid2' => $entrepriseId]);

$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ----- Pointages travail (heures) de la semaine (optionnel : adapte à ta table)
$startIso = $weekStart->format('Y-m-d');
$endIso   = (clone $weekStart)->modify('+7 days')->format('Y-m-d');

$hoursMap = []; // $hoursMap[user_id][Y-m-d] = nombre d'heures (float)
if ($pdo->query("SHOW TABLES LIKE 'pointages'")->rowCount()) {
  $stmt = $pdo->prepare("
    SELECT utilisateur_id, date_jour, SUM(TIMESTAMPDIFF(MINUTE, heure_debut, heure_fin))/60 AS h
    FROM pointages
    WHERE entreprise_id = :eid
      AND date_jour >= :d1 AND date_jour < :d2
    GROUP BY utilisateur_id, date_jour
  ");
  $stmt->execute([':eid'=>$entrepriseId, ':d1'=>$startIso, ':d2'=>$endIso]);
  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $hoursMap[(int)$r['utilisateur_id']][$r['date_jour']] = (float)$r['h'];
  }
}

// ----- Absences de la semaine
$absMap = []; // $absMap[user_id][date] = 'conges'|'maladie'|'injustifie'
$stmt = $pdo->prepare("
  SELECT utilisateur_id, date_jour, motif
  FROM pointages_absences
  WHERE entreprise_id = :eid
    AND date_jour >= :d1 AND date_jour < :d2
");
$stmt->execute([':eid'=>$entrepriseId, ':d1'=>$startIso, ':d2'=>$endIso]);
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $absMap[(int)$r['utilisateur_id']][$r['date_jour']] = $r['motif'];
}


// ----- Conduite A/R de la semaine
$conduiteMap = []; // $conduiteMap[user_id][date]['A'|'R']=true
$stmt = $pdo->prepare("
  SELECT utilisateur_id, date_pointage, type
  FROM pointages_conduite
  WHERE entreprise_id = :eid
    AND date_pointage >= :d1 AND date_pointage < :d2
");
$stmt->execute([':eid'=>$entrepriseId, ':d1'=>$startIso, ':d2'=>$endIso]);
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $conduiteMap[(int)$r['utilisateur_id']][$r['date_pointage']][$r['type']] = true;
}
?>

<div class="container my-4" id="pointageApp"
     data-week-start="<?= htmlspecialchars($weekStart->format('Y-m-d')) ?>">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm">← Retour</a>
    <h1 class="mb-0">Pointage</h1>
    <div class="text-muted">Semaine <?= $weekNum ?></div>
  </div>

  <!-- Barre recherche + filtres chantier -->
  <div class="mb-3">
    <input type="search" id="searchInput" class="form-control" placeholder="Rechercher un employé…">
  </div>

  <div class="d-flex align-items-center flex-wrap gap-2 mb-3" id="chantierFilters">
    <button class="btn btn-sm btn-outline-secondary active" data-chantier="all">Tous</button>
    <?php foreach ($visibleChantiers as $ch): ?>
      <button class="btn btn-sm btn-outline-secondary" data-chantier="<?= (int)$ch['id'] ?>">
        <?= htmlspecialchars($ch['nom']) ?>
      </button>
    <?php endforeach; ?>
  </div>

  <!-- Nav semaine -->
  <div class="d-flex justify-content-center gap-2 mb-3">
    <?php
      $prev = (clone $weekStart)->modify('-7 days')->format('Y-m-d');
      $curr = (new DateTime('monday this week'))->format('Y-m-d');
      $next = (clone $weekStart)->modify('+7 days')->format('Y-m-d');
    ?>
    <a class="btn btn-outline-secondary" href="?start=<?= $prev ?>">← Semaine -1</a>
    <a class="btn btn-outline-secondary" href="?start=<?= $curr ?>">Cette semaine</a>
    <a class="btn btn-outline-secondary" href="?start=<?= $next ?>">Semaine +1 →</a>
  </div>


  <!-- Tableau type planning -->
  <div class="table-responsive">
    <table class="table table-bordered align-middle">
      <thead class="table-dark">
        <tr>
          <th style="min-width:220px">Employés</th>
          <?php foreach ($days as $d): ?>
            <th>
              <div class="small fw-semibold"><?= htmlspecialchars($d['label']) ?></div>
              <div class="small text-muted"><?= htmlspecialchars($d['iso']) ?></div>
            </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($employees as $e):
        $uid    = (int)$e['id'];
        $idsStr = $e['chantier_ids'] ?: '';
        $nomsStr= $e['chantier_noms'] ?: '';
        $idsArr = $idsStr !== '' ? array_map('intval', explode(',', $idsStr)) : [];
        $nomsArr= $nomsStr !== '' ? explode('||', $nomsStr) : [];
        $pairs  = [];
        foreach ($idsArr as $k => $cid) { $pairs[] = [$cid, $nomsArr[$k] ?? '—']; }
      ?>
        <tr data-user-id="<?= $uid ?>"
            data-name="<?= htmlspecialchars(strtolower($e['nom'].' '.$e['prenom'])) ?>"
            data-chantiers="<?= htmlspecialchars($idsStr) ?>">
          <td style="white-space:nowrap">
            <strong><?= htmlspecialchars($e['nom'].' '.$e['prenom']) ?></strong>
            <span class="badge text-bg-light ms-2"><?= htmlspecialchars($e['fonction']) ?></span>
          </td>

          <?php foreach ($days as $d):
            $dateIso = $d['iso'];
            $hDone   = isset($hoursMap[$uid][$dateIso]) ? (float)$hoursMap[$uid][$dateIso] : null;
            $aDone   = !empty($conduiteMap[$uid][$dateIso]['A']);
            $rDone   = !empty($conduiteMap[$uid][$dateIso]['R']);
          ?>
          <td data-date="<?= htmlspecialchars($dateIso) ?>">
            <!-- Sélect chantier compact dans la cellule -->
            <div class="d-flex align-items-center gap-2 mb-2">
              <select class="form-select form-select-sm chantier-select" style="max-width: 180px">
                <option value="">— chantier —</option>
                <?php foreach ($pairs as [$cid, $cname]): ?>
                  <option value="<?= (int)$cid ?>"><?= htmlspecialchars($cname) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-sm btn-outline-secondary clear-cell" title="Effacer">×</button>
            </div>

            <?php
  $abs = $absMap[$uid][$dateIso] ?? null;
  $isAbsent = $abs !== null;
  $absLabel = $abs === 'conges' ? 'Congés' : ($abs === 'maladie' ? 'Maladie' : ($abs === 'injustifie' ? 'Injustifié' : ''));
?>
<!-- Heures présent (par défaut 8h15 = 8.25h) -->
<div class="btn-group mb-2">
  <button class="btn btn-sm present-btn <?= $hDone ? 'btn-success' : 'btn-outline-success' ?>"
          data-hours="8.25" <?= $isAbsent ? 'disabled' : '' ?>>
    <?= $hDone ? number_format($hDone,2,',','').' h' : 'Présent 8h15' ?>
  </button>
  <button class="btn btn-sm btn-outline-success present-btn" data-hours="7" <?= $isAbsent ? 'disabled' : '' ?>>7h</button>
  <button class="btn btn-sm btn-outline-success present-btn" data-hours="9" <?= $isAbsent ? 'disabled' : '' ?>>9h</button>
</div>

<!-- Conduite A/R -->
<div class="d-flex gap-2 mb-2">
  <button class="btn btn-sm conduite-btn <?= $aDone ? 'btn-primary' : 'btn-outline-primary' ?>"
          data-type="A" <?= ($aDone || $isAbsent) ? 'disabled' : '' ?>>A</button>
  <button class="btn btn-sm conduite-btn <?= $rDone ? 'btn-success' : 'btn-outline-success' ?>"
          data-type="R" <?= ($rDone || $isAbsent) ? 'disabled' : '' ?>>R</button>
</div>

<!-- Absence -->
<div class="btn-group">
  <button class="btn btn-sm <?= $isAbsent ? 'btn-danger' : 'btn-outline-danger' ?> absence-btn" disabled>
    <?= $isAbsent ? 'Abs. '.$absLabel : 'Abs.' ?>
  </button>
  <button class="btn btn-sm btn-outline-danger dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown"></button>
  <ul class="dropdown-menu small">
    <li><a class="dropdown-item absence-choice" data-reason="conges">Congés</a></li>
    <li><a class="dropdown-item absence-choice" data-reason="maladie">Maladie</a></li>
    <li><a class="dropdown-item absence-choice" data-reason="injustifie">Injustifié (non payé)</a></li>
  </ul>
</div>

          </td>
          <?php endforeach; ?>

        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
