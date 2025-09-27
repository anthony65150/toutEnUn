<?php

declare(strict_types=1);
require_once __DIR__ . '/../config/init.php';

if (!isset($_SESSION['utilisateurs'])) {
  header('Location: /connexion.php');
  exit;
}

$user         = $_SESSION['utilisateurs'];
$role         = (string)($user['fonction'] ?? '');
$entrepriseId = (int)($user['entreprise_id'] ?? 0);
if (!$entrepriseId) {
  http_response_code(403);
  exit('Entreprise non définie.');
}

/* ===== ID chantier ===== */
$chantierId = (int)($_GET['chantier_id'] ?? ($_GET['id'] ?? 0));
if (!$chantierId) {
  require_once __DIR__ . '/../templates/header.php';
  require_once __DIR__ . '/../templates/navigation/navigation.php';
  echo '<div class="container mt-4 alert alert-danger">ID de chantier manquant.</div>';
  require_once __DIR__ . '/../templates/footer.php';
  exit;
}

/* ===== Charger chantier ===== */
$stCh = $pdo->prepare("SELECT id, nom FROM chantiers WHERE id = ? AND entreprise_id = ?");
$stCh->execute([$chantierId, $entrepriseId]);
$chantier = $stCh->fetch(PDO::FETCH_ASSOC);
if (!$chantier) {
  require_once __DIR__ . '/../templates/header.php';
  require_once __DIR__ . '/../templates/navigation/navigation.php';
  echo '<div class="container mt-4 alert alert-danger">Chantier introuvable pour cette entreprise.</div>';
  require_once __DIR__ . '/../templates/footer.php';
  exit;
}

