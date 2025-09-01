<?php
require_once __DIR__ . '/../config/init.php';

if (!isset($_SESSION['utilisateurs']) || (($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur')) {
    header('Location: /connexion.php');
    exit;
}

$page = 'planning';
$entrepriseId = (int)($_SESSION['entreprise_id'] ?? 0);

/* ====== Semaine affichée ====== */
$today = isset($_GET['date']) ? date('Y-m-d', strtotime($_GET['date'])) : date('Y-m-d');
$dt = new DateTime($today);
$dt->setTime(0, 0, 0);
if ($dt->format('N') != 1) {
    $dt->modify('last monday');
}
$start = clone $dt;                 // lundi
$end   = (clone $start)->modify('+6 day');
$weekNumber = (int)$start->format('W');
/* Liens de navigation semaine */
$prevMonday = (clone $start)->modify('-7 day')->format('Y-m-d');
$nextMonday = (clone $start)->modify('+7 day')->format('Y-m-d');

$todayMon = new DateTime('today');
if ($todayMon->format('N') != 1) {
    $todayMon->modify('last monday');
}
$todayMonday = $todayMon->format('Y-m-d');


/* Libellés FR */
$jours = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
$mois  = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
$monthLabel = $mois[(int)$start->format('n') - 1] . ' ' . $start->format('Y');

/* Jours (1..7) */
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
/* Chantiers (palette) — tolérant si chef_id absent */
$hasChefId = false;
try {
    $chk = $pdo->query("SHOW COLUMNS FROM chantiers LIKE 'chef_id'");
    $hasChefId = $chk && $chk->rowCount() > 0;
} catch (Throwable $e) {
    $hasChefId = false;
}

$sqlCh = "SELECT id, nom, responsable_id FROM chantiers";
$paramsCh = [];
if ($entrepriseId) {
    $sqlCh .= " WHERE entreprise_id = :e";
    $paramsCh[':e'] = $entrepriseId;
}
$sqlCh .= " ORDER BY nom";
$st = $pdo->prepare($sqlCh);
$st->execute($paramsCh);
$chantiers = $st->fetchAll(PDO::FETCH_ASSOC);

// Map : [responsable_id => chantier_id] (un seul chantier préféré si plusieurs : on gardera le plus petit id dans l’API)
$respDefault = [];
foreach ($chantiers as $c) {
    if (!empty($c['responsable_id'])) {
        $respDefault[(int)$c['responsable_id']] = (int)$c['id'];
    }
}

/* Employés */
$sqlEmp = "SELECT id, CONCAT(prenom,' ',nom) AS nom, fonction AS role
           FROM utilisateurs
           WHERE fonction IN ('employe','chef','interim')";
$paramsEmp = [];
if ($entrepriseId) {
    $sqlEmp .= " AND entreprise_id=:e";
    $paramsEmp[':e'] = $entrepriseId;
}
$sqlEmp .= " ORDER BY nom";
$st = $pdo->prepare($sqlEmp);
$st->execute($paramsEmp);
$employes = $st->fetchAll(PDO::FETCH_ASSOC);

/* Affectations semaine */
$sqlAff = "SELECT utilisateur_id, chantier_id, date_jour
           FROM planning_affectations
           WHERE date_jour BETWEEN :s AND :e
             AND entreprise_id = :eid";
$st = $pdo->prepare($sqlAff);
$st->execute([':s' => $start->format('Y-m-d'), ':e' => $end->format('Y-m-d'), ':eid' => $entrepriseId]);
$affectRows = $st->fetchAll(PDO::FETCH_ASSOC);

/* Map affectations */
$affects = [];
foreach ($affectRows as $r) {
    $uid = (int)$r['utilisateur_id'];
    $affects[$uid][$r['date_jour']] = $r['chantier_id'] !== null ? (int)$r['chantier_id'] : null;
}

/* CSRF (si besoin) */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

require __DIR__ . '/../templates/header.php';
require __DIR__ . '/../templates/navigation/navigation.php';
?>
<style>
  .chip{display:inline-flex;align-items:center;gap:.5rem;padding:.35rem .6rem;border-radius:999px;color:#fff;font-weight:600;cursor:grab;user-select:none}
  .chip .dot{width:.6rem;height:.6rem;border-radius:50%;background:rgba(255,255,255,.8)}

  .table-sticky thead th{position:sticky;top:0;background:#fff;z-index:2}

  /* Cases */
  .cell-drop,.cell-off{min-height:48px;border:1px dashed #e2e8f0;border-radius:.5rem;position:relative;padding:0}
  .cell-drop.dragover{border-color:#0d6efd;background:#eef6ff}
  .cell-drop.has-chip{border-style:solid}
  .cell-off{background:#fafafa}
  .wkx{position:absolute;top:4px;right:6px;line-height:1;font-weight:700;font-size:14px;color:#9aa3af;background:transparent;border:0;cursor:pointer;padding:0}
  .wkx:hover{color:#6b7280}

  /* Pastille plein écran */
  .assign-chip{display:flex;width:100%;min-height:48px;border-radius:.5rem;align-items:center;justify-content:space-between;padding:0 .75rem;color:#fff;font-weight:600}
  .assign-chip .x{cursor:pointer;font-weight:800;opacity:.9;margin-left:.5rem}
  .assign-chip .x:hover{opacity:1}
</style>


<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="javascript:history.back()" class="btn btn-outline-secondary">← Retour</a>
        <h1 class="m-0 text-center flex-grow-1">Planning</h1>
        <div style="width:120px"></div>
    </div>

    <input type="text" id="searchInput" class="form-control mb-3" placeholder="Rechercher un employé..." autocomplete="off" />

    <!-- Palette chantiers -->
    <div class="mb-3 d-flex flex-wrap gap-2" id="palette">
        <?php foreach ($chantiers as $c):
            $h = (($c['id'] * 47) % 360);
            $bg = "hsl($h, 70%, 45%)"; ?>
            <div class="chip" draggable="true"
                data-chantier-id="<?= (int)$c['id'] ?>"
                data-chip-color="<?= htmlspecialchars($bg) ?>"
                style="background:<?= $bg ?>;">
                <span class="dot"></span><?= htmlspecialchars($c['nom']) ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- En-têtes mois & semaine + nav + bouton -->
    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <div class="fw-semibold"><?= htmlspecialchars(ucfirst($monthLabel)) ?></div>

        <div class="d-flex align-items-center gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="?date=<?= htmlspecialchars($prevMonday) ?>">← Semaine -1</a>
            <a class="btn btn-sm btn-outline-secondary" href="?date=<?= htmlspecialchars($todayMonday) ?>">Cette semaine</a>
            <a class="btn btn-sm btn-outline-secondary" href="?date=<?= htmlspecialchars($nextMonday) ?>">Semaine +1 →</a>

            <span class="badge text-bg-light ms-2">Semaine <?= $weekNumber ?></span>
            <button id="btnPrefillChefs" class="btn btn-sm btn-outline-primary">Préremplir semaine (chefs)</button>
        </div>
    </div>


    <!-- Tableau -->
    <div class="table-responsive table-sticky">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th style="min-width:220px;">Employés</th>
                    <?php foreach ($days as $d): ?>
                        <th class="text-center"><?= htmlspecialchars(ucfirst($d['label'])) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody id="gridBody">
                <?php foreach ($employes as $emp): ?>
                    <tr data-emp-id="<?= (int)$emp['id'] ?>">
                        <td class="fw-semibold">
                            <?= htmlspecialchars($emp['nom']) ?>
                            <span class="badge text-bg-secondary ms-1"><?= htmlspecialchars($emp['role']) ?></span>
                        </td>

                        <?php foreach ($days as $d):
                            $isWeekend = ($d['dow'] >= 6); // 6=samedi, 7=dimanche
                            $chip = null;
                            if (!$isWeekend) {
                                $cid = $affects[$emp['id']][$d['iso']] ?? null;

                                // si pas d'affectation, et que l'employé est 'chef', on le place sur son chantier dont il est responsable
                                if ($cid === null && ($emp['role'] ?? '') === 'chef') {
                                    $cid = $respDefault[(int)$emp['id']] ?? null;
                                }

                                if ($cid !== null) {
                                    $c = array_values(array_filter($chantiers, fn($x) => (int)$x['id'] === $cid))[0] ?? null;
                                    if ($c) {
                                        $h = (($cid * 47) % 360);
                                        $chip = ['nom' => $c['nom'], 'color' => "hsl($h, 70%, 45%)", 'id' => $cid];
                                    }
                                }
                            }
                        ?>
                            <td>
                                <?php if ($isWeekend): ?>
                                    <div class="cell-off" data-date="<?= htmlspecialchars($d['iso']) ?>" data-emp="<?= (int)$emp['id'] ?>">
                                        <button type="button" class="wkx" title="Jour non travaillé — activer exceptionnellement">×</button>
                                    </div>
                                <?php else: ?>
                                    <div class="cell-drop" data-date="<?= htmlspecialchars($d['iso']) ?>" data-emp="<?= (int)$emp['id'] ?>">
                                        <?php if ($chip): ?>
                                            <span class="assign-chip" style="background: <?= htmlspecialchars($chip['color']) ?>;"
                                                data-chantier-id="<?= (int)$chip['id'] ?>">
                                                <?= htmlspecialchars($chip['nom']) ?>
                                                <span class="x" title="Retirer">×</span>
                                            </span>
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
</div>

<script>
    window.PLANNING_DATE_START = <?= json_encode($start->format('Y-m-d')) ?>;
    window.API_MOVE = "/employes/api/moveAffectation.php";
    window.API_DELETE = "/employes/api/deleteAffectation.php";
</script>
<script src="/employes/js/planning.js"></script>

<script>
/* ===== Utilitaires drag-paint ===== */
let dragPaint = null; // {id,label,color,painted:Set}

function startDragPaint(id,color,label){
  dragPaint = { id:Number(id), color:(color||''), label:(label||'Chantier'), painted:new Set() };
}
function endDragPaint(){ dragPaint = null; }

function makeChipHTML(label,color,id){
  const span = document.createElement('span');
  span.className = 'assign-chip';
  span.style.background = color || '#334155';
  span.dataset.chantierId = id;
  span.innerHTML = `${label} <span class="x" title="Retirer">×</span>`;
  return span;
}

function bindChipEvents(chip){
  // retirer
  chip.querySelector('.x')?.addEventListener('click', () => {
    const cell = chip.closest('.cell-drop');
    const empId = cell.dataset.emp, date = cell.dataset.date;
    fetch(window.API_DELETE,{
      method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:new URLSearchParams({emp_id:empId,date})
    }).then(r=>r.json()).then(d=>{
      if(!d.ok) throw new Error(d.message||'Erreur');
      chip.remove(); cell.classList.remove('has-chip');
    }).catch(console.error);
  });

  // rendre “source de peinture”
  chip.setAttribute('draggable','true');
  chip.addEventListener('dragstart', e=>{
    const id = Number(chip.dataset.chantierId);
    const color = chip.style.background || '';
    const tmp = chip.cloneNode(true); tmp.querySelector('.x')?.remove();
    const label = tmp.textContent.trim();
    e.dataTransfer.setData('chantier_id', String(id));
    e.dataTransfer.setData('color', color);
    e.dataTransfer.setData('label', label);
    e.dataTransfer.effectAllowed = 'copyMove';
    startDragPaint(id,color,label);
  });
  chip.addEventListener('dragend', endDragPaint);
}

function applyToCell(cell, brush){
  const empId = cell.dataset.emp, date = cell.dataset.date;
  if(!empId || !date) return;

  // visuel
  cell.querySelector('.assign-chip')?.remove();
  const chip = makeChipHTML(brush.label, brush.color, brush.id);
  cell.appendChild(chip);
  cell.classList.add('has-chip');
  bindChipEvents(chip);

  // API
  fetch(window.API_MOVE,{
    method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams({emp_id:empId, chantier_id:String(brush.id), date})
  })
  .then(async (r)=>{ const raw=await r.text(); let d; try{d=JSON.parse(raw);}catch{ throw new Error(`HTTP ${r.status} – ${raw.slice(0,200)}`);} if(!r.ok||!d.ok) throw new Error(d.message||`HTTP ${r.status}`); })
  .catch(err=>{ console.error(err); chip.remove(); cell.classList.remove('has-chip'); });
}

function attachDropToCell(cell){
  cell.addEventListener('dragover', e=>{ e.preventDefault(); cell.classList.add('dragover'); });
  cell.addEventListener('dragleave', ()=> cell.classList.remove('dragover'));

  // drop unitaire
  cell.addEventListener('drop', e=>{
    e.preventDefault(); cell.classList.remove('dragover');
    const id    = Number(e.dataTransfer.getData('chantier_id')||0);
    const color = e.dataTransfer.getData('color')||'';
    const label = e.dataTransfer.getData('label')||'Chantier';
    if(!id) return;
    applyToCell(cell, {id,color,label});
  });

  // peinture au passage
  cell.addEventListener('dragenter', e=>{
    if(!dragPaint) return;
    e.preventDefault();
    const key = cell.dataset.emp + "|" + cell.dataset.date;
    if(dragPaint.painted.has(key)) return;
    dragPaint.painted.add(key);
    applyToCell(cell, dragPaint);
  });
}

// week-end inactif : s’active et se peint si on passe avec le pinceau
function attachPaintToOffCell(off){
  off.addEventListener('dragenter', e=>{
    if(!dragPaint) return;
    e.preventDefault();
    const active = document.createElement('div');
    active.className = 'cell-drop';
    active.dataset.date = off.dataset.date;
    active.dataset.emp  = off.dataset.emp;
    off.replaceWith(active);
    attachDropToCell(active);

    const key = active.dataset.emp + "|" + active.dataset.date;
    if(!dragPaint.painted.has(key)){
      dragPaint.painted.add(key);
      applyToCell(active, dragPaint);
    }
  });
}

document.addEventListener('DOMContentLoaded', () => {
  /* Palette : amorce la peinture */
  document.querySelectorAll('#palette .chip').forEach(ch=>{
    ch.addEventListener('dragstart', e=>{
      const id = Number(ch.dataset.chantierId);
      const color = ch.dataset.chipColor || '';
      const label = ch.textContent.trim();
      e.dataTransfer.setData('chantier_id', String(id));
      e.dataTransfer.setData('color', color);
      e.dataTransfer.setData('label', label);
      e.dataTransfer.effectAllowed = 'copyMove';
      startDragPaint(id,color,label);
    });
    ch.addEventListener('dragend', endDragPaint);
  });

  // Cases actives
  document.querySelectorAll('.cell-drop').forEach(cell=>{
    attachDropToCell(cell);
    const chip = cell.querySelector('.assign-chip');
    if (chip){ bindChipEvents(chip); cell.classList.add('has-chip'); }
  });

  // Cases OFF (week-end)
  document.querySelectorAll('.cell-off').forEach(attachPaintToOffCell);

  // Boutons X des week-ends pour activer manuellement
  document.querySelectorAll('.cell-off .wkx').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const off = btn.closest('.cell-off');
      const active = document.createElement('div');
      active.className = 'cell-drop';
      active.dataset.date = off.dataset.date;
      active.dataset.emp  = off.dataset.emp;
      off.replaceWith(active);
      attachDropToCell(active);
    });
  });
});
</script>



<?php require __DIR__ . '/../templates/footer.php'; ?>