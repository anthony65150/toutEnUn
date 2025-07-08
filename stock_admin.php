<?php
require_once "./config/init.php";
require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/navigation/navigation.php';

if (!isset($_SESSION['utilisateurs']) || $_SESSION['utilisateurs']['fonction'] !== 'administrateur') {
    header("Location: connexion.php");
    exit;
}
$allChantiers = $pdo->query("SELECT id, nom FROM chantiers")->fetchAll(PDO::FETCH_KEY_PAIR);



// Récupération des stocks
$stmt = $pdo->query("SELECT id, nom, quantite_totale, quantite_disponible, categorie, sous_categorie FROM stock ORDER BY nom");
$stocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération des associations stock <-> chantiers
$stmt = $pdo->query("SELECT sc.stock_id, c.nom AS chantier_nom, sc.quantite FROM stock_chantiers sc JOIN chantiers c ON sc.chantier_id = c.id");
$chantierAssoc = [];
foreach ($stmt as $row) {
    $chantierAssoc[$row['stock_id']][] = [
        'nom' => $row['chantier_nom'],
        'quantite' => $row['quantite']
    ];
}

// Catégories et sous-cat
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

  <!-- Bloc des catégories centré -->
  <div class="d-flex justify-content-center mb-3 flex-wrap gap-2" id="categoriesSlide">
    <button class="btn btn-outline-primary" onclick="filterByCategory('')">Tous</button>
    <?php foreach ($categories as $cat): ?>
      <button class="btn btn-outline-primary" onclick="filterByCategory('<?= htmlspecialchars($cat) ?>')">
        <?= htmlspecialchars(ucfirst($cat)) ?>
      </button>
    <?php endforeach; ?>
  </div>

  <!-- Bloc des sous-catégories centré -->
  <div class="d-flex justify-content-center mb-2 flex-wrap gap-2" id="subCategoriesSlide">
    <!-- Dynamique avec JS -->
  </div>
</div>




<div class="container">
    <!-- Champ de recherche -->
    <input type="text" id="searchInput" class="form-control mb-4" placeholder="Rechercher un article...">

    <div class="table-responsive">
        <table class="table table-bordered table-hover text-center align-middle">
            <thead class="table-dark">
                <tr>
                    <th>Nom</th>
                    <th>Photo</th>
                    <th>Quantité</th>
                    <th>Chantiers</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="stockTableBody">
                <?php foreach ($stocks as $stock): ?>
                    <?php
                    $stockId = $stock['id'];
                    $total = (int)$stock['quantite_totale'];
                    $dispo = (int)$stock['quantite_disponible'];
                    $surChantier = $total - $dispo;
                    ?>
                    <tr data-cat="<?= htmlspecialchars($stock['categorie']) ?>" data-subcat="<?= htmlspecialchars($stock['sous_categorie']) ?>">
                        <td><a href="article.php?id=<?= $stockId ?>"><?= htmlspecialchars($stock['nom']) ?> (<?= $total ?>)</a></td>
                        <td><img src="uploads/photos/<?= $stockId ?>.jpg" alt="photo" style="height: 40px;"></td>
                        <td class="quantite-col">
                            <span class="badge <?= $dispo < 10 ? 'bg-danger' : 'bg-success' ?>"><?= $dispo ?> dispo</span>
                            <span class="badge bg-warning text-dark"><?= $surChantier ?> sur chantier</span>
                        </td>
                        <td class="admin-col">
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
                            <a href="modifierStock.php?id=<?= $stockId ?>" class="btn btn-sm btn-warning">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <a href="supprimerStock.php?id=<?= $stockId ?>" class="btn btn-sm btn-danger" onclick="return confirm('Supprimer cet élément ?')">
                                <i class="bi bi-trash"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal de transfert (Admin) -->
<div class="modal fade" id="transferModal" tabindex="-1" aria-labelledby="transferModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
    
      <div class="modal-header">
        <h5 class="modal-title" id="transferModalLabel">Transfert d'article</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>

      <div class="modal-body">
        <form id="transferForm_admin">
          <input type="hidden" id="modalStockId">

          <!-- Chantier source -->
          <div class="mb-3">
            <label for="sourceChantier" class="form-label">Chantier d’origine</label>
            <select class="form-select" id="sourceChantier" required>
              <option value="" disabled selected>Choisir le chantier source</option>
              <option value="depot">Dépôt</option>
              <?php foreach ($allChantiers as $id => $nom): ?>
                <option value="<?= $id ?>"><?= htmlspecialchars($nom) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Destination -->
          <div class="mb-3">
            <label for="destinationChantier" class="form-label">Destination</label>
            <select class="form-select" id="destinationChantier" required>
              <option value="" disabled selected>Choisir la destination</option>
              <option value="depot">Dépôt</option>
              <?php foreach ($allChantiers as $id => $nom): ?>
                <option value="<?= $id ?>"><?= htmlspecialchars($nom) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Quantité -->
          <div class="mb-3">
            <label for="transferQty" class="form-label">Quantité à transférer</label>
            <input type="number" min="1" class="form-control" id="transferQty" required>
          </div>
        </form>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
        <button type="submit" class="btn btn-primary" id="confirmTransfer">Transférer</button>
      </div>
    </div>
  </div>
</div>

<!-- Toast de succès -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 9999">
  <div id="transferToast" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body" id="transferToastMessage">
        ✅ Transfert effectué avec succès !
      </div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Fermer"></button>
    </div>
  </div>
</div>

<!-- Toast d'erreur -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 9999">
  <div id="errorToast" class="toast align-items-center text-bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body" id="errorToastMessage">
        ❌ Une erreur est survenue.
      </div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Fermer"></button>
    </div>
  </div>
</div>




<script>
    const subCategories = <?= json_encode($subCategoriesGrouped) ?>;
</script>
<script src="/js/stock.js"></script>
<script src="/js/stockGestion_admin.js"></script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>