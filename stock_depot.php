<?php
require_once "./config/init.php";
require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/navigation/navigation.php';

if (!isset($_SESSION['utilisateurs']) || $_SESSION['utilisateurs']['fonction'] !== 'depot') {
  header('Location: ../index.php');
  exit;
}

// Dépôts et chantiers
$allChantiers = $pdo->query("SELECT id, nom FROM chantiers")->fetchAll(PDO::FETCH_KEY_PAIR);
$allDepots = $pdo->query("SELECT id, nom FROM depots")->fetchAll(PDO::FETCH_KEY_PAIR);

$userId = $_SESSION['utilisateurs']['id'];
$stmtDepot = $pdo->prepare("SELECT id FROM depots WHERE responsable_id = ?");
$stmtDepot->execute([$userId]);
$depot = $stmtDepot->fetch(PDO::FETCH_ASSOC);
$depotId = $depot ? (int)$depot['id'] : null;
if (!$depotId) {
  die("Dépôt non trouvé pour cet utilisateur.");
}

$stmt = $pdo->query("SELECT sc.stock_id, c.nom AS chantier_nom, sc.quantite FROM stock_chantiers sc JOIN chantiers c ON sc.chantier_id = c.id");
$chantierAssoc = [];
foreach ($stmt as $row) {
  $chantierAssoc[$row['stock_id']][] = ['nom' => $row['chantier_nom'], 'quantite' => $row['quantite']];
}

