<?php
require_once "./config/init.php";
require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/navigation/navigation.php';

if (!isset($_SESSION['utilisateurs'])) {
    header("Location: connexion.php"); exit;
}

$user   = $_SESSION['utilisateurs'];
$role   = $user['fonction'] ?? null;
$userId = (int)($user['id'] ?? 0);

/* =========================================================
   Chantiers visibles selon le rôle
   ========================================================= */
if ($role === 'administrateur') {
    $stmt = $pdo->query("SELECT id, nom FROM chantiers ORDER BY nom");
    $visibleChantiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Chef : uniquement ses chantiers
    $stmt = $pdo->prepare("
        SELECT c.id, c.nom
        FROM utilisateur_chantiers uc
        JOIN chantiers c ON c.id = uc.chantier_id
        WHERE uc.utilisateur_id = ?
        ORDER BY c.nom
    ");
    $stmt->execute([$userId]);
    $visibleChantiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =========================================================
   Employés + leurs chantiers
   - On liste les employés (et éventuellement chefs si tu veux les pointer aussi)
   - Ajuste la clause WHERE si besoin
   ========================================================= */
$employees = $pdo->query("
    SELECT u.id,
           u.prenom,
           u.nom,
           u.fonction,
           GROUP_CONCAT(DISTINCT uc.chantier_id ORDER BY uc.chantier_id SEPARATOR ',') AS chantier_ids,
           GROUP_CONCAT(DISTINCT c.nom ORDER BY c.nom SEPARATOR ' | ')                  AS chantier_noms
    FROM utilisateurs u
    LEFT JOIN utilisateur_chantiers uc ON uc.utilisateur_id = u.id
    LEFT JOIN chantiers c            ON c.id = uc.chantier_id
    WHERE u.fonction IN ('employe','chef')  -- <- ajoute d'autres rôles si besoin
    GROUP BY u.id, u.prenom, u.nom, u.fonction
    ORDER BY u.nom, u.prenom
")->fetchAll(PDO::FETCH_ASSOC);

// Pour l’URL d’action (backend à créer si pas déjà fait)
$POINTAGE_ENDPOINT = 'pointage_actions.php';
?>
<div class="container mt-4" 
     id="pointageApp"
     data-endpoint="<?= htmlspecialchars($POINTAGE_ENDPOINT) ?>"
     data-role="<?= htmlspecialchars($role ?? '') ?>">

  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <h1 class="mb-0">Pointage</h1>
    <div class="d-flex align-items-center gap-2">
      <input type="date" id="dateInput" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" />
    </div>
  </div>

  <!-- Barre d’actions groupées -->
  <div class="d-flex flex-wrap align-items-center gap-2 mb-3" id="bulkBar">
    <span class="small text-muted" id="bulkCount">0 sélectionné</span>
    <button class="btn btn-sm btn-success" data-bulk="present" data-hours="7">Présent 7h</button>
    <button class="btn btn-sm btn-outline-secondary" data-bulk="present" data-hours="8">Présent 8h</button>
    <div class="btn-group">
      <button class="btn btn-sm btn-outline-primary" data-bulk="conduite" data-hours="2">Conduite 2h</button>
      <button class="btn btn-sm btn-outline-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown"></button>
      <ul class="dropdown-menu">
        <li><a class="dropdown-item" data-bulk="conduite" data-hours="1">Conduite 1h</a></li>
        <li><a class="dropdown-item" data-bulk="conduite" data-hours="2">Conduite 2h</a></li>
        <li><a class="dropdown-item" data-bulk="conduite" data-hours="3">Conduite 3h</a></li>
      </ul>
    </div>
    <div class="btn-group">
      <button class="btn btn-sm btn-outline-danger" data-bulk="absence" data-reason="maladie">Abs. Maladie</button>
      <button class="btn btn-sm btn-outline-danger dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown"></button>
      <ul class="dropdown-menu">
        <li><a class="dropdown-item" data-bulk="absence" data-reason="conges">Abs. Congés</a></li>
        <li><a class="dropdown-item" data-bulk="absence" data-reason="sans_solde">Abs. Sans solde</a></li>
        <li><a class="dropdown-item" data-bulk="absence" data-reason="autre">Abs. Autre…</a></li>
      </ul>
    </div>
  </div>

  <!-- Filtres chantiers -->
  <div class="d-flex flex-wrap gap-2 mb-3" id="chantierFilters">
    <button class="btn btn-sm btn-outline-secondary active" data-chantier="all">Tous</button>
    <?php foreach ($visibleChantiers as $ch): ?>
      <button class="btn btn-sm btn-outline-secondary" data-chantier="<?= (int)$ch['id'] ?>">
        <?= htmlspecialchars($ch['nom']) ?>
      </button>
    <?php endforeach; ?>
  </div>

  <!-- Recherche -->
  <div class="mb-3">
    <input type="text" id="searchInput" class="form-control" placeholder="Rechercher un employé…">
  </div>

  <!-- Tableau employés -->
  <div class="table-responsive">
    <table class="table table-hover align-middle" id="pointageTable">
      <thead class="table-light">
        <tr>
          <th style="width:28%">Nom</th>
          <th>Chantiers</th>
          <th style="width:48%">Actions rapides</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($employees as $e):
        $ids  = $e['chantier_ids'] ?: '';     // ex "1,3,5"
        $noms = $e['chantier_noms'] ?: '—';
      ?>
        <tr data-user-id="<?= (int)$e['id'] ?>"
            data-chantiers="<?= htmlspecialchars($ids) ?>"
            data-name="<?= htmlspecialchars(strtolower($e['nom'].' '.$e['prenom'])) ?>">
          <td>
            <strong><?= htmlspecialchars($e['nom'].' '.$e['prenom']) ?></strong>
            <div class="small text-muted"><?= htmlspecialchars($e['fonction']) ?></div>
          </td>
          <td><?= htmlspecialchars($noms) ?></td>
          <td class="d-flex flex-wrap align-items-center gap-2">
            <!-- 1 clic = présent 7h -->
            <button class="btn btn-sm btn-success quick-action"
                    data-action="present" data-hours="7" title="Présent 7h">Présent</button>

            <!-- Ajustements rapides -->
            <div class="btn-group">
              <button class="btn btn-sm btn-outline-secondary quick-action" data-action="present" data-hours="6">6h</button>
              <button class="btn btn-sm btn-outline-secondary quick-action" data-action="present" data-hours="8">8h</button>
              <button class="btn btn-sm btn-outline-secondary quick-action" data-action="present" data-hours="9">9h</button>
            </div>

            <!-- Conduite par défaut + menu -->
            <div class="btn-group">
              <button class="btn btn-sm btn-outline-primary quick-action"
                      data-action="conduite" data-hours="2" title="Conduite 2h">Conduite</button>
              <button class="btn btn-sm btn-outline-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown"></button>
              <ul class="dropdown-menu dropdown-menu-end small">
                <li><a class="dropdown-item quick-action" data-action="conduite" data-hours="1">1h</a></li>
                <li><a class="dropdown-item quick-action" data-action="conduite" data-hours="2">2h</a></li>
                <li><a class="dropdown-item quick-action" data-action="conduite" data-hours="3">3h</a></li>
              </ul>
            </div>

            <!-- Absence par défaut + menu -->
            <div class="btn-group">
              <button class="btn btn-sm btn-outline-danger quick-action"
                      data-action="absence" data-reason="maladie" title="Absence (Maladie)">Abs.</button>
              <button class="btn btn-sm btn-outline-danger dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown"></button>
              <ul class="dropdown-menu dropdown-menu-end small">
                <li><a class="dropdown-item quick-action" data-action="absence" data-reason="maladie">Maladie</a></li>
                <li><a class="dropdown-item quick-action" data-action="absence" data-reason="conges">Congés</a></li>
                <li><a class="dropdown-item quick-action" data-action="absence" data-reason="sans_solde">Sans solde</a></li>
                <li><a class="dropdown-item quick-action" data-action="absence" data-reason="autre">Autre…</a></li>
              </ul>
            </div>

            <!-- Sélection groupée -->
            <div class="form-check ms-1">
              <input class="form-check-input select-user" type="checkbox">
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal "Autre…" -->
<div class="modal fade" id="autreModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="autreForm">
      <div class="modal-header">
        <h5 class="modal-title">Préciser le motif</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <textarea class="form-control" id="autreComment" rows="3" placeholder="Motif / commentaire" required></textarea>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary" type="submit">Valider</button>
      </div>
    </form>
  </div>
</div>

<!-- Toast (injecté par JS) -->

<!-- JS de la page -->
<script>
  // Optionnel : si tu veux pré-sélectionner un chantier via l’URL (ex: pointage.php?chantier_id=3)
  window.defaultChantierId = <?= isset($_GET['chantier_id']) ? (int)$_GET['chantier_id'] : 'null' ?>;
</script>
<script src="/js/pointage.js?v=1"></script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
