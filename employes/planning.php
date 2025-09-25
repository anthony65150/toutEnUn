<?php
require_once __DIR__ . '/../config/init.php';

if (
  !isset($_SESSION['utilisateurs']) ||
  (($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur')
) {
  header('Location: /connexion.php');
  exit;
}

$page = 'planning';

/** ====== Multi-entreprise : source fiable ====== */
$entrepriseId = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);
if ($entrepriseId <= 0) {
  header('Location: /connexion.php');
  exit;
}

/* ----- Semaine affichée ou courante ----- */
if (isset($_GET['year'], $_GET['week'])) {
  $viewYear = (int) $_GET['year'];
  $viewWeek = (int) $_GET['week'];
} else {
  $now      = new DateTime();
  $viewYear = (int) $now->format('o'); // année ISO
  $viewWeek = (int) $now->format('W'); // semaine ISO
}

/* ----- Lundi de la semaine affichée + bornes ----- */
$start = new DateTime();
$start->setISODate($viewYear, $viewWeek);     // lundi
$end   = (clone $start)->modify('+6 day');    // dimanche

/* ----- Nav ----- */
$prevMonday = (clone $start)->modify('-7 day')->format('Y-m-d');
$nextMonday = (clone $start)->modify('+7 day')->format('Y-m-d');

/* ----- Semaine courante (affichage à droite) ----- */
$today       = new DateTime();
$currentYear = (int) $today->format('o');
$currentWeek = (int) $today->format('W');
$todayIso    = (new DateTime('today'))->format('Y-m-d');

/* ----- Libellés FR & jours ----- */
$jours = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
$mois  = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
$monthLabel = $mois[(int)$start->format('n') - 1] . ' ' . $start->format('Y');

$days = [];
for ($i = 0; $i < 7; $i++) {
  $d = (clone $start)->modify("+$i day");
  $days[] = [
    'iso'   => $d->format('Y-m-d'),
    'label' => $jours[$i] . ' ' . (int)$d->format('j') . ' ' . $mois[(int)$d->format('n') - 1],
    'dow'   => (int)$d->format('N')
  ];
}

/** @var PDO $pdo */

/* ====== Données ====== */

/* Chantiers (palette) */
$sqlCh = "SELECT id, nom, responsable_id
          FROM chantiers
          WHERE entreprise_id = :e
          ORDER BY nom";
$st = $pdo->prepare($sqlCh);
$st->execute([':e' => $entrepriseId]);
$chantiers = $st->fetchAll(PDO::FETCH_ASSOC);

/* Dépôts (palette) — pour les pastilles Dépôt */
$sqlDep = "SELECT id, nom
           FROM depots
           WHERE entreprise_id = :e
           ORDER BY nom";
$st = $pdo->prepare($sqlDep);
$st->execute([':e' => $entrepriseId]);
$depots = $st->fetchAll(PDO::FETCH_ASSOC);

/* Agences (pour le filtre) */
$sqlAg = "SELECT id, nom
          FROM agences
          WHERE entreprise_id = :e
          ORDER BY nom";
$st = $pdo->prepare($sqlAg);
$st->execute([':e' => $entrepriseId]);
$agences = $st->fetchAll(PDO::FETCH_ASSOC);

/* Maps rapides pour noms (affichage) */
$chantierById = [];
foreach ($chantiers as $c) $chantierById[(int)$c['id']] = $c['nom'];
$depotById = [];
foreach ($depots as $d) $depotById[(int)$d['id']] = $d['nom'];

/* Employés : tout le monde SAUF administrateur, avec agence */
$employes = [];

$sqlEmp = "SELECT u.id,
                  CONCAT(u.nom, ' ', u.prenom) AS nom,
                  u.fonction AS role,
                  u.agence_id,
                  a.nom AS agence_nom
           FROM utilisateurs u
           LEFT JOIN agences a
                  ON a.id = u.agence_id
                 AND a.entreprise_id = :e2
           WHERE u.entreprise_id = :e1
             AND u.fonction <> 'administrateur'
           ORDER BY (a.nom IS NULL), a.nom, u.nom, u.prenom";

$st = $pdo->prepare($sqlEmp);
$st->execute([':e1' => $entrepriseId, ':e2' => $entrepriseId]);
$employes = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* Détection schéma planning_affectations : type / depot_id existent ? */
$hasTypeCol  = false;
$hasDepotCol = false;
try {
  $rs = $pdo->query("SHOW COLUMNS FROM planning_affectations LIKE 'type'");
  $hasTypeCol = $rs && $rs->rowCount() > 0;
} catch (Throwable $e) {
}
try {
  $rs = $pdo->query("SHOW COLUMNS FROM planning_affectations LIKE 'depot_id'");
  $hasDepotCol = $rs && $rs->rowCount() > 0;
} catch (Throwable $e) {
}

/* Affectations semaine (bornées par entreprise) */
if ($hasTypeCol || $hasDepotCol) {
  // Schéma moderne
  $sqlAff = "SELECT utilisateur_id, chantier_id, depot_id, type, date_jour, is_active
             FROM planning_affectations
             WHERE date_jour BETWEEN :s AND :e
               AND entreprise_id = :eid";
} else {
  // Compat : pas de type/depot_id
  $sqlAff = "SELECT utilisateur_id, chantier_id, date_jour, is_active
             FROM planning_affectations
             WHERE date_jour BETWEEN :s AND :e
               AND entreprise_id = :eid";
}
$st = $pdo->prepare($sqlAff);
$st->execute([
  ':s'   => $start->format('Y-m-d'),
  ':e'   => $end->format('Y-m-d'),
  ':eid' => $entrepriseId
]);
$affectRows = $st->fetchAll(PDO::FETCH_ASSOC);

/* Map affectations normalisée */
$affects = [];
foreach ($affectRows as $r) {
  $uid = (int)$r['utilisateur_id'];

  if ($hasTypeCol || $hasDepotCol) {
    $type = $r['type'] ?? 'chantier';
    $affects[$uid][$r['date_jour']] = [
      'type'        => $type,
      'chantier_id' => $r['chantier_id'] !== null ? (int)$r['chantier_id'] : null,
      'depot_id'    => $r['depot_id'] !== null ? (int)$r['depot_id'] : null,
      'is_active'   => (int)($r['is_active'] ?? 0)
    ];
  } else {
    // Compat : on considère chantier_id=0 comme "dépôt"
    $cid  = isset($r['chantier_id']) ? (int)$r['chantier_id'] : null;
    $type = ($cid === 0 ? 'depot' : 'chantier');
    $affects[$uid][$r['date_jour']] = [
      'type'        => $type,
      'chantier_id' => $cid && $cid > 0 ? $cid : null,
      'depot_id'    => null, // inconnu en compat
      'is_active'   => (int)($r['is_active'] ?? 0)
    ];
  }
}

/* CSRF (si besoin) */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

require __DIR__ . '/../templates/header.php';
require __DIR__ . '/../templates/navigation/navigation.php';

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
    case 'interim':
      return '<span class="badge bg-warning text-dark">Employé</span>';
    default:
      return '<span class="badge bg-secondary">Autre</span>';
  }
}
?>

