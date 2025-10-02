<?php
require_once __DIR__ . '/../config/init.php';

if (!isset($_SESSION['utilisateurs'])) {
  header('Location: ../connexion.php');
  exit;
}

$user         = $_SESSION['utilisateurs'];
$role         = $user['fonction'] ?? null;
$userId       = (int)($user['id'] ?? 0);
$entrepriseId = (int)($user['entreprise_id'] ?? 0);

$weekStartParam = (isset($_GET['start']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start']))
  ? '&start=' . $_GET['start']
  : '';

$isAdmin = ($role === 'administrateur');
$chantierId = isset($_GET['chantier_id']) ? (int)$_GET['chantier_id'] : 0;

/* ==============================
   Chantiers visibles / autorisés
============================== */
if ($isAdmin) {
  // Admin : liste complète
  $stmt = $pdo->prepare("
    SELECT c.id, c.nom, c.depot_id, d.nom AS depot_nom
    FROM chantiers c
    LEFT JOIN depots d
      ON d.id = c.depot_id AND d.entreprise_id = c.entreprise_id
    WHERE c.entreprise_id = ?
    ORDER BY c.nom
  ");
  $stmt->execute([$entrepriseId]);
  $visibleChantiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
  // Chef : uniquement ses chantiers
  $stmt = $pdo->prepare("
    SELECT DISTINCT c.id, c.nom, c.depot_id, d.nom AS depot_nom
    FROM utilisateur_chantiers uc
    JOIN chantiers c ON c.id = uc.chantier_id AND c.entreprise_id = :eid
    LEFT JOIN depots d
      ON d.id = c.depot_id AND d.entreprise_id = c.entreprise_id
    WHERE uc.utilisateur_id = :uid
    ORDER BY c.nom
  ");
  $stmt->execute([':uid' => $userId, ':eid' => $entrepriseId]);
  $visibleChantiers = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // liste blanche des IDs autorisés
  $allowedIds = array_map(fn($r) => (int)$r['id'], $visibleChantiers);

  if (!$allowedIds) {
    // aucun chantier rattaché
    echo '<!doctype html><meta charset="utf-8"><div style="padding:2rem;font-family:sans-serif">
            <h3>Aucun chantier rattaché</h3>
            <p>Demande à un administrateur de te rattacher à un chantier.</p>
          </div>';
    exit;
  }

  // 1) s’il n’y a pas d’id dans l’URL → forcer le 1er autorisé
  if ($chantierId <= 0) {
    $first = $allowedIds[0];
    header("Location: pointage.php?chantier_id={$first}{$weekStartParam}");
    exit;
  }

  // 2) s’il y a un id mais qu’il n’est PAS autorisé → rediriger vers le 1er autorisé
  if (!in_array($chantierId, $allowedIds, true)) {
    $first = $allowedIds[0];
    header("Location: pointage.php?chantier_id={$first}{$weekStartParam}");
    exit;
  }
}

// Admin : pas de redirection ; $chantierId peut rester 0 (aucun filtre chantier)

/* ==============================
   À PARTIR D'ICI : on peut envoyer du HTML
   ============================== */
setlocale(LC_TIME, 'fr_FR.UTF-8', 'fr_FR', 'French_France.1252');
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/navigation/navigation.php';

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
$currentChantierId = $chantierId;

/* ==========================================
   Chantiers pour le filtre SOUS "Agence"
   (Admin: tous ; Chef: seulement autorisés)
========================================== */
if ($isAdmin) {
  $stAll = $pdo->prepare("
    SELECT 
      c.id,
      c.nom,
      COALESCE(d.agence_id, NULL) AS agence_effective
    FROM chantiers c
    LEFT JOIN depots d
      ON d.id = c.depot_id
     AND d.entreprise_id = c.entreprise_id
    WHERE c.entreprise_id = :eid
    ORDER BY c.nom
  ");
  $stAll->execute([':eid' => $entrepriseId]);
} else {
  $stAll = $pdo->prepare("
    SELECT 
      c.id,
      c.nom,
      COALESCE(d.agence_id, NULL) AS agence_effective
    FROM utilisateur_chantiers uc
    JOIN chantiers c
      ON c.id = uc.chantier_id AND c.entreprise_id = :eid
    LEFT JOIN depots d
      ON d.id = c.depot_id AND d.entreprise_id = c.entreprise_id
    WHERE uc.utilisateur_id = :uid
    ORDER BY c.nom
  ");
  $stAll->execute([':eid' => $entrepriseId, ':uid' => $userId]);
}

$chantiersForJs = array_map(fn($r) => [
  'id'        => (int)$r['id'],
  'nom'       => (string)$r['nom'],
  'agence_id' => $r['agence_effective'] !== null ? (int)$r['agence_effective'] : null,
], $stAll->fetchAll(PDO::FETCH_ASSOC));
?>
<script>
  window.CHANTIERS_LIST = <?= json_encode($chantiersForJs, JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php


/* ======================================================
   Infos chantier sélectionné (inclut les champs trajet_*)
   ====================================================== */
$info = null;
if ($currentChantierId > 0) {
  $st = $pdo->prepare("
    SELECT
      c.id                AS chantier_id,
      c.nom               AS chantier_nom,
      c.depot_id,
      c.trajet_distance_m,
      c.trajet_duree_s,
      c.trajet_last_calc,
      d.nom               AS depot_nom
    FROM chantiers c
    JOIN depots d
      ON d.id = c.depot_id AND d.entreprise_id = c.entreprise_id
    WHERE c.id = :cid AND c.entreprise_id = :eid
  ");
  $st->execute([':cid' => $currentChantierId, ':eid' => $entrepriseId]);
  $info = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// km / min pré-connus en base (sinon ce sera refresh via API)
$km  = ($info && $info['trajet_distance_m'] !== null)
  ? round(((float)$info['trajet_distance_m']) / 1000, 1) : null;

$min = ($info && $info['trajet_duree_s'] !== null)
  ? (int)round(((float)$info['trajet_duree_s']) / 60) : null;

$depotNom = trim((string)($info['depot_nom'] ?? ''));

/* ==============================
   Agences (pour filtre)
   ============================== */
$sqlAg = "SELECT id, nom
          FROM agences
          WHERE entreprise_id = :e
            AND id > 0
          ORDER BY nom";

$st = $pdo->prepare($sqlAg);
$st->execute([':e' => $entrepriseId]);
$agences = $st->fetchAll(PDO::FETCH_ASSOC);



/* ==============================
   Absences planifiées de la semaine
   ============================== */
$planAbs = []; // $planAbs[$uid][$dateIso] = 'rtt' | 'conges' | 'maladie'
$sql = "SELECT utilisateur_id, date_jour, type
        FROM planning_affectations
        WHERE entreprise_id = :e
          AND date_jour >= :s
          AND date_jour <  :f
          AND type IN ('conges','maladie','rtt')";
$st = $pdo->prepare($sql);
$st->execute([':e' => $entrepriseId, ':s' => $startIso, ':f' => $endIso]);
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
  $planAbs[(int)$r['utilisateur_id']][$r['date_jour']] = $r['type'];
}

function badgePlanningAbs(?string $t): string
{
  if (!$t) return '';
  $map = [
    'conges'  => ['label' => 'Congés',  'class' => 'badge bg-warning text-dark'],
    'maladie' => ['label' => 'Maladie', 'class' => 'badge bg-danger'],
    'rtt'     => ['label' => 'RTT',     'class' => 'badge bg-primary'],
  ];
  if (!isset($map[$t])) return '';
  $m = $map[$t];
  return '<span class="' . $m['class'] . ' me-2">' . htmlspecialchars($m['label']) . '</span>';
}

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
$plannedDayMap = [];                 // [$uid][$date][$chantier_id] = true
$depotMap      = [];                 // [$uid][$date] = true
$depotIdMap    = [];                 // [$uid][$date] = depot_id (si dispo)

$cols = $pdo->query("SHOW COLUMNS FROM planning_affectations")->fetchAll(PDO::FETCH_COLUMN, 0);
$hasTypeCol  = in_array('type', $cols, true);
$hasDepotCol = in_array('depot_id', $cols, true);

if ($hasTypeCol || $hasDepotCol) {
  $stmt = $pdo->prepare("
    SELECT utilisateur_id, chantier_id, depot_id, type, date_jour
    FROM planning_affectations
    WHERE entreprise_id = :eid
      AND date_jour >= :d1 AND date_jour < :d2
  ");
} else {
  // compat : ancien schéma sans type/depot_id (chantier_id=0 signifie dépôt)
  $stmt = $pdo->prepare("
    SELECT utilisateur_id, chantier_id, date_jour
    FROM planning_affectations
    WHERE entreprise_id = :eid
      AND date_jour >= :d1 AND date_jour < :d2
  ");
}

$stmt->execute([':eid' => $entrepriseId, ':d1' => $startIso, ':d2' => $endIso]);

while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $uid = (int)$r['utilisateur_id'];
  $dj  = (string)$r['date_jour'];

  if ($hasTypeCol || $hasDepotCol) {
    $type = strtolower((string)($r['type'] ?? ''));
    if ($type === 'depot') {
      $depotMap[$uid][$dj]   = true;
      if ($hasDepotCol && isset($r['depot_id'])) $depotIdMap[$uid][$dj] = (int)$r['depot_id'];
      continue;
    }
    $cid = isset($r['chantier_id']) ? (int)$r['chantier_id'] : 0;
    if ($cid > 0) $plannedDayMap[$uid][$dj][$cid] = true;
  } else {
    // compat sans type: chantier_id=0 => dépôt
    $cid = isset($r['chantier_id']) ? (int)$r['chantier_id'] : 0;
    if ($cid === 0) {
      $depotMap[$uid][$dj] = true;
    } elseif ($cid > 0) {
      $plannedDayMap[$uid][$dj][$cid] = true;
    }
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
for ($i = 0; $i < 5; $i++) {
  $d = (clone $weekStart)->modify("+$i day");
  $days[] = ['iso' => $d->format('Y-m-d'), 'label' => ucfirst(strftime('%A %e %B', $d->getTimestamp())), 'dow' => (int)$d->format('N')];
}
$sat = (clone $weekStart)->modify('+5 day');
$sun = (clone $weekStart)->modify('+6 day');
$satIso = $sat->format('Y-m-d');
$sunIso = $sun->format('Y-m-d');
if (hasPlanningForDate($plannedDayMap, $satIso)) $days[] = ['iso' => $satIso, 'label' => ucfirst(strftime('%A %e %B', $sat->getTimestamp())), 'dow' => 6];
if (hasPlanningForDate($plannedDayMap, $sunIso)) $days[] = ['iso' => $sunIso, 'label' => ucfirst(strftime('%A %e %B', $sun->getTimestamp())), 'dow' => 7];

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
  $absMap[$uidR][$dj] = ['motif' => $r['motif'], 'heures' => ($r['heures'] === null ? null : (float)$r['heures'])];
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

/* ==============================
   ZONING conduite (modifiable)
   - ici on zone par durée aller simple (minutes)
   - ex: Z1 ≤ 10, Z2 ≤ 20, Z3 ≤ 30, Z4 au-delà
   ============================== */
$ZONES = [
  ['label' => 'Z1', 'max_min' => 10, 'class' => 'badge text-bg-success'],
  ['label' => 'Z2', 'max_min' => 20, 'class' => 'badge text-bg-primary'],
  ['label' => 'Z3', 'max_min' => 30, 'class' => 'badge text-bg-warning text-dark'],
  ['label' => 'Z4', 'max_min' => PHP_INT_MAX, 'class' => 'badge text-bg-danger'],
];

function computeZone(?int $minutes, array $ZONES): array
{
  if ($minutes === null) return ['label' => 'Z?', 'class' => 'badge text-bg-secondary'];
  foreach ($ZONES as $z) {
    if ($minutes <= (int)$z['max_min']) return ['label' => $z['label'], 'class' => $z['class']];
  }
  return ['label' => 'Z?', 'class' => 'badge text-bg-secondary'];
}

$zone = computeZone($min, $ZONES);

/* ==============================
   Tâche du jour (préaffichage)
   ============================== */
$dates = array_column($days, 'iso');
$tacheMap = [];
if ($dates) {
  $minD = min($dates);
  $maxD = max($dates);

  $cols = $pdo->query("SHOW COLUMNS FROM chantier_taches")->fetchAll(PDO::FETCH_COLUMN, 0);
  $hasShortcut = in_array('shortcut', $cols, true);
  $labelCol = null;
  foreach (['libelle', 'nom', 'titre', 'intitule', 'label'] as $c) {
    if (in_array($c, $cols, true)) {
      $labelCol = $c;
      break;
    }
  }
  $libExpr = "COALESCE(NULLIF(ct.`shortcut`, '')," . ($labelCol ? "NULLIF(ct.`$labelCol`, '')," : "") . "CAST(ct.`id` AS CHAR))";

  $ptCols = $pdo->query("SHOW COLUMNS FROM pointages_taches")->fetchAll(PDO::FETCH_COLUMN, 0);
  $hasUpdated = in_array('updated_at', $ptCols, true);

  if ($hasUpdated) {
    $sqlT = "
      SELECT pt.utilisateur_id, pt.date_jour, pt.tache_id, pt.chantier_id, pt.heures,
             $libExpr AS libelle
      FROM pointages_taches pt
      LEFT JOIN chantier_taches ct ON ct.id = pt.tache_id AND ct.entreprise_id = ?
      WHERE pt.entreprise_id = ?
        AND pt.date_jour BETWEEN ? AND ?
        AND NOT EXISTS (
          SELECT 1 FROM pointages_taches x
          WHERE x.entreprise_id = pt.entreprise_id
            AND x.utilisateur_id = pt.utilisateur_id
            AND x.date_jour      = pt.date_jour
            AND ((x.updated_at > pt.updated_at) OR (x.updated_at = pt.updated_at AND x.id > pt.id))
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
      LEFT JOIN chantier_taches ct ON ct.id = pt.tache_id AND ct.entreprise_id = ?
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
      // si chef: forcer un chantier sélectionné par défaut pour le titre
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
        <?= $isAdmin ? 'Pointage' : 'Pointage ' . htmlspecialchars($currentChantierName ?: '') ?>
      </h1>
    </div>

    <div class="col-12 col-md-4 text-md-end text-muted">
      Semaine <?= $weekNum ?>
    </div>
  </div>

  <!-- Toolbar CENTRÉE sous le titre -->
  <div class="d-flex justify-content-center">
    <div class="btn-toolbar flex-column align-items-center gap-2" id="filtersToolbar">

      <!-- Rangée AGENCE -->
      <div id="agenceFilters" class="btn-group" role="group" aria-label="Filtre agence">
        <button type="button" class="btn btn-outline-primary active" data-agence="0">Tous</button>
        <?php foreach ($agences as $a): ?>
          <button type="button" class="btn btn-outline-primary" data-agence="<?= (int)$a['id'] ?>">
            <?= htmlspecialchars($a['nom']) ?>
          </button>
        <?php endforeach; ?>
      </div>

      <!-- Rangée CHANTIERS -->

      <div id="chantierFilters"
        class="d-flex flex-wrap justify-content-center gap-2 my-2"
        role="group" aria-label="Chantiers"></div>
    </div>
  </div>

  <!-- Contrôles Camions -->
  <div id="camionControls" class="mb-2 d-flex align-items-center gap-2">
    <label for="camionCount" class="mb-0 small text-muted">Nombre de camions</label>
    <div class="input-group input-group-sm camion-stepper" style="width:140px">
      <button class="btn btn-outline-secondary" type="button" data-action="decr" aria-label="Diminuer">−</button>
      <input id="camionCount" type="text" class="form-control text-center"
        value="0" inputmode="numeric" pattern="[0-9]*" aria-label="Nombre de camions">
      <button class="btn btn-outline-secondary" type="button" data-action="incr" aria-label="Augmenter">+</button>
    </div>
  </div>

  <!-- Conduite (VERROUILLÉE sur le dépôt du chantier) -->
  <div class="d-flex align-items-center gap-2 my-3" id="conduiteWrap"
    data-chantier="<?= (int)$currentChantierId ?>"
    data-depot-id="<?= (int)($info['depot_id'] ?? 0) ?>"
    data-depot-name="<?= htmlspecialchars($depotNom) ?>"
    data-last="<?= htmlspecialchars((string)($info['trajet_last_calc'] ?? '')) ?>">

    <strong>Trajet :</strong>

    <?php if ($km !== null && $min !== null): ?>
      <!-- Zone d'abord -->
      <span id="trajetZone" class="<?= htmlspecialchars($zone['class']) ?>"><?= htmlspecialchars($zone['label']) ?></span>
      <!-- Distance + durée -->
      <span id="trajetKm" class="badge text-bg-primary"><?= htmlspecialchars(number_format($km, 1, ',', '')) ?> km</span>
      <span id="trajetMin" class="badge text-bg-secondary"><?= htmlspecialchars($min) ?> min</span>
      <!-- Phrase dépôt -->
      <?php if ($depotNom !== ''): ?>
        <span id="trajetDepotPhrase" class="text-muted">du dépôt de <?= htmlspecialchars($depotNom) ?></span>
      <?php endif; ?>
    <?php else: ?>
      <span id="trajetWarn" class="badge text-bg-warning">Adresse/dépôt manquants ou non calculés</span>
      <span id="trajetZone" class="badge text-bg-secondary d-none">Z?</span>
      <span id="trajetKm" class="badge text-bg-primary d-none"></span>
      <span id="trajetMin" class="badge text-bg-secondary d-none"></span>
      <span id="trajetDepotPhrase" class="text-muted d-none"></span>
    <?php endif; ?>
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
              $planningType = $planAbs[$uid][$dateIso] ?? null; // 'rtt'|'conges'|'maladie'|null

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

              $labelMap = [
                'conges_payes'       => 'Congés payés',
                'conges_intemperies' => 'Intempéries',
                'maladie'            => 'Maladie',
                'justifie'           => 'Justifié',
                'injustifie'         => 'Injustifié',
              ];

              $absLabel = $isAbsent ? ($labelMap[strtolower((string)$absMotif)] ?? ucfirst((string)$absMotif)) : '';
              $presentDisabledByPlan = in_array($planningType, ['conges', 'maladie', 'rtt'], true);

              $hasSavedState = ($hDone !== null) || $aDone || $rDone || $isAbsent;

              /* --- Calculs avant rendu --- */
              $presentIsActive = ($hDone !== null && $hDone > 0);
              $presentLabel    = $presentIsActive ? ('Présent ' . fmtHM((float)$hDone)) : 'Présent 8h15';

              $absText  = 'Abs.';
              if ($isAbsent) {
                $absText = trim($absLabel);
                if ($absHeures !== null) $absText .= ' ' . str_replace('.', ',', (string)$absHeures) . ' h';
                if ($absText === '') $absText = 'Abs.'; // sécurité
              } elseif ($planningType) {
                // pas d'absence saisie : reprendre le planning
                $absText = ['rtt' => 'RTT', 'conges' => 'Congés', 'maladie' => 'Maladie'][$planningType] ?? 'Abs.';
              }

              // --- Classe visuelle du bouton ---
              $absClass = $isAbsent ? 'btn-danger' : 'btn-outline-danger';

              // --- Mode plein ? (masque Présent/Conduite/Tâche) ---
              // Priorité à l'absence réellement pointée ; sinon, au planning
              $fullType = null; // 'maladie' | 'rtt' | 'conges' | null
              if ($isAbsent) {
                $motifLow = strtolower((string)$absMotif);
                if (in_array($motifLow, ['maladie', 'rtt'], true)) {
                  $fullType = $motifLow;      // plein
                } elseif ($motifLow === 'conges_payes') {
                  $fullType = 'conges';       // plein
                }
                // ⚠️ PAS de plein pour 'conges_intemperies'
              }

              $fullLabel = $fullType ? [
                'conges'  => 'Congés',
                'maladie' => 'Maladie',
                'rtt'     => 'RTT',
              ][$fullType] : '';

              $isDepot = !empty($depotMap[$uid][$dateIso]);
              $depotId = isset($depotIdMap[$uid][$dateIso]) ? (int)$depotIdMap[$uid][$dateIso] : 0;

              // Priorité à 'depot' si c'est planifié ; sinon garde l’absence éventuelle (conges/maladie/rtt)
              $cellPlanType = $isDepot ? 'depot' : ($planningType ?? '');

            ?>

              <td class="tl-cell text-center"
                data-date="<?= htmlspecialchars($dateIso) ?>"
                data-day-label="<?= htmlspecialchars($d['label']) ?>"
                data-planned-chantiers-day="<?= htmlspecialchars($plannedIdsForDay) ?>"
                data-planning-type="<?= htmlspecialchars($cellPlanType) ?>"
                <?= $isDepot ? 'data-depot-id="' . (int)$depotId . '"' : '' ?>>


                <?php
                // ---- Données "tâche" DÉFINIES AVANT TOUT ----
                $tInfo   = $tacheMap[$uid][$dateIso] ?? null;
                $tId     = (int)($tInfo['id'] ?? 0);
                $tLib    = (string)($tInfo['libelle'] ?? '');
                $tHeures = isset($tInfo['heures']) ? (float)$tInfo['heures'] : ($hDone ?? null);

                // ---- Détermination du plein-écran ----
                $fullType = null; // 'maladie' | 'rtt' | 'conges' | null
                if ($isAbsent) {
                  $motifLow = strtolower((string)$absMotif);
                  if (in_array($motifLow, ['maladie', 'rtt'], true)) {
                    $fullType = $motifLow;
                  } elseif ($motifLow === 'conges_payes') {
                    $fullType = 'conges';
                  }
                  // conges_intemperies => JAMAIS plein-écran
                } elseif (in_array(($planningType ?? ''), ['conges', 'maladie', 'rtt'], true)) {
                  $fullType = $planningType;
                }

                $fullLabel = $fullType ? ['conges' => 'Congés', 'maladie' => 'Maladie', 'rtt' => 'RTT'][$fullType] : '';
                ?>

                <?php if ($fullType): ?>
                  <?php
                  $reasonForModal = $isAbsent
                    ? strtolower((string)$absMotif)
                    : ($fullType === 'rtt' ? 'rtt' : ($fullType === 'maladie' ? 'maladie' : 'conges_payes'));
                  $hoursForModal = $isAbsent ? ($absHeures !== null ? (float)$absHeures : 8.25) : 8.25;
                  ?>
                  <div class="tl-absence-full <?= htmlspecialchars($fullType) ?>"
                    role="button"
                    title="Modifier l'absence"
                    data-click-absence
                    data-reason="<?= htmlspecialchars($reasonForModal) ?>"
                    data-hours="<?= htmlspecialchars(number_format((float)$hoursForModal, 2, '.', '')) ?>">
                    <?= htmlspecialchars($fullLabel) ?>
                  </div>

                <?php elseif ($dow >= 6 && !$hasPlanning && !$hasSavedState): ?>
                  <div class="text-muted">×</div>

                <?php else: ?>
                  <!-- Mode normal -->
                  <span class="tl-dot <?= $isAbsent ? 'absent' : ($presentIsActive ? 'present' : (!empty($plannedIdsForDay) ? 'plan' : '')) ?>"></span>

                  <?= badgePlanningAbs($planningType) ?>

                  <div class="tl-actions mb-2 d-flex flex-wrap gap-1 justify-content-center">
                    <button class="btn btn-sm present-btn <?= $presentIsActive ? 'btn-success' : 'btn-outline-success' ?>"
                      data-hours="8.25" <?= ($isAbsent || in_array($planningType, ['conges', 'maladie', 'rtt'], true)) ? 'disabled' : '' ?>>
                      <?= htmlspecialchars($presentLabel) ?>
                    </button>

                    <?php
                    $motif   = strtolower((string)$absMotif);
                    $hideA   = $isAbsent && $motif !== 'conges_intemperies';
                    $absText = 'Abs.';
                    if ($isAbsent) {
                      $absText = trim($absLabel);
                      if ($absHeures !== null) $absText .= ' ' . str_replace('.', ',', (string)$absHeures) . ' h';
                      if ($absText === '') $absText = 'Abs.';
                    } elseif ($planningType) {
                      $absText = ['rtt' => 'RTT', 'conges' => 'Congés', 'maladie' => 'Maladie'][$planningType] ?? 'Abs.';
                    }
                    $absClass = $isAbsent ? 'btn-danger' : 'btn-outline-danger';
                    $absReasonForModal = $isAbsent
                      ? strtolower((string)$absMotif)
                      : ($planningType === 'rtt' ? 'rtt' : ($planningType === 'maladie' ? 'maladie' : ($planningType === 'conges' ? 'conges_payes' : 'injustifie')));
                    $absHoursForModal  = $isAbsent ? ($absHeures !== null ? (float)$absHeures : 8.25) : 8.25;
                    ?>

                    <button class="btn btn-sm conduite-btn <?= $aDone ? 'btn-primary' : 'btn-outline-primary' ?><?= $hideA ? ' d-none' : '' ?>"
                      data-type="A" <?= $hideA ? 'disabled' : '' ?>>A</button>
                    <button class="btn btn-sm conduite-btn <?= $rDone ? 'btn-success' : 'btn-outline-success' ?><?= $isAbsent ? ' d-none' : '' ?>"
                      data-type="R" <?= $isAbsent ? 'disabled' : '' ?>>R</button>

                    <button type="button"
                      class="btn btn-sm <?= $absClass ?> absence-btn"
                      data-reason="<?= htmlspecialchars($absReasonForModal) ?>"
                      data-hours="<?= htmlspecialchars(number_format((float)$absHoursForModal, 2, '.', '')) ?>"
                      data-has-absence="<?= $isAbsent ? '1' : '0' ?>">
                      <?= htmlspecialchars($absText) ?>
                    </button>
                  </div>

                  <!-- Slot Tâche -->
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
                        <div class="small text-muted mt-1"><?= htmlspecialchars(number_format((float)$tHeures, 2, ',', '')) ?> h</div>
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
                  <input type="radio" class="form-check-input me-2" name="reason" value="conges_payes" checked>
                  Congés payés
                </label>
                <label class="btn btn-outline-secondary btn-sm text-start">
                  <input type="radio" class="form-check-input me-2" name="reason" value="conges_intemperies">
                  Congés intempéries
                </label>
                <label class="btn btn-outline-secondary btn-sm text-start">
                  <input type="radio" class="form-check-input me-2" name="reason" value="maladie">
                  Maladie
                </label>
                <label class="btn btn-outline-secondary btn-sm text-start">
                  <input type="radio" class="form-check-input me-2" name="reason" value="justifie">
                  Justifié (décès…)
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
          <button type="button" class="btn btn-outline-danger btn-sm me-auto d-none" id="absenceDelete">
            Supprimer l'absence
          </button>

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
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>