$stmt = $pdo->prepare("
    SELECT 
        s.id, s.nom, 
        COALESCE(sd.quantite,0)+COALESCE(sc.total_chantier,0) AS total_recalculé,
        COALESCE(sd.quantite,0) AS quantite_stock_depot,
        COALESCE(sd.quantite,0) - COALESCE((
            SELECT SUM(te.quantite) 
            FROM transferts_en_attente te 
            WHERE te.article_id = s.id 
            AND te.source_type = 'depot' 
            AND te.source_id = ? 
            AND te.statut = 'en_attente'
        ),0) AS disponible_depot,
        s.categorie, s.sous_categorie
    FROM stock s
    LEFT JOIN stock_depots sd ON s.id = sd.stock_id AND sd.depot_id = ?
    LEFT JOIN (
        SELECT stock_id, SUM(quantite) AS total_chantier 
        FROM stock_chantiers 
        GROUP BY stock_id
    ) sc ON s.id = sc.stock_id
    ORDER BY s.nom
");
$stmt->execute([$depotId, $depotId]);
$stocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categories = $pdo->query("SELECT DISTINCT categorie FROM stock WHERE categorie IS NOT NULL ORDER BY categorie")->fetchAll(PDO::FETCH_COLUMN);
$subCatRaw = $pdo->query("SELECT categorie, sous_categorie FROM stock WHERE sous_categorie IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
$subCategoriesGrouped = [];
foreach ($subCatRaw as $row) {
  $catKey = strtolower(trim($row['categorie']));
  $subKey = strtolower(trim($row['sous_categorie']));
  $subCategoriesGrouped[$catKey][] = $subKey;
}
foreach ($subCategoriesGrouped as &$subs) {
  $subs = array_unique($subs);
}
unset($subs);

$chantiers = $pdo->query("SELECT id, nom FROM chantiers ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT t.id AS transfert_id, s.nom AS article_nom, t.quantite, u.nom AS demandeur_nom, u.prenom AS demandeur_prenom
    FROM transferts_en_attente t
    JOIN stock s ON t.article_id = s.id
    JOIN utilisateurs u ON t.demandeur_id = u.id
    WHERE t.destination_type = 'depot' AND t.destination_id = ? AND t.statut = 'en_attente'
");
$stmt->execute([$depotId]);
$transfertsEnAttente = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container py-5">
  <h2 class="text-center mb-4">Stock dépôt</h2>

  <div class="d-flex justify-content-center mb-3 flex-wrap gap-2" id="categoriesSlide">
    <button class="btn btn-outline-primary" onclick="filterByCategory('')">Tous</button>
    <?php foreach ($categories as $cat): ?>
      <button class="btn btn-outline-primary" onclick="filterByCategory('<?= strtolower(trim(htmlspecialchars($cat))) ?>')">
        <?= htmlspecialchars(ucfirst($cat)) ?>
      </button>
    <?php endforeach; ?>
  </div>
  <div id="subCategoriesSlide" class="d-flex justify-content-center mb-4 flex-wrap gap-2"></div>

  <input type="text" id="searchInput" class="form-control mb-4" placeholder="Rechercher un article...">

  <div class="table-responsive">
    <table class="table table-bordered table-hover align-middle text-center" id="stockTableBody">
      <thead class="table-dark">
        <tr>
          <th>Nom</th>
          <th>Photo</th>
          <th>Disponible au dépôt</th>
          <th>Chantiers</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($stocks as $stock):
          $stockId = $stock['id'];
          $total = (int)$stock['total_recalculé'];
          $dispoDepot = (int)$stock['disponible_depot'];
          $nom = htmlspecialchars($stock['nom']);
          $cat = strtolower(trim($stock['categorie']));
          $subcat = strtolower(trim($stock['sous_categorie']));
          $chantierList = $chantierAssoc[$stockId] ?? [];
        ?>
          <tr data-cat="<?= $cat ?>" data-subcat="<?= $subcat ?>">
            <td>
              <span class="nom-article"><?= $nom ?></span>
              <span class="article-total"> (<?= $total ?>)</span>
            </td>
            <td><img src="uploads/photos/<?= $stockId ?>.jpg" alt="<?= $nom ?>" style="height:40px;"></td>
            <td><span class="badge quantite-disponible <?= $dispoDepot < 10 ? 'bg-danger' : 'bg-success' ?>"><?= $dispoDepot ?></span></td>
            <td>
              <?php if (count($chantierList)): ?>
                <?php foreach ($chantierList as $chantier): ?>
                  <div><?= htmlspecialchars($chantier['nom']) ?> (<?= (int)$chantier['quantite'] ?>)</div>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="text-muted">Aucun</span>
              <?php endif; ?>
            </td>
            <td>
              <button class="btn btn-sm btn-primary transfer-btn" data-stock-id="<?= $stockId ?>" data-stock-nom="<?= $nom ?>">
                <i class="bi bi-arrow-left-right"></i> Transférer
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <h3 class="mt-5">Transferts à valider</h3>
  <?php if ($transfertsEnAttente): ?>
    <table class="table table-bordered align-middle text-center">
      <thead class="table-info">
        <tr>
          <th>Article</th>
          <th>Quantité</th>
          <th>Envoyé par</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($transfertsEnAttente as $t): ?>
          <tr>
            <td><?= htmlspecialchars($t['article_nom']) ?></td>
            <td><?= $t['quantite'] ?></td>
            <td><?= htmlspecialchars($t['demandeur_prenom'] . ' ' . $t['demandeur_nom']) ?></td>
            <td>
              <form method="post" action="validerReception_depot.php" style="display:inline;">
                <input type="hidden" name="transfert_id" value="<?= $t['transfert_id'] ?>">
                <button type="submit" class="btn btn-success btn-sm">✅ Valider réception</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="text-muted">Aucun transfert en attente de validation.</p>
  <?php endif; ?>
</div>

<!-- Modal Transfert -->
<div class="modal fade" id="transferModal" tabindex="-1" aria-labelledby="transferModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="transferModalLabel">Transférer du stock</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <form id="transferForm">
          <input type="hidden" id="articleId" name="article_id">
          <input type="hidden" id="sourceDepotId" name="source_depot_id" value="<?= $depotId ?>">
          <div class="mb-3">
            <label>Destination</label>
            <select class="form-select" id="destinationChantier">
              <option value="" disabled selected>Choisir la destination</option>
              <optgroup label="Dépôts">
                <?php foreach ($allDepots as $id => $nom): ?>
                  <?php if ($id != $depotId): ?>
                    <option value="depot_<?= $id ?>"><?= htmlspecialchars($nom) ?></option>
                  <?php endif; ?>
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
            <label for="quantity" class="form-label">Quantité</label>
            <input type="number" class="form-control" id="quantity" name="quantity" min="1" required>
          </div>
          <button type="submit" class="btn btn-primary">Envoyer</button>
        </form>
      </div>
    </div>
  </div>
</div>

<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1100">
  <div id="toastMessage" class="toast align-items-center text-bg-primary border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body">
        <!-- Message inséré en JS -->
      </div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Fermer"></button>
    </div>
  </div>
</div>
<script>
window.isChef = false;
</script>
<script>
  const subCategories = <?= json_encode($subCategoriesGrouped) ?>;
</script>
<script src="/js/stockGestion_depot.js"></script>
<?php require_once __DIR__ . '/templates/footer.php'; ?>