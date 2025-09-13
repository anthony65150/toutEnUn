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
   Employés
============================== */
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

/* ==============================
   Planning de la semaine (affectations)
   $plannedDayMap[user_id][Y-m-d] = [chantier_id => true, ...]
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
   Construction dynamique des jours à afficher
   - Par défaut : Lundi -> Vendredi (5 jours)
   - Ajout Samedi / Dimanche s'il existe AU MOINS UNE
     affectation planning ce jour-là (pour n'importe quel employé)
============================== */
function hasPlanningForDate(array $map, string $iso): bool {
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
// Ajouter samedi/dimanche UNIQUEMENT si planning présent
$sat = (clone $weekStart)->modify('+5 day');
$sun = (clone $weekStart)->modify('+6 day');
$satIso = $sat->format('Y-m-d');
$sunIso = $sun->format('Y-m-d');

if (hasPlanningForDate($plannedDayMap, $satIso)) {
  $days[] = [
    'iso'   => $satIso,
    'label' => ucfirst(strftime('%A %e %B', $sat->getTimestamp())),
    'dow'   => 6
  ];
}
if (hasPlanningForDate($plannedDayMap, $sunIso)) {
  $days[] = [
    'iso'   => $sunIso,
    'label' => ucfirst(strftime('%A %e %B', $sun->getTimestamp())),
    'dow'   => 7
  ];
}

/* ==============================
   Heures pointées de la semaine
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
   Absences de la semaine
============================== */
$absMap = []; // $absMap[user_id][date] = 'conges'|'maladie'|'injustifie'
$stmt = $pdo->prepare("
  SELECT utilisateur_id, date_jour, motif
  FROM pointages_absences
  WHERE entreprise_id = :eid
    AND date_jour >= :d1 AND date_jour < :d2
");
$stmt->execute([':eid' => $entrepriseId, ':d1' => $startIso, ':d2' => $endIso]);
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $absMap[(int)$r['utilisateur_id']][$r['date_jour']] = $r['motif'];
}

/* ==============================
   Conduite A/R de la semaine
============================== */
$conduiteMap = []; // $conduiteMap[user_id][date]['A'|'R']=true
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
?>

<div class="container my-4" id="pointageApp"
  data-week-start="<?= htmlspecialchars($weekStart->format('Y-m-d')) ?>">
  <div class="row align-items-center mb-3">
    <div class="col-12 col-md-4"><!-- vide --></div>
    <div class="col-12 col-md-4 text-center">
      <h1 class="mb-0">Pointage</h1>
    </div>
    <div class="col-12 col-md-4 text-md-end text-muted">
      Semaine <?= $weekNum ?>
    </div>
  </div>

  <!-- Recherche + filtres chantiers -->
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
  <div id="camionControls" class="d-flex align-items-center flex-wrap gap-3 mb-3"></div>

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

  <!-- Tableau -->
<div class="table-responsive">
  <table class="table table-bordered align-middle">
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
            data-name="<?= htmlspecialchars(strtolower($e['nom'] . ' ' . $e['prenom'])) ?>"
            data-chantiers="<?= htmlspecialchars($idsStr) ?>">

          <td style="white-space:nowrap">
            <strong><?= htmlspecialchars($e['nom'] . ' ' . $e['prenom']) ?></strong>
            <span class="badge text-bg-light ms-2"><?= htmlspecialchars($e['fonction']) ?></span>
          </td>

          <?php foreach ($days as $d):
            $dateIso = $d['iso'];
            $dow     = (int)$d['dow']; // 1..7

            // Chantiers planifiés pour le filtre
            $plannedIdsForDay = isset($plannedDayMap[$uid][$dateIso])
              ? implode(',', array_keys($plannedDayMap[$uid][$dateIso]))
              : '';
            $hasPlanning = !empty($plannedDayMap[$uid][$dateIso]);

            // États sauvegardés (issus des maps déjà chargées en amont)
            // hoursMap depuis `pointages` (SUM TIMESTAMPDIFF)
            $hDone = isset($hoursMap[$uid][$dateIso]) ? (float)$hoursMap[$uid][$dateIso] : null;

            // conduiteMap depuis `pointages_conduite` (date_pointage)
            // -> on mappe sur $dateIso pour l'affichage
            $aDone = !empty($conduiteMap[$uid][$dateIso]['A']);
            $rDone = !empty($conduiteMap[$uid][$dateIso]['R']);

            // absMap depuis `pointages_absences` (motif)
            $abs      = $absMap[$uid][$dateIso] ?? null; // 'conges'|'maladie'|'injustifie'
            $isAbsent = ($abs !== null);
            $absLabel = $abs === 'conges' ? 'Congés'
                      : ($abs === 'maladie' ? 'Maladie'
                      : ($abs === 'injustifie' ? 'Injustifié' : ''));

            $hasSavedState = ($hDone !== null) || $aDone || $rDone || $isAbsent;
          ?>
            <td data-date="<?= htmlspecialchars($dateIso) ?>"
                data-planned-chantiers-day="<?= htmlspecialchars($plannedIdsForDay) ?>">

              <?php if ($dow >= 6 && !$hasPlanning && !$hasSavedState): ?>
                <!-- Week-end sans planning ET sans état sauvegardé : croix -->
                <div class="text-center text-muted">×</div>
              <?php else: ?>
                <!-- Présence journée standard (8h15 = 8.25 h) -->
                <div class="mb-2">
                  <button class="btn btn-sm present-btn <?= $hDone ? 'btn-success' : 'btn-outline-success' ?>"
                          data-hours="8.25" <?= $isAbsent ? 'disabled' : '' ?>>
                    Présent 8h15
                  </button>
                </div>

                <!-- Conduite A/R -->
                <div class="d-flex gap-2 mb-2">
                  <button class="btn btn-sm conduite-btn <?= $aDone ? 'btn-primary' : 'btn-outline-primary' ?>"
                          data-type="A" <?= $isAbsent ? 'disabled' : '' ?>>A</button>
                  <button class="btn btn-sm conduite-btn <?= $rDone ? 'btn-success' : 'btn-outline-success' ?>"
                          data-type="R" <?= $isAbsent ? 'disabled' : '' ?>>R</button>
                </div>

                <!-- Absence -->
                <div class="btn-group">
                  <button class="btn btn-sm <?= $isAbsent ? 'btn-danger' : 'btn-outline-danger' ?> absence-btn">
                    <?= $isAbsent ? 'Abs. ' . htmlspecialchars($absLabel) : 'Abs.' ?>
                  </button>
                  <button class="btn btn-sm btn-outline-danger dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown"></button>
                  <ul class="dropdown-menu small">
                    <li><a class="dropdown-item absence-choice" data-reason="conges">Congés</a></li>
                    <li><a class="dropdown-item absence-choice" data-reason="maladie">Maladie</a></li>
                    <li><a class="dropdown-item absence-choice" data-reason="injustifie">Injustifié (non payé)</a></li>
                  </ul>
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

<script>
(function () {
  const filtersBar = document.getElementById('chantierFilters');
  const tbodyRows  = document.querySelectorAll('tbody tr[data-user-id]');
  const thead      = document.querySelector('thead');

  // Jours visibles dans l’entête
  const headerThs = Array.from(document.querySelectorAll('thead th[data-iso]'));
  const dayIsos   = headerThs.map(th => th.dataset.iso);

  // YYYY-MM-DD en local (pas d'effet UTC)
  function todayLocalISO() {
    const d = new Date();
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0,10);
  }
  const todayIso = todayLocalISO();

  // 👉 si "aujourd’hui" n’est pas dans la semaine affichée : pas de jour actif
  let activeDay = dayIsos.includes(todayIso) ? todayIso : null;

  function getActiveColIndex() {
    if (!activeDay) return -1;
    const i = dayIsos.indexOf(activeDay);
    return i === -1 ? -1 : i + 1; // +1 car col 0 = "Employés"
  }

  function highlightActiveDay() {
    // retire toute surbrillance existante
    document.querySelectorAll('.day-active').forEach(el => el.classList.remove('day-active'));

    const col = getActiveColIndex();
    if (col < 0) return; // 👉 rien à surligner si le jour n’est pas visible

    const th = thead?.querySelectorAll('tr th')[col];
    if (th) th.classList.add('day-active');

    document.querySelectorAll('tbody tr').forEach(tr => {
      const td = tr.querySelectorAll('td')[col];
      if (td) td.classList.add('day-active');
    });
  }

  // Cliquer sur un en-tête choisit explicitement ce jour (même si ce n’est pas “aujourd’hui”)
  thead?.addEventListener('click', (e) => {
    const th = e.target.closest('th[data-iso]');
    if (!th) return;
    activeDay = th.dataset.iso || null;
    applyFilter();
  });

  // ----- Filtre chantier (inchangé, mais tolère activeDay=null)
  let activeChantier = 'all';

  function applyFilter() {
    highlightActiveDay();

    tbodyRows.forEach(tr => {
      if (activeChantier === 'all' || !activeDay) {
        // pas de jour actif → pas de filtre de ligne
        tr.classList.remove('d-none');
        return;
      }
      const cell = tr.querySelector(`td[data-date="${activeDay}"]`);
      if (!cell) { tr.classList.add('d-none'); return; }
      const planned = (cell.dataset.plannedChantiersDay || '').split(',').filter(Boolean);
      tr.classList.toggle('d-none', !planned.includes(String(activeChantier)));
    });
  }

  // boutons de filtre de chantier
  const filtersBarEl = document.getElementById('chantierFilters');
  filtersBarEl?.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-chantier]');
    if (!btn) return;
    filtersBarEl.querySelectorAll('button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    activeChantier = btn.dataset.chantier || 'all';
    applyFilter();
  });

  // init
  const current = filtersBarEl?.querySelector('button.active[data-chantier]') || filtersBarEl?.querySelector('button[data-chantier="all"]');
  if (current) activeChantier = current.dataset.chantier;
  applyFilter();
})();
</script>


<style>
  thead th.day-active,
  tbody td.day-active {
    position: relative;
    background-color: #f8f9fa !important;
    box-shadow: inset 0 0 0 9999px rgba(108,117,125,.08);
  }
  thead th.day-active::before,
  tbody td.day-active::before {
    content: "";
    position: absolute;
    top: -1px; bottom: -1px; left: -1px; right: -1px;
    border-left: 2px solid #dee2e6;
    border-right: 2px solid #dee2e6;
    pointer-events: none;
  }
  thead th.day-active .small.fw-semibold {
    color: #343a40;
    font-weight: 600;
  }
  .day-active { box-shadow: inset 0 0 0 9999px rgba(0,0,0,.02); }
  thead th.day-active { background: rgba(0,0,0,.05); }
  thead th { cursor: default; }
  thead th .small.text-muted { cursor: pointer; }

  /* Week-end ajouté : fond léger */
  th.weekend { background: #fafafa; }

  .absence-pill { margin-top: 2px; }
</style>

<script src="js/pointage.js"></script>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