/* ===== Accès : admin OK ; chef OK si affecté ===== */
$allowed = false;
if ($role === 'administrateur') {
  $allowed = true;
} elseif ($role === 'chef') {
  $stmtAuth = $pdo->prepare("
        SELECT 1 FROM utilisateur_chantiers
        WHERE utilisateur_id = :uid AND chantier_id = :cid AND entreprise_id = :eid
        LIMIT 1
    ");
  $stmtAuth->execute([':uid' => (int)$user['id'], ':cid' => $chantierId, ':eid' => $entrepriseId]);
  $allowed = (bool)$stmtAuth->fetchColumn();
}
if (!$allowed) {
  require_once __DIR__ . '/../templates/header.php';
  require_once __DIR__ . '/../templates/navigation/navigation.php';
  echo '<div class="container mt-4 alert alert-danger">Accès refusé.</div>';
  require_once __DIR__ . '/../templates/footer.php';
  exit;
}

/* ===== CSRF ===== */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ===== Données tâches (TU en HEURES) =====
   ALTER TABLE chantier_taches ADD COLUMN tu_heures DECIMAL(9,2) NOT NULL DEFAULT 0;
*/
$sql = "
SELECT
  t.id,
  t.nom,
  COALESCE(t.unite,'')           AS unite,
  COALESCE(t.quantite,0)         AS quantite,
  COALESCE(t.tu_heures,0)        AS tu_heures,
  COALESCE(t.avancement_pct,0)   AS avancement_pct,
  t.updated_at,
  (
    SELECT COALESCE(SUM(pj.heures), 0)
    FROM pointages_jour pj
    WHERE pj.entreprise_id = ?
      AND pj.chantier_id   = t.chantier_id
      AND pj.tache_id      = t.id
  ) AS heures_pointes
FROM chantier_taches t
WHERE t.chantier_id   = ?
  AND t.entreprise_id = ?
ORDER BY t.nom ASC
";
$st = $pdo->prepare($sql);
$st->execute([(int)$entrepriseId, (int)$chantierId, (int)$entrepriseId]);
$tasks = $st->fetchAll(PDO::FETCH_ASSOC);

/* Helper jolie sortie h déc. (2 décimales) */
function h2(float $x): string
{
  return number_format((float)$x, 2, '.', '');
}

require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/navigation/navigation.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="/chantiers/css/chantier_heures.css?v=<?= time() ?>">

<!--
  Data attributes pour que le JS externe récupère le contexte
  (évite de mettre du PHP dans le .js)
-->
<div
  id="heuresPage"
  data-is-admin="<?= $role === 'administrateur' ? '1' : '0' ?>"
  data-csrf-token="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
  data-chantier-id="<?= (int)$chantierId ?>"
  class="container mt-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <a href="javascript:history.back()" class="btn btn-outline-secondary">← Retour</a>
    <h1 class="mb-0">Heures – <?= htmlspecialchars($chantier['nom']) ?></h1>
    <div></div>
  </div>

  <div class="d-flex gap-2 mb-3">
    <?php if ($role === 'administrateur'): ?>
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#tacheModal">
        <i class="bi bi-plus-lg me-1"></i> Nouvelle tâche
      </button>
    <?php endif; ?>
  </div>

  <div class="table-responsive">
    <table class="table table-striped table-hover table-bordered text-center align-middle">
      <thead class="table-dark">
        <tr>
          <th>Tâches</th>
          <th class="w-110">Qte</th>
          <th class="w-110">TU<br><small>(h / unité)</small></th>
          <th class="w-110">Temps total</th>
          <th class="w-90">% avancement</th>
          <th class="w-110">Temps au stade</th>
          <th class="w-110">Heures pointées</th>
          <th class="w-110">Écart</th>
          <th class="w-110">Nouveau TU<br><small>(h / unité)</small></th>
          <?php if ($role === 'administrateur'): ?><th class="w-70">Actions</th><?php endif; ?>
        </tr>
      </thead>
      <tbody id="heuresTbody">
        <?php if (empty($tasks)): ?>
          <tr>
            <td colspan="<?= $role === 'administrateur' ? 10 : 9 ?>" class="text-muted py-4">Aucune tâche pour ce chantier.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($tasks as $t):
            $qte   = (float)$t['quantite'];
            $tuH   = (float)$t['tu_heures'];
            $pct   = (float)$t['avancement_pct'];
            $ttH   = (float)($qte * $tuH);
            $tsH   = (float)($ttH * ($pct / 100));
            $hpH   = (float)($t['heures_pointes'] ?? 0);
            $ecH   = $tsH - $hpH;
            $newTU = ($qte > 0 && $pct > 0)
              ? ($hpH / ($qte * ($pct / 100)))
              : 0.0;

            $ecCls  = ($ecH < 0) ? 'cell-bad' : 'cell-good';
            $newCls = ($newTU > $tuH + 1e-9) ? 'cell-bad' : 'cell-good';

            $qteTxt   = rtrim(rtrim(number_format($qte, 2, '.', ''), '0'), '.');
            $uniteTxt = $t['unite'] ?: '';
          ?>
            <tr
              data-id="<?= (int)$t['id'] ?>"
              data-name="<?= htmlspecialchars($t['nom']) ?>"
              data-qte="<?= htmlspecialchars((string)$qte) ?>"
              data-tu-hours="<?= htmlspecialchars(h2($tuH)) ?>"
              data-shortcut="<?= htmlspecialchars($t['shortcut'] ?? '') ?>"
              data-unite="<?= htmlspecialchars($t['unite'] ?? '') ?>">
              <td class="text-start">
                <div class="fw-semibold"><?= htmlspecialchars($t['nom']) ?></div>
              </td>

              <td class="mono"><?= htmlspecialchars($qteTxt) ?> <?= htmlspecialchars($uniteTxt) ?></td>
              <td class="mono"><?= htmlspecialchars(h2($tuH)) ?></td>

              <td class="mono tt-cell"><?= h2($ttH) ?></td>

              <?php
              $updatedText = !empty($t['updated_at']) ? date('d-m-Y', strtotime($t['updated_at'])) : '';
              ?>
              <td>
                <div class="input-group input-group-sm">
                  <input type="number" step="1" min="0" max="100"
                    class="form-control avc-input"
                    value="<?= htmlspecialchars((string)$pct) ?>"
                    <?= $role === 'administrateur' ? '' : 'readonly' ?>>
                  <span class="input-group-text">%</span>
                </div>

                <div class="small text-muted last-update" <?= $updatedText ? '' : 'style="display:none"' ?>>
                  Dernière mise à jour le <span class="date"><?= htmlspecialchars($updatedText) ?></span>
                </div>
              </td>




              <td class="mono ts-cell" data-h="<?= h2($tsH) ?>"><?= h2($tsH) ?></td>
              <td class="mono hp-cell" data-h="<?= h2($hpH) ?>"><?= h2($hpH) ?></td>
              <td class="mono ecart-cell <?= $ecCls ?>" data-h="<?= h2($ecH) ?>"><?= h2($ecH) ?></td>
              <td class="mono newtu-cell <?= $newCls ?>" data-h="<?= h2($newTU) ?>"><?= h2($newTU) ?></td>

              <?php if ($role === 'administrateur'): ?>
                <td>
                  <!-- Bouton MODIFIER (jaune, crayon) -->
                  <button class="btn btn-sm btn-warning edit-row" title="Modifier">
                    <i class="bi bi-pencil"></i>
                  </button>

                  <!-- Supprimer -->
                  <button class="btn btn-sm btn-danger delete-row ms-1" title="Supprimer">
                    <i class="bi bi-trash"></i>
                  </button>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($role === 'administrateur'): ?>
  <!-- Modal nouvelle tâche -->
  <div class="modal fade" id="tacheModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form class="modal-content" id="tacheForm">
        <div class="modal-header">
          <h5 class="modal-title">Tâche</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="tache_id" id="tacheId">
          <input type="hidden" name="chantier_id" value="<?= (int)$chantierId ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

          <div class="form-group">
            <label for="taskName">Nom de la tâche</label>
            <input type="text" class="form-control" id="taskName" name="task_name" required>
          </div>

          <div class="form-group">
            <label for="taskShortcut">Raccourci (affiché pour le pointage)</label>
            <input type="text" class="form-control" id="taskShortcut" name="shortcut" placeholder="ex: Ferraillage">
          </div>


          <div class="row g-2">
            <div class="col-6">
              <label class="form-label">Unité (ex: u, m²)</label>
              <input type="text" class="form-control" name="unite" id="tacheUnite">
            </div>
            <div class="col-6">
              <label class="form-label">Quantité</label>
              <input type="number" step="0.01" min="0" class="form-control" name="quantite" id="tacheQte" value="0">
            </div>
          </div>
          <div class="mt-3">
            <label class="form-label">TU (h / unité) — heures décimales</label>
            <input type="number" step="0.01" min="0" class="form-control" name="tu_heures" id="tacheTUh" value="0">
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-primary">Enregistrer</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>

<?php if ($role === 'administrateur'): ?>
  <!-- Modal modifier tâche -->
  <div class="modal fade" id="tacheEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form class="modal-content" id="tacheEditForm">
        <div class="modal-header">
          <h5 class="modal-title">Modifier la tâche</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="tache_id" id="editTacheId">
          <input type="hidden" name="chantier_id" value="<?= (int)$chantierId ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

          <div class="form-group">
            <label for="editTaskName">Nom de la tâche</label>
            <input type="text" class="form-control" id="editTaskName" name="task_name" required>
          </div>

          <div class="form-group">
            <label for="editTaskShortcut">Raccourci (affiché pour le pointage)</label>
            <input type="text" class="form-control" id="editTaskShortcut" name="shortcut" placeholder="ex: Ferraillage">
          </div>

          <div class="row g-2">
            <div class="col-6">
              <label class="form-label" for="editTacheUnite">Unité (ex: u, m²)</label>
              <input type="text" class="form-control" name="unite" id="editTacheUnite">
            </div>
            <div class="col-6">
              <label class="form-label" for="editTacheQte">Quantité</label>
              <input type="number" step="0.01" min="0" class="form-control" name="quantite" id="editTacheQte" value="0">
            </div>
          </div>
          <div class="mt-3">
            <label class="form-label" for="editTacheTUh">TU (h / unité) — heures décimales</label>
            <input type="number" step="0.01" min="0" class="form-control" name="tu_heures" id="editTacheTUh" value="0">
          </div>
        </div>

        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-primary">Enregistrer</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>


<!-- Modal confirmation suppression -->
<div class="modal fade" id="confirmDeleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 rounded-3 overflow-hidden">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title fw-semibold">
          <i class="bi bi-exclamation-octagon me-2"></i> Confirmer la suppression
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <p id="confirmDeleteText" class="mb-0"></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
        <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Supprimer</button>
      </div>
    </div>
  </div>
</div>


<script src="./js/chantier_heures.js" defer></script>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>