<?php
require_once __DIR__ . '/../config/init.php';
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/navigation/navigation.php';

// Vérifier si admin connecté
if (!isset($_SESSION['utilisateurs']) || ($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur') {
  header("Location: connexion.php");
  exit;
}

/* ====== CSRF ====== */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

/* ====== Liste chefs (pour les modales) ====== */
$chefsOptions = $pdo->query("SELECT id, prenom, nom FROM utilisateurs WHERE fonction = 'chef' ORDER BY prenom, nom")
  ->fetchAll(PDO::FETCH_ASSOC);

/* ====== Données chantiers ====== */
$chantiers = $pdo->query("SELECT id, nom, description, date_debut, date_fin FROM chantiers ORDER BY id DESC")
  ->fetchAll(PDO::FETCH_ASSOC);

?>
<div class="container mt-4">
  <h1 class="mb-4 text-center">Gestion des chantiers</h1>

  <!-- Bouton création -->
  <div class="d-flex justify-content-center mb-3">
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#chantierModal">
      + Créer un chantier
    </button>
  </div>

  <input type="text" id="chantierSearchInput" class="form-control mb-4" placeholder="Rechercher un chantier..." autocomplete="off" />

  <!-- Tableau des chantiers -->
  <table class="table table-striped table-hover table-bordered text-center">
    <thead class="table-dark">
      <tr>
        <th>Nom</th>
        <th>Chefs assignés</th>
        <th>Date début</th>
        <th>Date fin</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody id="chantiersTableBody">
      <?php
      // Préparer requête pour chefs par chantier
      $stmtChefs = $pdo->prepare("
            SELECT u.id, u.prenom, u.nom
            FROM utilisateurs u
            JOIN utilisateur_chantiers uc ON uc.utilisateur_id = u.id
            WHERE uc.chantier_id = ?
            ORDER BY u.prenom, u.nom
        ");

      foreach ($chantiers as $chantier) {
        $stmtChefs->execute([$chantier['id']]);
        $chefsOf = $stmtChefs->fetchAll(PDO::FETCH_ASSOC);
        $chefsText = $chefsOf
          ? implode(', ', array_map(fn($c) => htmlspecialchars($c['prenom'] . ' ' . $c['nom']), $chefsOf))
          : '<span class="text-muted">—</span>';

        $highlight = (isset($_GET['highlight']) && (int)$_GET['highlight'] === (int)$chantier['id']) ? 'table-success' : '';

        echo '<tr class="align-middle ' . $highlight . '" data-row-id="' . (int)$chantier['id'] . '">';
        echo '<td>
                    <a class="link-primary fw-semibold text-decoration-none"
                       href="chantier_contenu.php?id=' . (int)$chantier['id'] . '">
                       ' . htmlspecialchars($chantier['nom']) . '
                    </a>
                  </td>';
        echo '<td>' . $chefsText . '</td>';
        echo '<td>' . htmlspecialchars($chantier['date_debut'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($chantier['date_fin'] ?? '') . '</td>';

        // Pour le bouton éditer, on passe aussi la liste des chefs (IDs) en data attr
        $chefIds = $chefsOf ? implode(',', array_map(fn($c) => $c['id'], $chefsOf)) : '';
        echo '<td>
                    <button class="btn btn-sm btn-warning edit-btn"
                        data-bs-toggle="modal"
                        data-bs-target="#chantierEditModal"
                        data-id="' . (int)$chantier['id'] . '"
                        data-nom="' . htmlspecialchars($chantier['nom']) . '"
                        data-description="' . htmlspecialchars($chantier['description'] ?? '') . '"
                        data-debut="' . htmlspecialchars($chantier['date_debut'] ?? '') . '"
                        data-fin="' . htmlspecialchars($chantier['date_fin'] ?? '') . '"
                        data-chef-ids="' . $chefIds . '"
                        title="Modifier">
                        <i class="bi bi-pencil-fill"></i>
                    </button>
                    <button class="btn btn-sm btn-danger delete-btn"
                        data-bs-toggle="modal"
                        data-bs-target="#deleteModal"
                        data-id="' . (int)$chantier['id'] . '"
                        title="Supprimer">
                        <i class="bi bi-trash-fill"></i>
                    </button>
                  </td>';
        echo '</tr>';
      }
      ?>
    </tbody>
  </table>
</div>

<!-- Modal création -->
<div class="modal fade" id="chantierModal" tabindex="-1" aria-labelledby="chantierModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" action="ajouterChantier.php" id="chantierForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="chantierModalLabel">Créer un chantier</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="chantier_id" id="chantierId" value="">
          <div class="mb-3">
            <label for="chantierNom" class="form-label">Nom du chantier</label>
            <input type="text" class="form-control" id="chantierNom" name="nom" required>
          </div>
          <div class="mb-3">
            <label for="chantierDesc" class="form-label">Description</label>
            <textarea class="form-control" id="chantierDesc" name="description"></textarea>
          </div>
          <div class="mb-3">
            <label for="chantierDebut" class="form-label">Date de début</label>
            <input type="date" class="form-control" id="chantierDebut" name="date_debut">
          </div>
          <div class="mb-3">
            <label for="chantierFin" class="form-label">Date de fin</label>
            <input type="date" class="form-control" id="chantierFin" name="date_fin">
          </div>

          <div class="mb-3">
            <label for="chefChantier" class="form-label">Chef(s) de chantier</label>
            <select class="form-select" id="chefChantier" name="chefs[]" multiple size="6">
              <?php foreach ($chefsOptions as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['prenom'] . ' ' . $c['nom']) ?></option>
              <?php endforeach; ?>
            </select>
            <small class="text-muted">Maintiens Ctrl/Cmd pour sélection multiple.</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success">Enregistrer</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Modal modification -->
<div class="modal fade" id="chantierEditModal" tabindex="-1" aria-labelledby="chantierEditModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" action="ajouterChantier.php" id="chantierEditForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="chantier_id" id="chantierIdEdit" value="">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="chantierEditModalLabel">Modifier un chantier</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="chantierNomEdit" class="form-label">Nom du chantier</label>
            <input type="text" class="form-control" id="chantierNomEdit" name="nom" required>
          </div>
          <div class="mb-3">
            <label for="chantierDescEdit" class="form-label">Description</label>
            <textarea class="form-control" id="chantierDescEdit" name="description"></textarea>
          </div>
          <div class="mb-3">
            <label for="chantierDebutEdit" class="form-label">Date de début</label>
            <input type="date" class="form-control" id="chantierDebutEdit" name="date_debut">
          </div>
          <div class="mb-3">
            <label for="chantierFinEdit" class="form-label">Date de fin</label>
            <input type="date" class="form-control" id="chantierFinEdit" name="date_fin">
          </div>
          <div class="mb-3">
            <label for="chefChantierEdit" class="form-label">Chef(s) de chantier</label>
            <select class="form-select" id="chefChantierEdit" name="chefs[]" multiple size="6">
              <?php foreach ($chefsOptions as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['prenom'] . ' ' . $c['nom']) ?></option>
              <?php endforeach; ?>
            </select>
            <small class="text-muted">Maintiens Ctrl/Cmd pour sélection multiple.</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success">Enregistrer</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Modal confirmation suppression -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" action="supprimerChantier.php" id="deleteForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="delete_id" id="deleteId">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Confirmer la suppression</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          Es-tu sûr de vouloir supprimer ce chantier ? Cette action est irréversible.
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-danger">Supprimer</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1055">
  <div id="chantierToast" class="toast align-items-center text-white bg-success border-0" role="alert">
    <div class="d-flex">
      <div class="toast-body" id="chantierToastMsg">Chantier enregistré avec succès.</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Fermer"></button>
    </div>
  </div>
</div>

<?php if (isset($_GET['success'])): ?>
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const type = "<?= htmlspecialchars($_GET['success']) ?>";
      let message = "Chantier enregistré avec succès.";
      if (type === "create") message = "Chantier créé avec succès.";
      else if (type === "update") message = "Chantier modifié avec succès.";
      else if (type === "delete") message = "Chantier supprimé avec succès.";
      showChantierToast(message);
    });
  </script>
<?php endif; ?>

<script src="./js/chantiers_admin.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    const highlightedRow = document.querySelector('tr.table-success');
    if (highlightedRow) setTimeout(() => highlightedRow.classList.remove('table-success'), 3000);
  });
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>