<?php
require_once "./config/init.php";
require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/navigation/navigation.php';

if (!isset($_SESSION['utilisateurs'])) {
    header("Location: connexion.php");
    exit;
}

// Dépôts et chantiers
$allChantiers = $pdo->query("SELECT id, nom FROM chantiers")->fetchAll(PDO::FETCH_KEY_PAIR);
$allDepots = $pdo->query("SELECT id, nom FROM depots")->fetchAll(PDO::FETCH_KEY_PAIR);


$utilisateurChantierId = $_SESSION['utilisateurs']['chantier_id'] ?? null;

$stmt = $pdo->query("SELECT sc.stock_id, c.id AS chantier_id, c.nom AS chantier_nom, sc.quantite FROM stock_chantiers sc JOIN chantiers c ON sc.chantier_id = c.id");
$chantierAssoc = [];
foreach ($stmt as $row) {
    $chantierAssoc[$row['stock_id']][] = ['id' => $row['chantier_id'], 'nom' => $row['chantier_nom'], 'quantite' => (int)$row['quantite']];
}

$stmt = $pdo->query("SELECT sd.stock_id, d.id AS depot_id, d.nom AS depot_nom, sd.quantite FROM stock_depots sd JOIN depots d ON sd.depot_id = d.id");
$depotAssoc = [];
foreach ($stmt as $row) {
    $depotAssoc[$row['stock_id']][] = ['id' => $row['depot_id'], 'nom' => $row['depot_nom'], 'quantite' => (int)$row['quantite']];
}

$stocks = $pdo->query("SELECT id, nom, quantite_totale, categorie, sous_categorie FROM stock ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
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

$stmt = $pdo->prepare("SELECT t.id AS transfert_id, s.nom AS article_nom, t.quantite, u.nom AS demandeur_nom, u.prenom AS demandeur_prenom FROM transferts_en_attente t JOIN stock s ON t.article_id = s.id JOIN utilisateurs u ON t.demandeur_id = u.id WHERE t.destination_type = 'chantier' AND t.destination_id = ? AND t.statut = 'en_attente'");
$stmt->execute([$utilisateurChantierId]);
$transfertsEnAttente = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container py-4">
    <h2 class="text-center mb-4">Stock - Chef de chantier</h2>

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

    <?php if ($transfertsEnAttente): ?>
  <div class="mb-4">
    <h3>Transferts à valider</h3>
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
              <form method="post" action="validerReception_chef.php" style="display:inline;">
                <input type="hidden" name="transfert_id" value="<?= $t['transfert_id'] ?>">
                <button type="submit" class="btn btn-success btn-sm">✅ Valider réception</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>


    <div class="table-responsive mb-4">
        <table class="table table-bordered table-hover text-center align-middle">
            <thead class="table-dark">
                <tr>
                    <th>Nom (Total)</th>
                    <th class="col-photo">Photo</th>
                    <th>Dépôts</th>
                    <th>Chantiers</th>
                    <th>Mon chantier</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody class="stockTableBody">
                <?php foreach ($stocks as $stock): ?>
                    <?php
                    $stockId = $stock['id'];
                    $quantiteTotale = (int)$stock['quantite_totale'];
                    $depotsList = $depotAssoc[$stockId] ?? [];
                    $chantiersList = $chantierAssoc[$stockId] ?? [];
                    $quantiteMonChantier = 0;
                    $depotsHtml = $depotsList ? implode('<br>', array_map(fn($d) => htmlspecialchars($d['nom']) . ' (' . $d['quantite'] . ')', $depotsList)) : '<span class="text-muted">Aucun</span>';
                    $chantiersHtml = '<span class="text-muted">Aucun</span>';
                    if ($chantiersList) {
                        $chantiersHtml = '';
                        foreach ($chantiersList as $c) {
                            if ($c['id'] == $utilisateurChantierId) {
                                $quantiteMonChantier = $c['quantite'];
                                continue;
                            }
                            $chantiersHtml .= htmlspecialchars($c['nom']) . ' (' . $c['quantite'] . ')<br>';
                        }
                        if ($chantiersHtml === '') $chantiersHtml = '<span class="text-muted">Aucun</span>';
                    }
                    $badge = $quantiteMonChantier > 0 ? '<span class="badge bg-success">' . $quantiteMonChantier . '</span>' : '<span class="badge bg-danger">0</span>';
                    ?>
                    <tr data-cat="<?= strtolower(trim(htmlspecialchars($stock['categorie']))) ?>"
                        data-subcat="<?= strtolower(trim(htmlspecialchars($stock['sous_categorie']))) ?>">
                        <td><?= htmlspecialchars($stock['nom']) ?> (<?= $quantiteTotale ?>)</td>
                        <td class="col-photo"><img src="uploads/photos/<?= $stockId ?>.jpg" alt="photo" style="height: 40px;"></td>
                        <td><?= $depotsHtml ?></td>
                        <td><?= $chantiersHtml ?></td>
                        <td><?= $badge ?></td>
                        <td>
                            <button class="btn btn-sm btn-primary transfer-btn" data-stock-id="<?= $stockId ?>">
                                <i class="bi bi-arrow-left-right"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
   </div>

<!-- Modal Transfert Chef -->
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
                    <div class="mb-3">
                        <label>Destination</label>
                        <select class="form-select" id="destinationChantier">
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
                        <label for="quantity" class="form-label">Quantité</label>
                        <input type="number" class="form-control" id="quantity" name="quantity" min="1" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Envoyer</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Toast message -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1100">
    <div id="toastMessage" class="toast align-items-center text-bg-primary border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body">
                <!-- Message toast -->
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Fermer"></button>
        </div>
    </div>
</div>



<script>
window.isChef = true;
window.chefChantierId = <?= $utilisateurChantierId ?>;
</script>



<script>
    const subCategories = <?= json_encode($subCategoriesGrouped) ?>;
</script>
<script src="/js/stockGestion_chef.js"></script>
<?php require_once __DIR__ . '/templates/footer.php'; ?>