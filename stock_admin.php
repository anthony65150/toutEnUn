<?php
require_once "./config/init.php";

if (!isset($_SESSION['utilisateurs']) || $_SESSION['utilisateurs']['fonction'] !== 'administrateur') {
  header("Location: connexion.php");
  exit;
}
require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/navigation/navigation.php';

$allChantiers = $pdo->query("SELECT id, nom FROM chantiers")->fetchAll(PDO::FETCH_KEY_PAIR);
$allDepots = $pdo->query("SELECT id, nom FROM depots")->fetchAll(PDO::FETCH_KEY_PAIR);

$stmt = $pdo->query("SELECT id, nom, quantite_totale, categorie, sous_categorie FROM stock ORDER BY nom");
$stocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->query("SELECT sc.stock_id, c.nom AS chantier_nom, sc.quantite FROM stock_chantiers sc JOIN chantiers c ON sc.chantier_id = c.id");
$chantierAssoc = [];
foreach ($stmt as $row) {
  $chantierAssoc[$row['stock_id']][] = [
    'nom' => $row['chantier_nom'],
    'quantite' => $row['quantite']
  ];
}

$categories = $pdo->query("SELECT DISTINCT categorie FROM stock WHERE categorie IS NOT NULL ORDER BY categorie")->fetchAll(PDO::FETCH_COLUMN);
$subCatRaw = $pdo->query("SELECT categorie, sous_categorie FROM stock WHERE sous_categorie IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
$subCategoriesGrouped = [];
foreach ($subCatRaw as $row) {
  $subCategoriesGrouped[$row['categorie']][] = $row['sous_categorie'];
}
?>

<div class="container py-4">
  <div class="text-center mb-4">
    <h2 class="mb-3">Gestion de stock</h2>
    <a href="ajoutStock.php" class="btn btn-success">
      <i class="bi bi-plus-circle"></i> Ajouter un élément
    </a>
  </div>

  <!-- Boutons catégories -->
  <div class="d-flex justify-content-center mb-3 flex-wrap gap-2" id="categoriesSlide">
    <button class="btn btn-outline-primary" onclick="filterByCategory('')">Tous</button>
    <?php foreach ($categories as $cat): ?>
      <button class="btn btn-outline-primary" onclick="filterByCategory('<?= htmlspecialchars($cat) ?>')">
        <?= htmlspecialchars(ucfirst($cat)) ?>
      </button>
    <?php endforeach; ?>
  </div>

  <!-- Conteneur pour sous-catégories, rempli dynamiquement par JS -->
  <div id="subCategoriesSlide" class="d-flex justify-content-center mb-2 flex-wrap gap-2"></div>

  <input type="text" id="searchInput" class="form-control mb-4" placeholder="Rechercher un article...">

  <div class="table-responsive">
    <table class="table table-bordered table-hover text-center align-middle">
      <thead class="table-dark">
        <tr>
          <th>Nom</th>
          <th>Photo</th>
          <th>Chantiers</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody id="stockTableBody">
        <?php foreach ($stocks as $stock): ?>
          <?php $stockId = $stock['id']; ?>
          <tr data-cat="<?= htmlspecialchars($stock['categorie']) ?>" data-subcat="<?= htmlspecialchars($stock['sous_categorie']) ?>">
            <td>
              <a href="article.php?id=<?= $stockId ?>">
                <?= htmlspecialchars($stock['nom']) ?> (<?= $stock['quantite_totale'] ?>)
              </a>
            </td>
            <td><img src="uploads/photos/<?= $stockId ?>.jpg" alt="photo" style="height: 40px;"></td>
            <td>
              <?php if (!empty($chantierAssoc[$stockId])): ?>
                <?php foreach ($chantierAssoc[$stockId] as $c): ?>
                  <div><?= htmlspecialchars($c['nom']) ?> (<?= $c['quantite'] ?>)</div>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="text-muted">Aucun</span>
              <?php endif; ?>
            </td>
            <td>
              <button class="btn btn-sm btn-primary transfer-btn" data-stock-id="<?= $stockId ?>">
                <i class="bi bi-arrow-left-right"></i>
              </button>
              <button class="btn btn-sm btn-warning edit-btn"
                data-stock-id="<?= $stockId ?>"
                data-stock-nom="<?= htmlspecialchars($stock['nom']) ?>">
                <i class="bi bi-pencil"></i>
              </button>
              <button type="button"
                class="btn btn-sm btn-danger delete-btn"
                data-stock-id="<?= $stockId ?>"
                data-stock-nom="<?= htmlspecialchars($stock['nom']) ?>">
                <i class="bi bi-trash"></i>
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal Transfert -->
<div class="modal fade" id="transferModal" tabindex="-1" aria-labelledby="transferModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="transferModalLabel">Transfert d'article</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <form>
          <input type="hidden" id="modalStockId">
          <div class="mb-3">
            <label for="sourceChantier" class="form-label">Source</label>
            <select class="form-select" id="sourceChantier" required>
              <option value="" disabled selected>Choisir la source</option>
              <optgroup label="Dépôts">
                <?php foreach ($allDepots as $id => $nom): ?>
                  <option value="depot_<?= $id ?>"><?= htmlspecialchars($nom) ?></option>
                <?php endforeach; ?>
              </optgroup>
              <optgroup label="Chantiers">
                <?php foreach ($allChantiers as $id => $nom): ?>
                  <option value="chantier_<?= $id ?>"><?= htmlspecialchars($nom) ?></option>
                <?php endforeach; ?>
              </optgroup>
            </select>
          </div>
          <div class="mb-3">
            <label for="destinationChantier" class="form-label">Destination</label>
            <select class="form-select" id="destinationChantier" required>
              <option value="" disabled selected>Choisir la destination</option>
              <optgroup label="Dépôts">
                <?php foreach ($allDepots as $id => $nom): ?>
                  <option value="depot_<?= $id ?>"><?= htmlspecialchars($nom) ?></option>
                <?php endforeach; ?>
              </optgroup>
              <optgroup label="Chantiers">
                <?php foreach ($allChantiers as $id => $nom): ?>
                  <option value="chantier_<?= $id ?>"><?= htmlspecialchars($nom) ?></option>
                <?php endforeach; ?>
              </optgroup>
            </select>
          </div>
          <div class="mb-3">
            <label for="transferQty" class="form-label">Quantité à transférer</label>
            <input type="number" min="1" class="form-control" id="transferQty" required>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
        <button type="button" class="btn btn-primary" id="confirmTransfer">Transférer</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal Modifier -->
<div class="modal fade" id="modifyModal" tabindex="-1" aria-labelledby="modifyModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modifyModalLabel">Modifier un article</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="modifyStockId">
        <div class="mb-3">
          <label for="modifyNom" class="form-label">Nom</label>
          <input type="text" class="form-control" id="modifyNom" required>
        </div>
        <div class="mb-3">
          <label for="modifyQty" class="form-label">Quantité totale</label>
          <input type="number" class="form-control" id="modifyQty" min="0">
        </div>
        <div class="mb-3">
          <label for="modifyPhoto" class="form-label">Photo (optionnel)</label>
          <input type="file" class="form-control" id="modifyPhoto">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
        <button type="button" class="btn btn-primary" id="confirmModify">Enregistrer</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal Supprimer -->
<div class="modal fade" id="confirmDeleteModal" tabindex="-1" aria-labelledby="confirmDeleteLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title" id="confirmDeleteLabel">Confirmer la suppression</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <p>Es-tu sûr de vouloir supprimer <strong id="deleteItemName"></strong> ? Cette action est irréversible.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
        <button type="button" class="btn btn-danger" id="confirmDeleteButton">Supprimer</button>
      </div>
    </div>
  </div>
</div>

<!-- Toasts -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 9999">
  <div id="transferToast" class="toast align-items-center text-bg-success border-0 mb-2" role="alert">
    <div class="d-flex">
      <div class="toast-body">✅ Transfert enregistré avec succès.</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
  <div id="modifyToast" class="toast align-items-center text-bg-success border-0 mb-2" role="alert">
    <div class="d-flex">
      <div class="toast-body">✅ Modification enregistrée avec succès.</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
  <div id="errorToast" class="toast align-items-center text-bg-danger border-0" role="alert">
    <div class="d-flex">
      <div class="toast-body" id="errorToastMessage">❌ Une erreur est survenue.</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<script>
  const subCategories = <?= json_encode($subCategoriesGrouped) ?>;
</script>
<script src="/js/stock.js"></script>
<script src="/js/stockGestion_admin.js"></script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>