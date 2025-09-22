<?php
require_once __DIR__ . '/../config/init.php';
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/navigation/navigation.php';
setlocale(LC_TIME, 'fr_FR.UTF-8', 'fr_FR', 'French_France.1252');

if (!isset($_SESSION['utilisateurs'])) {
  header("Location: ../connexion.php");
  exit;
}

$user         = $_SESSION['utilisateurs'];
$role         = $user['fonction'] ?? null;
$userId       = (int)($user['id'] ?? 0);
$entrepriseId = (int)($user['entreprise_id'] ?? 0);

/* ==============================
   Semaine affichée (lundi)
============================== */
$weekStart = isset($_GET['start']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start'])
  ? new DateTime($_GET['start'])
  : new DateTime('monday this week');

$weekNum     = (int)$weekStart->format('W');
$monthTitle  = ucfirst(strftime('%B %Y', $weekStart->getTimestamp()));
$startIso    = $weekStart->format('Y-m-d');
$endIso      = (clone $weekStart)->modify('+7 days')->format('Y-m-d'); // exclusif

/* ==============================
   Filtre chantier actif (depuis l'URL)
============================== */
$currentChantierId = isset($_GET['chantier_id']) ? max(0, (int)$_GET['chantier_id']) : 0;

/* ==============================
   Chantiers visibles pour filtres
============================== */
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

/* ==============================
   Agences (pour filtre)
============================== */
$sqlAg = "SELECT id, nom
          FROM agences
          WHERE entreprise_id = :e
          ORDER BY nom";
$st = $pdo->prepare($sqlAg);
$st->execute([':e' => $entrepriseId]);
$agences = $st->fetchAll(PDO::FETCH_ASSOC);

/* ==============================
   Employés (inclure dépôt) + agence
============================== */
$stmt = $pdo->prepare("
  SELECT u.id, u.prenom, u.nom, u.fonction,
         u.agence_id, a.nom AS agence_nom,
         GROUP_CONCAT(DISTINCT uc.chantier_id ORDER BY uc.chantier_id SEPARATOR ',') AS chantier_ids,
         GROUP_CONCAT(DISTINCT c.nom ORDER BY c.nom SEPARATOR '||') AS chantier_noms
  FROM utilisateurs u
  LEFT JOIN utilisateur_chantiers uc ON uc.utilisateur_id = u.id
  LEFT JOIN chantiers c ON c.id = uc.chantier_id AND c.entreprise_id = :eid2
  LEFT JOIN agences   a ON a.id = u.agence_id AND a.entreprise_id = :eid3
  WHERE u.entreprise_id = :eid1
    AND u.fonction IN ('employe','chef','depot')
  GROUP BY u.id, u.prenom, u.nom, u.fonction, u.agence_id, a.nom
  ORDER BY (a.nom IS NULL), a.nom, u.nom, u.prenom
");
$stmt->execute([':eid1' => $entrepriseId, ':eid2' => $entrepriseId, ':eid3' => $entrepriseId]);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ==============================
   Planning de la semaine (affectations)
============================== */
$plannedDayMap = [];
if ($pdo->query("SHOW TABLES LIKE 'planning_affectations'")->rowCount()) {
  $stmt = $pdo->prepare("
    SELECT utilisateur_id, chantier_id, date_jour
    FROM planning_affectations
    WHERE entreprise_id = :eid
      AND date_jour >= :d1 AND date_jour < :d2
    GROUP BY utilisateur_id, chantier_id, date_jour
  ");
  $stmt->execute([':eid' => $entrepriseId, ':d1' => $startIso, ':d2' => $endIso]);
  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $uid = (int)$r['utilisateur_id'];
    $cid = (int)$r['chantier_id'];
    $dj  = $r['date_jour'];
    $plannedDayMap[$uid][$dj][$cid] = true;
  }
}

/* ==============================
   Jours (Lun→Ven + Sam/Dim si planning)
============================== */
function hasPlanningForDate(array $map, string $iso): bool
{
  foreach ($map as $byDate) {
    if (!empty($byDate[$iso])) return true;
  }
  return false;
}
$days = [];
for ($i = 0; $i < 5; $i++) { // Lun -> Ven
  $d = (clone $weekStart)->modify("+$i day");
  $days[] = [
    'iso'   => $d->format('Y-m-d'),
    'label' => ucfirst(strftime('%A %e %B', $d->getTimestamp())),
    'dow'   => (int)$d->format('N'),
  ];
}
$sat = (clone $weekStart)->modify('+5 day');
$sun = (clone $weekStart)->modify('+6 day');
$satIso = $sat->format('Y-m-d');
$sunIso = $sun->format('Y-m-d');
if (hasPlanningForDate($plannedDayMap, $satIso)) {
  $days[] = ['iso' => $satIso, 'label' => ucfirst(strftime('%A %e %B', $sat->getTimestamp())), 'dow' => 6];
}
if (hasPlanningForDate($plannedDayMap, $sunIso)) {
  $days[] = ['iso' => $sunIso, 'label' => ucfirst(strftime('%A %e %B', $sun->getTimestamp())), 'dow' => 7];
}

/* ==============================
   Heures pointées
============================== */
$hoursMap = [];
$stH = $pdo->prepare("
  SELECT utilisateur_id, date_jour, heures
  FROM pointages_jour
  WHERE entreprise_id = :eid
    AND date_jour >= :d1 AND date_jour < :d2
");
$stH->execute([':eid' => $entrepriseId, ':d1' => $startIso, ':d2' => $endIso]);
foreach ($stH->fetchAll(PDO::FETCH_ASSOC) as $r) {
  $hoursMap[(int)$r['utilisateur_id']][$r['date_jour']] = (float)$r['heures'];
}

/* ==============================
   Absences (motif + heures)
============================== */
$absMap = [];
$stmt = $pdo->prepare("
  SELECT utilisateur_id, date_jour, motif, heures
  FROM pointages_absences
  WHERE entreprise_id = :eid
    AND date_jour >= :d1 AND date_jour < :d2
");
$stmt->execute([':eid' => $entrepriseId, ':d1' => $startIso, ':d2' => $endIso]);
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $uidR = (int)$r['utilisateur_id'];
  $dj   = $r['date_jour'];
  $absMap[$uidR][$dj] = [
    'motif'  => $r['motif'],
    'heures' => ($r['heures'] === null ? null : (float)$r['heures']),
  ];
}

/* ==============================
   Conduite A/R
============================== */
$conduiteMap = [];
$stmt = $pdo->prepare("
  SELECT utilisateur_id, date_pointage, type
  FROM pointages_conduite
  WHERE entreprise_id = :eid
    AND date_pointage >= :d1 AND date_pointage < :d2
");
$stmt->execute([':eid' => $entrepriseId, ':d1' => $startIso, ':d2' => $endIso]);
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $conduiteMap[(int)$r['utilisateur_id']][$r['date_pointage']][$r['type']] = true;
}

function badgeRole($role)
{
  $r = mb_strtolower($role);
  if ($r === 'employé') $r = 'employe';
  switch ($r) {
    case 'administrateur':
    case 'admin':
      return '<span class="badge bg-danger">Administrateur</span>';
    case 'depot':
      return '<span class="badge bg-info text-dark">Dépôt</span>';
    case 'chef':
      return '<span class="badge bg-success">Chef</span>';
    case 'employe':
      return '<span class="badge bg-warning text-dark">Employé</span>';
    default:
      return '<span class="badge bg-secondary">Autre</span>';
  }
}

function fmtHM(float $dec): string
{
  $h = (int)floor($dec);
  $m = (int)round(($dec - $h) * 60);
  return $h . 'h' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
}
?>

<?php
/* ==== Tâche du jour (pour préafficher dans la cellule) ==== */
$dates = array_column($days, 'iso');
$tacheMap = [];

if ($dates) {
  $minD = min($dates);
  $maxD = max($dates);

  // Détection libellé (shortcut prioritaire)
  $cols = $pdo->query("SHOW COLUMNS FROM chantier_taches")->fetchAll(PDO::FETCH_COLUMN, 0);
  $hasShortcut = in_array('shortcut', $cols, true);
  $labelCol = null;
  foreach (['libelle', 'nom', 'titre', 'intitule', 'label'] as $c) {
    if (in_array($c, $cols, true)) {
      $labelCol = $c;
      break;
    }
  }
  $libExpr = "COALESCE(
    NULLIF(ct.`shortcut`, ''),
    " . ($labelCol ? "NULLIF(ct.`$labelCol`, '')," : "") . "
    CAST(ct.`id` AS CHAR)
  )";

  // La colonne updated_at existe ?
  $ptCols = $pdo->query("SHOW COLUMNS FROM pointages_taches")->fetchAll(PDO::FETCH_COLUMN, 0);
  $hasUpdated = in_array('updated_at', $ptCols, true);

  if ($hasUpdated) {
    $sqlT = "
      SELECT pt.utilisateur_id, pt.date_jour, pt.tache_id, pt.chantier_id, pt.heures,
             $libExpr AS libelle
      FROM pointages_taches pt
      LEFT JOIN chantier_taches ct
        ON ct.id = pt.tache_id AND ct.entreprise_id = ?
      WHERE pt.entreprise_id = ?
        AND pt.date_jour BETWEEN ? AND ?
        AND NOT EXISTS (
          SELECT 1
          FROM pointages_taches x
          WHERE x.entreprise_id = pt.entreprise_id
            AND x.utilisateur_id = pt.utilisateur_id
            AND x.date_jour      = pt.date_jour
            AND (
                 (x.updated_at > pt.updated_at)
              OR (x.updated_at = pt.updated_at AND x.id > pt.id)
            )
        )
    ";
    $params = [$entrepriseId, $entrepriseId, $minD, $maxD];
  } else {
    $sqlT = "
      SELECT pt.utilisateur_id, pt.date_jour, pt.tache_id, pt.chantier_id, pt.heures,
             $libExpr AS libelle
      FROM pointages_taches pt
      JOIN (
        SELECT utilisateur_id, date_jour, MAX(id) AS mid
        FROM pointages_taches
        WHERE entreprise_id = ? AND date_jour BETWEEN ? AND ?
        GROUP BY utilisateur_id, date_jour
      ) last ON last.utilisateur_id = pt.utilisateur_id
           AND last.date_jour      = pt.date_jour
           AND last.mid            = pt.id
      LEFT JOIN chantier_taches ct
        ON ct.id = pt.tache_id AND ct.entreprise_id = ?
      WHERE pt.entreprise_id = ?
    ";
    $params = [$entrepriseId, $minD, $maxD, $entrepriseId, $entrepriseId];
  }

  $stT = $pdo->prepare($sqlT);
  $stT->execute($params);

  while ($r = $stT->fetch(PDO::FETCH_ASSOC)) {
    $u = (int)$r['utilisateur_id'];
    $d = $r['date_jour'];
    $tacheMap[$u][$d] = [
      'id'      => (int)$r['tache_id'],
      'libelle' => (string)($r['libelle'] ?? ''),
      'heures'  => (float)($r['heures'] ?? 0),
      'cid'     => (int)$r['chantier_id'],
    ];
  }
}



?>

<div class="container my-4" id="pointageApp"
  data-week-start="<?= htmlspecialchars($weekStart->format('Y-m-d')) ?>"
  data-role="<?= htmlspecialchars(strtolower($role)) ?>"
  data-chantier-id="<?= (int)$currentChantierId ?>">
  <div class="row align-items-center mb-3">
    <div class="col-12 col-md-4"><!-- vide --></div>
    <div class="col-12 col-md-4 text-center">
      <?php
      // si chef: forcer un chantier sélectionné par défaut
      if ($role !== 'administrateur' && $currentChantierId === 0 && !empty($visibleChantiers)) {
        $currentChantierId = (int)$visibleChantiers[0]['id'];
      }
      $currentChantierName = '';
      foreach ($visibleChantiers as $ch) {
        if ((int)$ch['id'] === (int)$currentChantierId) {
          $currentChantierName = $ch['nom'];
          break;
        }
      }
      ?>
      <h1 id="pageTitle" class="mb-0">
        <?= ($role === 'administrateur') ? 'Pointage' : 'Pointage ' . htmlspecialchars($currentChantierName ?: '') ?>
      </h1>
    </div>
    <div class="col-12 col-md-4 text-md-end text-muted">
      Semaine <?= $weekNum ?>
    </div>
  </div>


  <!-- Filtres agence(centré) -->
  <?php if ($role === 'administrateur' && !empty($agences)): ?>
    <div class="d-flex justify-content-center mb-2">
      <div id="agenceFilters" class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-sm btn-primary" data-agence="all">Tous</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-agence="0">Sans agence</button>
        <?php foreach ($agences as $ag): ?>
          <button type="button" class="btn btn-sm btn-outline-secondary" data-agence="<?= (int)$ag['id'] ?>">
            <?= htmlspecialchars($ag['nom']) ?>
          </button>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>



  <!-- Recherche + filtres chantiers existants -->
  <div class="mb-3">
    <input type="search" id="searchInput" class="form-control" placeholder="Rechercher un employé…">
  </div>

  <?php
  $chefHasSingleChantier = ($role !== 'administrateur' && count($visibleChantiers) <= 1);
  ?>
  <div class="d-flex align-items-center flex-wrap gap-2 mb-3 <?= $chefHasSingleChantier ? 'd-none' : '' ?>" id="chantierFilters">
    <?php if ($role === 'administrateur'): ?>
      <button class="btn btn-sm btn-outline-secondary <?= $currentChantierId === 0 ? 'active' : '' ?>" data-chantier="all">Tous</button>
    <?php endif; ?>

    <?php foreach ($visibleChantiers as $ch): $cid = (int)$ch['id']; ?>
      <button class="btn btn-sm btn-outline-secondary <?= ($cid === (int)$currentChantierId ? 'active' : '') ?>"
        data-chantier="<?= $cid ?>">
        <?= htmlspecialchars($ch['nom']) ?>
      </button>
    <?php endforeach; ?>
  </div>

  <div id="camionControls" class="mb-2 d-flex align-items-center gap-2">
    <label for="camionCount" class="mb-0 small text-muted">Nombre de camions</label>
    <div class="input-group input-group-sm camion-stepper" style="width:140px">
      <button class="btn btn-outline-secondary" type="button" data-action="decr" aria-label="Diminuer">−</button>
      <input id="camionCount" type="text" class="form-control text-center"
        value="0" inputmode="numeric" pattern="[0-9]*" aria-label="Nombre de camions">
      <button class="btn btn-outline-secondary" type="button" data-action="incr" aria-label="Augmenter">+</button>
    </div>
  </div>


  <!-- Nav semaine -->
  <div class="d-flex justify-content-center gap-2 mb-3">
    <?php
    $prev = (clone $weekStart)->modify('-7 days')->format('Y-m-d');
    $curr = (new DateTime('monday this week'))->format('Y-m-d');
    $next = (clone $weekStart)->modify('+7 days')->format('Y-m-d');
    ?>
    <a class="btn btn-outline-secondary" href="?start=<?= $prev ?><?= $currentChantierId ? '&chantier_id=' . $currentChantierId : '' ?>">← Semaine -1</a>
    <a class="btn btn-outline-secondary" href="?start=<?= $curr ?><?= $currentChantierId ? '&chantier_id=' . $currentChantierId : '' ?>">Cette semaine</a>
    <a class="btn btn-outline-secondary" href="?start=<?= $next ?><?= $currentChantierId ? '&chantier_id=' . $currentChantierId : '' ?>">Semaine +1 →</a>
  </div>

  <!-- Tableau -->
  <div class="table-responsive">
    <?php
    $chMap = [];
    foreach ($visibleChantiers as $ch) {
      $chMap[(int)$ch['id']] = $ch['nom'];
    }
    ?>
    <script>
      window.CHANTIERS = <?= json_encode($chMap, JSON_UNESCAPED_UNICODE) ?>;
    </script>

    <table class="table table-bordered table-hover table-striped align-middle pointage-table">
      <thead class="table-dark">
        <tr>
          <th style="min-width:220px">Employés</th>
          <?php foreach ($days as $d): ?>
            <th data-iso="<?= htmlspecialchars($d['iso']) ?>" class="<?= ($d['dow'] >= 6) ? 'weekend' : '' ?>">
              <div class="small fw-semibold"><?= htmlspecialchars($d['label']) ?></div>
            </th>
          <?php endforeach; ?>
        </tr>
      </thead>

      <tbody>
        <?php foreach ($employees as $e):
          $uid     = (int)$e['id'];
          $idsStr  = $e['chantier_ids'] ?: '';
          $nomsStr = $e['chantier_noms'] ?: '';
          $idsArr  = $idsStr !== '' ? array_map('intval', explode(',', $idsStr)) : [];
          $nomsArr = $nomsStr !== '' ? explode('||', $nomsStr) : [];
        ?>
          <tr data-user-id="<?= $uid ?>"
            data-role="<?= htmlspecialchars(strtolower($e['fonction'])) ?>"
            data-agence-id="<?= (int)($e['agence_id'] ?? 0) ?>"
            data-name="<?= htmlspecialchars(strtolower($e['nom'] . ' ' . $e['prenom'])) ?>"
            data-chantiers="<?= htmlspecialchars($idsStr) ?>">

            <!-- Nom + rôle -->
            <td class="emp-cell">
              <strong><?= htmlspecialchars($e['nom'] . ' ' . $e['prenom']) ?></strong>
              <?= badgeRole($e['fonction']) ?>
            </td>

            <?php foreach ($days as $d):
              $dateIso = $d['iso'];
              $dow     = (int)$d['dow'];

              $plannedIdsForDay = isset($plannedDayMap[$uid][$dateIso])
                ? implode(',', array_keys($plannedDayMap[$uid][$dateIso]))
                : '';
              $hasPlanning = !empty($plannedDayMap[$uid][$dateIso]);

              $hDone = isset($hoursMap[$uid][$dateIso]) ? (float)$hoursMap[$uid][$dateIso] : null;

              $aDone = !empty($conduiteMap[$uid][$dateIso]['A']);
              $rDone = !empty($conduiteMap[$uid][$dateIso]['R']);

              $absData   = $absMap[$uid][$dateIso] ?? null;
              $isAbsent  = is_array($absData);
              $absMotif  = $absData['motif']  ?? null;
              $absHeures = $absData['heures'] ?? null;

              $absLabel = $absMotif === 'conges' ? 'Congés'
                : ($absMotif === 'maladie' ? 'Maladie'
                  : ($absMotif === 'injustifie' ? 'Injustifié' : ''));

              $hasSavedState = ($hDone !== null) || $aDone || $rDone || $isAbsent;

              /* --- Calculs avant rendu --- */
              $presentIsActive = ($hDone !== null && $hDone > 0);
              $presentLabel    = $presentIsActive ? ('Présent ' . fmtHM((float)$hDone)) : 'Présent 8h15';

              $absText  = 'Abs.';
              if ($isAbsent) {
                $absText = $absLabel;
                if ($absHeures !== null) $absText .= ' ' . str_replace('.', ',', (string)$absHeures) . ' h';
              }
              $absClass = $isAbsent ? 'btn-danger' : 'btn-outline-danger';

              $tInfo   = $tacheMap[$uid][$dateIso] ?? null;
              $tId     = $tInfo['id']      ?? '';
              $tLib    = $tInfo['libelle'] ?? '';
              $tHeures = isset($tInfo['heures']) ? (float)$tInfo['heures'] : ($hDone ?? null);
            ?>
              <td class="tl-cell text-center"
                data-date="<?= htmlspecialchars($dateIso) ?>"
                data-day-label="<?= htmlspecialchars($d['label']) ?>"
                data-planned-chantiers-day="<?= htmlspecialchars($plannedIdsForDay) ?>">

                <!-- Dot de la timeline -->
                <span class="tl-dot <?= $isAbsent ? 'absent' : ($presentIsActive ? 'present' : (!empty($plannedIdsForDay) ? 'plan' : '')) ?>"></span>

                <?php if ($dow >= 6 && !$hasPlanning && !$hasSavedState): ?>
                  <div class="text-muted">×</div>
                <?php else: ?>
                  <div class="tl-actions mb-2 d-flex flex-wrap gap-1 justify-content-center">
                    <button class="btn btn-sm present-btn <?= $presentIsActive ? 'btn-success' : 'btn-outline-success' ?>"
                      data-hours="8.25" <?= $isAbsent ? 'disabled' : '' ?>>
                      <?= htmlspecialchars($presentLabel) ?>
                    </button>

                    <button class="btn btn-sm conduite-btn <?= $aDone ? 'btn-primary' : 'btn-outline-primary' ?>"
                      data-type="A" <?= $isAbsent ? 'disabled' : '' ?>>A</button>

                    <button class="btn btn-sm conduite-btn <?= $rDone ? 'btn-success' : 'btn-outline-success' ?>"
                      data-type="R" <?= $isAbsent ? 'disabled' : '' ?>>R</button>

                    <button type="button" class="btn btn-sm <?= $absClass ?> absence-btn">
                      <?= htmlspecialchars($absText) ?>
                    </button>
                  </div>

                  <div class="tl-task task-slot"
                    data-click-tache
                    data-user-id="<?= $uid ?>"
                    data-date="<?= htmlspecialchars($dateIso) ?>"
                    data-tache-id="<?= htmlspecialchars((string)$tId) ?>"
                    data-heures="<?= $tHeures !== null ? htmlspecialchars((string)$tHeures) : '' ?>"
                    data-pt-chantier-id="<?= (int)($tacheMap[$uid][$dateIso]['cid'] ?? $currentChantierId) ?>">

                    <?php if ($tId): ?>
                      <span class="badge bg-primary"><?= htmlspecialchars($tLib) ?></span>
                      <?php if ($tHeures !== null && (float)$tHeures !== 8.25): ?>
                        <div class="small text-muted mt-1">
                          <?= htmlspecialchars(number_format((float)$tHeures, 2, ',', '')) ?> h
                        </div>
                      <?php endif; ?>
                    <?php else: ?>
                      <button type="button" class="btn btn-sm btn-outline-secondary">+ Tâche</button>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>



    </table>
  </div>

  <!-- Modal Absence -->
  <div class="modal fade" id="absenceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header py-2">
          <h6 class="modal-title">Déclarer une absence</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
        </div>
        <div class="modal-body">
          <form id="absenceForm">
            <input type="hidden" name="utilisateur_id" id="absUserId">
            <input type="hidden" name="date" id="absDate">

            <div class="mb-3">
              <label class="form-label">Motif</label>
              <div class="d-grid gap-2">
                <label class="btn btn-outline-secondary btn-sm text-start">
                  <input type="radio" class="form-check-input me-2" name="reason" value="conges" checked>
                  Congés
                </label>
                <label class="btn btn-outline-secondary btn-sm text-start">
                  <input type="radio" class="form-check-input me-2" name="reason" value="maladie">
                  Maladie
                </label>
                <label class="btn btn-outline-secondary btn-sm text-start">
                  <input type="radio" class="form-check-input me-2" name="reason" value="injustifie">
                  Injustifié (non payé)
                </label>
              </div>
            </div>

            <div class="mb-2">
              <label class="form-label">Heures d’absence</label>
              <input type="number" step="0.25" min="0.25" max="8.25" class="form-control form-control-sm" id="absHours" value="8.25">
              <div class="form-text">8.25 = journée complète (8h15)</div>
            </div>
          </form>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
          <button type="button" class="btn btn-danger btn-sm" id="absenceSave">Enregistrer</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal: Tâche du jour -->
  <div class="modal fade" id="tacheJourModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
      <form class="modal-content" id="tacheJourForm">
        <div class="modal-header">
          <h5 class="modal-title">Tâche du jour</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="utilisateur_id" id="tj_utilisateur_id">
          <input type="hidden" name="date_jour" id="tj_date_jour">
          <input type="hidden" name="tache_id" id="tj_tache_id">
          <p class="text-muted small mb-2">
            Touchez une tâche pour la sélectionner, touchez à nouveau pour la retirer.
          </p>


          <div id="tj_list" class="list-group" style="max-height: 50vh; overflow:auto;"></div>

          <div class="form-text mt-2">
            Les heures de la journée sont reprises du bouton <em>Présent</em> (ou du calcul Présent = 8h15 − Absence).
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
        </div>
      </form>
    </div>
  </div>



  <script>
    window.POINTAGE_DAYS = <?= json_encode(array_column($days, 'iso')) ?>;
    window.API_CAMIONS_CFG = "/pointage/api/camions_config.php";
  </script>



  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>

  <script src="js/pointage.js"></script>
  <?php require_once __DIR__ . '/../templates/footer.php'; ?>