<div class="container mt-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <a href="javascript:history.back()" class="btn btn-outline-secondary">← Retour</a>
    <h1 class="m-0 text-center flex-grow-1">Planning</h1>
    <div style="width:120px"></div>
  </div>

  <?php if (!empty($agences)): ?>
    <div class="d-flex justify-content-center mb-3">
      <div id="agenceFilters" class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-primary" data-agence="all">Tous</button>
        <button type="button" class="btn btn-outline-secondary" data-agence="0">Sans agence</button>
        <?php foreach ($agences as $ag): ?>
          <button type="button"
            class="btn btn-outline-secondary"
            data-agence="<?= (int)$ag['id'] ?>">
            <?= htmlspecialchars($ag['nom']) ?>
          </button>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <input type="text" id="searchInput" class="form-control mb-3" placeholder="Rechercher un employé..." autocomplete="off" />

  <!-- Palette chantiers -->
  <div class="mb-2 d-flex flex-wrap gap-2" id="palette-chantiers">
    <?php foreach ($chantiers as $c):
      $h  = (($c['id'] * 47) % 360);
      $bg = "hsl($h, 70%, 45%)"; ?>
      <div class="chip"
        draggable="true"
        data-type="chantier"
        data-chantier-id="<?= (int)$c['id'] ?>"
        data-chip-color="<?= htmlspecialchars($bg) ?>"
        style="background:<?= $bg ?>;">
        <span class="dot"></span><?= htmlspecialchars($c['nom']) ?>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Palette dépôts -->
  <?php if (!empty($depots)): ?>
    <div class="mb-2 d-flex flex-wrap gap-2" id="palette-depots">
      <div class="chip"
        draggable="true"
        data-type="depot"
        data-depot-id="0"
        style="background:#cff4fc;color:#084c61;border:1px solid rgba(0,0,0,.08)">
        <span class="dot" style="background:#0dcaf0"></span>Dépôt
      </div>
    </div>
  <?php endif; ?>

  <!-- Palette absences -->
  <div class="mb-3 d-flex flex-wrap gap-2" id="palette-absences">
    <div class="chip"
      draggable="true"
      data-type="absence"
      data-absence="conges"
      style="background:#fde68a;color:#7a5c00;border:1px solid rgba(0,0,0,.08)">
      <span class="dot" style="background:#f59e0b"></span>Congés
    </div>
    <div class="chip"
      draggable="true"
      data-type="absence"
      data-absence="maladie"
      style="background:#fecaca;color:#7a0b0b;border:1px solid rgba(0,0,0,.08)">
      <span class="dot" style="background:#dc2626"></span>Maladie
    </div>
    <div class="chip"
      draggable="true"
      data-type="absence"
      data-absence="rtt"
      style="background:#bfdbfe;color:#0b4a7a;border:1px solid rgba(0,0,0,.08)">
      <span class="dot" style="background:#2563eb"></span>RTT
    </div>
  </div>

  <!-- En-têtes mois & semaine + nav -->
  <div id="weekNav"
    class="d-flex align-items-center justify-content-between my-3"
    data-week="<?= (int)$viewWeek ?>" data-year="<?= (int)$viewYear ?>">
    <div class="fw-semibold"><?= htmlspecialchars(ucfirst($monthLabel)) ?></div>

    <div class="d-flex justify-content-center flex-grow-1 gap-2">
      <button type="button" class="btn btn-outline-secondary" data-week-shift="-1">← Semaine -1</button>
      <button type="button" class="btn btn-outline-secondary" data-week-shift="0">Cette semaine</button>
      <button type="button" class="btn btn-outline-secondary" data-week-shift="1">Semaine +1 →</button>
    </div>

    <div class="ms-3">
      <span>Semaine <?= (int)$viewWeek ?></span>
    </div>
  </div>

  <!-- Tableau -->
  <div class="table-responsive">
    <table class="table table-bordered table-hover table-striped align-middle">
      <thead class="table-dark">
        <tr>
          <th style="min-width:220px;">Employés</th>
          <?php foreach ($days as $d): ?>
            <th class="text-center <?= ($d['iso'] === $todayIso ? 'table-primary' : '') ?>">
              <?= htmlspecialchars(ucfirst($d['label'])) ?>
            </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody id="gridBody">
        <?php foreach ($employes as $emp): ?>
          <tr data-emp-id="<?= (int)$emp['id'] ?>"
            data-agence-id="<?= (int)($emp['agence_id'] ?? 0) ?>">

            <td class="fw-semibold">
              <?= htmlspecialchars($emp['nom']) ?>
              <?= badgeRole($emp['role']) ?>
            </td>

            <?php foreach ($days as $d):
              $isWeekend = ($d['dow'] >= 6);
              $aff       = $affects[$emp['id']][$d['iso']] ?? null;
              $isActive  = (int)($aff['is_active'] ?? 0);

              /* Construire le chip si on a une affectation */
              $chip = null;
              if ($aff) {
                $type = $aff['type'] ?? 'chantier';

                if ($type === 'depot') {
                  $did = $aff['depot_id'] ?? null;
                  $nomDepot = ($did !== null && isset($depotById[$did])) ? ('Dépôt — ' . $depotById[$did]) : 'Dépôt';
                  $chip = [
                    'nom'   => $nomDepot,
                    'color' => '#cff4fc',
                    'id'    => $did ?? 0,
                    'type'  => 'depot'
                  ];
                } elseif (in_array($type, ['conges', 'maladie', 'rtt'], true)) {
                  $labels = ['conges' => 'Congés', 'maladie' => 'Maladie', 'rtt' => 'RTT'];
                  $colors = ['conges' => '#fde68a', 'maladie' => '#fecaca', 'rtt' => '#bfdbfe'];
                  $chip = [
                    'nom'   => $labels[$type],
                    'color' => $colors[$type],
                    'id'    => null,
                    'type'  => $type
                  ];
                } else {
                  $cid = $aff['chantier_id'] ?? null;
                  if ($cid !== null && isset($chantierById[$cid])) {
                    $h = (((int)$cid * 47) % 360);
                    $chip = [
                      'nom'   => $chantierById[$cid],
                      'color' => "hsl($h, 70%, 45%)",
                      'id'    => (int)$cid,
                      'type'  => 'chantier'
                    ];
                  }
                }
              }
            ?>
              <td>
                <?php
                // Prépare les data-* spécifiques selon le type
                $extraData = '';
                if ($chip && $chip['type'] === 'depot') {
                  $extraData = 'data-depot-id="' . (int)$chip['id'] . '"';
                } elseif ($chip && in_array($chip['type'], ['conges', 'maladie', 'rtt'], true)) {
                  $extraData = 'data-absence="' . htmlspecialchars($chip['type']) . '"';
                } elseif ($chip && $chip['type'] === 'chantier') {
                  $extraData = 'data-chantier-id="' . (int)$chip['id'] . '"';
                }
                ?>

                <?php if ($chip): ?>
                  <!-- Affecté -->
                  <div class="cell-drop has-chip"
                    data-date="<?= htmlspecialchars($d['iso']) ?>"
                    data-emp="<?= (int)$emp['id'] ?>">
                    <span class="assign-chip<?= ($chip['type'] === 'depot' ? ' assign-chip-depot' : '') ?>"
                      style="background: <?= htmlspecialchars($chip['color']) ?>;"
                      data-type="<?= htmlspecialchars($chip['type']) ?>"
                      <?= $extraData ?>>
                      <?= htmlspecialchars($chip['nom']) ?>
                      <span class="x" title="Retirer">×</span>
                    </span>
                  </div>

                <?php elseif ($isActive): ?>
                  <!-- Jour activé mais sans affectation -->
                  <div class="cell-drop"
                    data-date="<?= htmlspecialchars($d['iso']) ?>"
                    data-emp="<?= (int)$emp['id'] ?>"></div>

                <?php elseif ($isWeekend): ?>
                  <!-- Week-end inactif -->
                  <div class="cell-off"
                    data-date="<?= htmlspecialchars($d['iso']) ?>"
                    data-emp="<?= (int)$emp['id'] ?>">
                    <button type="button" class="wkx"
                      title="Jour non travaillé — activer exceptionnellement">×</button>
                  </div>

                <?php else: ?>
                  <!-- Semaine sans affectation (actif par défaut) -->
                  <div class="cell-drop"
                    data-date="<?= htmlspecialchars($d['iso']) ?>"
                    data-emp="<?= (int)$emp['id'] ?>"></div>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <script>
    // Endpoints
    window.PLANNING_DATE_START = <?= json_encode($start->format('Y-m-d')) ?>;
    window.API_MOVE = "/employes/api/moveAffectation.php";
    window.API_DELETE = "/employes/api/deleteAffectation.php";
  </script>

  <script src="/employes/js/planning.js"></script>

  <?php require __DIR__ . '/../templates/footer.php'; ?>