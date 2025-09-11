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

// ----- Semaine affichée (lundi -> dimanche)
$weekStart = isset($_GET['start']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start'])
  ? new DateTime($_GET['start'])
  : new DateTime('monday this week');

$days = [];
for ($i = 0; $i < 7; $i++) {
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

// ----- Pointages travail (heures) de la semaine
$startIso = $weekStart->format('Y-m-d');
$endIso   = (clone $weekStart)->modify('+7 days')->format('Y-m-d');

// ----- Employés planifiés PAR JOUR sur la semaine (depuis le planning)
$plannedDayMap = []; // $plannedDayMap[user_id][Y-m-d] = [chantier_id => true, ...]

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

$hoursMap = []; // $hoursMap[user_id][Y-m-d] = nombre d'heures (float)
if ($pdo->query("SHOW TABLES LIKE 'pointages'")->rowCount()) {
  $stmt = $pdo->prepare("
    SELECT utilisateur_id, date_jour, SUM(TIMESTAMPDIFF(MINUTE, heure_debut, heure_fin))/60 AS h
    FROM pointages
    WHERE entreprise_id = :eid
      AND date_jour >= :d1 AND date_jour < :d2
    GROUP BY utilisateur_id, date_jour
  ");
  $stmt->execute([':eid' => $entrepriseId, ':d1' => $startIso, ':d2' => $endIso]);
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
$stmt->execute([':eid' => $entrepriseId, ':d1' => $startIso, ':d2' => $endIso]);
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
$stmt->execute([':eid' => $entrepriseId, ':d1' => $startIso, ':d2' => $endIso]);
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $conduiteMap[(int)$r['utilisateur_id']][$r['date_pointage']][$r['type']] = true;
}
?>

<div class="container my-4" id="pointageApp"
  data-week-start="<?= htmlspecialchars($weekStart->format('Y-m-d')) ?>">
  <div class="row align-items-center mb-3">
    <div class="col-12 col-md-4"><!-- vide, ancien emplacement du bouton --></div>
    <div class="col-12 col-md-4 text-center">
      <h1 class="mb-0">Pointage</h1>
    </div>
    <div class="col-12 col-md-4 text-md-end text-muted">
      Semaine <?= $weekNum ?>
    </div>
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

  <!-- Tableau type planning -->
  <div class="table-responsive">
    <table class="table table-bordered align-middle">
      <thead class="table-dark">
        <tr>
          <th style="min-width:220px">Employés</th>
          <?php foreach ($days as $d): ?>
            <th data-iso="<?= htmlspecialchars($d['iso']) ?>">
              <div class="small fw-semibold"><?= htmlspecialchars($d['label']) ?></div>
            </th>
          <?php endforeach; ?>
        </tr>
      </thead>

      <tbody>
        <?php foreach ($employees as $e):
          $uid    = (int)$e['id'];
          $idsStr = $e['chantier_ids'] ?: '';
          $nomsStr = $e['chantier_noms'] ?: '';
          $idsArr = $idsStr !== '' ? array_map('intval', explode(',', $idsStr)) : [];
          $nomsArr = $nomsStr !== '' ? explode('||', $nomsStr) : [];
          $pairs  = [];
          foreach ($idsArr as $k => $cid) {
            $pairs[] = [$cid, $nomsArr[$k] ?? '—'];
          }
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
              $hDone   = isset($hoursMap[$uid][$dateIso]) ? (float)$hoursMap[$uid][$dateIso] : null;
              $aDone   = !empty($conduiteMap[$uid][$dateIso]['A']);
              $rDone   = !empty($conduiteMap[$uid][$dateIso]['R']);
              $plannedIdsForDay = isset($plannedDayMap[$uid][$dateIso])
                ? implode(',', array_keys($plannedDayMap[$uid][$dateIso]))
                : '';
            ?>
              <td data-date="<?= htmlspecialchars($dateIso) ?>"
                data-planned-chantiers-day="<?= htmlspecialchars($plannedIdsForDay) ?>">


                <?php
                $abs = $absMap[$uid][$dateIso] ?? null;
                $isAbsent = $abs !== null;
                $absLabel = $abs === 'conges' ? 'Congés' : ($abs === 'maladie' ? 'Maladie' : ($abs === 'injustifie' ? 'Injustifié' : ''));
                ?>
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
                    <?= $isAbsent ? 'Abs. ' . $absLabel : 'Abs.' ?>
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
  (function() {
    const filtersBar = document.getElementById('chantierFilters');
    const tbodyRows = document.querySelectorAll('tbody tr[data-user-id]');
    const thead = document.querySelector('thead');
    const headerIsoCells = Array.from(document.querySelectorAll('thead th .small.text-muted'));
    const dayIsos = headerIsoCells.map(el => el.textContent.trim());
    const todayIso = new Date().toISOString().slice(0, 10);

    // jour actif = today si présent, sinon 1er jour
    let activeDay = dayIsos.includes(todayIso) ? todayIso : dayIsos[0];

    // renvoie l'index de colonne (dans le tableau complet) du jour actif
    function getActiveColIndex() {
      const dayIdx = dayIsos.indexOf(activeDay); // 0..6
      if (dayIdx === -1) return -1;
      return dayIdx + 1; // +1 car col 0 = "Employés"
    }

    function highlightActiveDay() {
      const colIndex = getActiveColIndex();
      // retire les highlights existants
      document.querySelectorAll('.day-active').forEach(el => el.classList.remove('day-active'));

      if (colIndex < 0) return;

      // th du jour
      const th = thead?.querySelectorAll('tr th')[colIndex];
      if (th) th.classList.add('day-active');

      // toutes les td de cette colonne
      document.querySelectorAll('tbody tr').forEach(tr => {
        const tds = tr.querySelectorAll('td');
        const td = tds[colIndex];
        if (td) td.classList.add('day-active');
      });
    }

    // click sur l’en-tête = changer de jour actif
    thead?.addEventListener('click', (e) => {
      const th = e.target.closest('th');
      if (!th) return;
      const iso = th.querySelector('.small.text-muted')?.textContent?.trim();
      if (!iso) return; // a cliqué sur la colonne "Employés"
      activeDay = iso;
      applyFilter();
    });

    // filtre chantier
    let activeChantier = 'all';

    function applyFilter() {
      highlightActiveDay();

      tbodyRows.forEach(tr => {
        if (activeChantier === 'all') {
          tr.classList.remove('d-none');
          return;
        }
        const cell = tr.querySelector(`td[data-date="${activeDay}"]`);
        if (!cell) {
          tr.classList.add('d-none');
          return;
        }
        const planned = (cell.dataset.plannedChantiersDay || '').split(',').filter(Boolean);
        const show = planned.includes(String(activeChantier));
        tr.classList.toggle('d-none', !show);
      });
    }

    // boutons chantier
    filtersBar?.addEventListener('click', (e) => {
      const btn = e.target.closest('button[data-chantier]');
      if (!btn) return;
      filtersBar.querySelectorAll('button').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      activeChantier = btn.dataset.chantier; // "all" ou id
      applyFilter();
    });

    // init
    const current = filtersBar?.querySelector('button.active[data-chantier]') || filtersBar?.querySelector('button[data-chantier="all"]');
    if (current) activeChantier = current.dataset.chantier;
    applyFilter();
  })();
</script>

<style>
/* Surbrillance douce de la colonne active en gris */
thead th.day-active,
tbody td.day-active {
  position: relative;
  background-color: #f8f9fa !important; /* gris clair bootstrap */
  box-shadow: inset 0 0 0 9999px rgba(108, 117, 125, 0.08); /* gris léger */
}

/* Bordures latérales grises discrètes */
thead th.day-active::before,
tbody td.day-active::before {
  content: "";
  position: absolute;
  top: -1px; bottom: -1px; left: -1px; right: -1px;
  border-left: 2px solid #dee2e6;  /* gris clair */
  border-right: 2px solid #dee2e6;
  pointer-events: none;
}

/* Titre du jour un peu renforcé */
thead th.day-active .small.fw-semibold {
  color: #343a40; /* gris foncé pour contraster */
  font-weight: 600;
}


  /* optionnel : léger highlight de la colonne du jour actif */
  .day-active {
    box-shadow: inset 0 0 0 9999px rgba(0, 0, 0, 0.02);
  }

  thead th.day-active {
    background: rgba(0, 0, 0, 0.05);
  }

  thead th {
    cursor: default;
  }

  thead th .small.text-muted {
    cursor: pointer;
  }

  .absence-pill { margin-top: 2px; }

  /* signaler qu'on peut cliquer sur un jour */
</style>

<script src="js/pointage.js"></script>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>