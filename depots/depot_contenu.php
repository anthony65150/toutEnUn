<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/navigation/navigation.php';

if (!isset($_SESSION['utilisateurs'])) {
    header("Location: ../connexion.php");
    exit;
}

$user = $_SESSION['utilisateurs'];
$role = $user['fonction'] ?? null;

$depotId = 0;
if (isset($_GET['depot_id'])) {
    $depotId = (int)$_GET['depot_id'];
} elseif (isset($_GET['id'])) {
    $depotId = (int)$_GET['id'];
}

// Listes pour la modale (identique à stock_depot.php)
$allChantiers = $pdo->query("SELECT id, nom FROM chantiers")->fetchAll(PDO::FETCH_KEY_PAIR);
$allDepots    = $pdo->query("SELECT id, nom FROM depots")->fetchAll(PDO::FETCH_KEY_PAIR);

// Récup infos dépôt
$stmtDepot = $pdo->prepare("SELECT id, nom, responsable_id FROM depots WHERE id = ?");
$stmtDepot->execute([$depotId]);
$depot = $stmtDepot->fetch(PDO::FETCH_ASSOC);

if (!$depot) {
    echo '<div class="container mt-4 alert alert-danger">Dépôt introuvable.</div>';
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

// Sécurité : admin OK ; role "depot" OK seulement si responsable de CE dépôt
$allowed = ($role === 'administrateur') || ($role === 'depot' && (int)$depot['responsable_id'] === (int)$user['id']);
if (!$allowed) {
    echo '<div class="container mt-4 alert alert-danger">Accès refusé.</div>';
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

// ----------- Données (uniquement pour CE dépôt) ------------

// Articles présents dans le dépôt (quantité > 0)
$sql = "
    SELECT 
        s.id             AS article_id,
        s.nom            AS article_nom,
        s.photo          AS photo,
        s.categorie      AS categorie,
        s.sous_categorie AS sous_categorie,
        SUM(COALESCE(sd.quantite,0)) AS quantite
    FROM stock s
    JOIN stock_depots sd ON sd.stock_id = s.id
    WHERE sd.depot_id = :depot_id
      AND COALESCE(sd.quantite,0) > 0
    GROUP BY s.id, s.nom, s.photo, s.categorie, s.sous_categorie
    ORDER BY s.nom ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':depot_id' => $depotId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Catégories présentes dans CE dépôt
$stmtCats = $pdo->prepare("
    SELECT DISTINCT s.categorie
    FROM stock s
    JOIN stock_depots sd ON sd.stock_id = s.id
    WHERE sd.depot_id = :depot_id
      AND COALESCE(sd.quantite,0) > 0
      AND s.categorie IS NOT NULL AND s.categorie <> ''
    ORDER BY s.categorie
");
$stmtCats->execute([':depot_id' => $depotId]);
$categories = $stmtCats->fetchAll(PDO::FETCH_COLUMN);

// Sous-catégories groupées par catégorie (pour CE dépôt)
$stmtSubs = $pdo->prepare("
    SELECT DISTINCT s.categorie, s.sous_categorie
    FROM stock s
    JOIN stock_depots sd ON sd.stock_id = s.id
    WHERE sd.depot_id = :depot_id
      AND COALESCE(sd.quantite,0) > 0
      AND s.sous_categorie IS NOT NULL AND s.sous_categorie <> ''
");
$stmtSubs->execute([':depot_id' => $depotId]);
$subCategoriesGrouped = [];
foreach ($stmtSubs->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $subCategoriesGrouped[$r['categorie']][] = $r['sous_categorie'];
}
// déduplication
foreach ($subCategoriesGrouped as $k => $arr) {
    $subCategoriesGrouped[$k] = array_values(array_unique($arr));
}
?>

<div class="container mt-4">
    <div class="mb-4 text-center">
        <h1 class="mb-4 text-center">Stock du dépôt : <?= htmlspecialchars($depot['nom']) ?></h1>
    </div>

    <!-- Filtres catégories/sous-catégories (identiques à la page admin) -->
    <div class="d-flex justify-content-center mb-3 flex-wrap gap-2" id="categoriesSlide">
        <button class="btn btn-outline-primary" data-cat="">Tous</button>
        <?php foreach ($categories as $cat): ?>
            <button class="btn btn-outline-primary" data-cat="<?= htmlspecialchars($cat) ?>">
                <?= htmlspecialchars(ucfirst($cat)) ?>
            </button>
        <?php endforeach; ?>
    </div>
    <div id="subCategoriesSlide" class="d-flex justify-content-center mb-4 flex-wrap gap-2"></div>

    <!-- Recherche instantanée -->
    <input type="text" id="searchInput" class="form-control mb-4" placeholder="Rechercher un article...">

    <div class="table-responsive">
        <table class="table table-bordered table-hover text-center align-middle">
            <thead class="table-dark">
                <tr>
                    <th class="text-center">Photo</th>
                    <th class="text-center">Article</th>
                    <th class="text-center">Quantité</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>

            <tbody id="stockTableBody">
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">Aucun article dans ce dépôt.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $photoWeb = !empty($r['photo']) ? '/' . ltrim($r['photo'], '/') : '';
                        $cat = $r['categorie'] ?? '';
                        $sub = $r['sous_categorie'] ?? '';
                        $qte = (int)$r['quantite'];
                        ?>
                        <tr
                            data-cat="<?= htmlspecialchars($cat) ?>"
                            data-subcat="<?= htmlspecialchars($sub) ?>">
                            <td class="text-center" style="width:64px">
                                <?php if (!empty($photoWeb)): ?>
                                    <img src="<?= htmlspecialchars($photoWeb) ?>" alt="" class="img-thumbnail" style="width:56px;height:56px;object-fit:cover;">
                                <?php else: ?>
                                    <div class="bg-light border rounded d-inline-flex align-items-center justify-content-center" style="width:56px;height:56px;">—</div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <a href="../stock/article.php?id=<?= (int)$r['article_id'] ?>" class="text-decoration-underline fw-bold text-primary article-name">
                                    <?= htmlspecialchars(ucfirst(strtolower($r['article_nom']))) ?>
                                </a>
                                <?php if ($cat || $sub): ?>
                                    <div class="small text-muted">
                                        <?= htmlspecialchars($cat ?: '—') ?><?= $sub ? ' • ' . htmlspecialchars($sub) : '' ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td class="text-center fw-semibold">
                                <span class="badge <?= $qte < 10 ? 'bg-danger' : 'bg-success' ?>"><?= $qte ?></span>
                            </td>

                            <td class="text-center">
                                <button
                                    class="btn btn-sm btn-primary transfer-btn"
                                    data-stock-id="<?= (int)$r['article_id'] ?>"
                                    data-stock-nom="<?= htmlspecialchars($r['article_nom']) ?>"
                                    title="Transférer"
                                    aria-label="Transférer">
                                    <i class="bi bi-arrow-left-right"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
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
                    <input type="hidden" id="sourceDepotId" name="source_depot_id" value="<?= (int)$depotId ?>">
                    <div class="mb-3">
                        <label>Destination</label>
                        <select class="form-select" id="destinationChantier">
                            <option value="" disabled selected>Choisir la destination</option>
                            <optgroup label="Dépôts">
                                <?php foreach ($allDepots as $id => $nom): ?>
                                    <?php if ((int)$id !== (int)$depotId): ?>
                                        <option value="depot_<?= (int)$id ?>"><?= htmlspecialchars($nom) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Chantiers">
                                <?php foreach ($allChantiers as $id => $nom): ?>
                                    <option value="chantier_<?= (int)$id ?>"><?= htmlspecialchars($nom) ?></option>
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

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1100">
  <div id="toastMessage" class="toast align-items-center text-bg-primary border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body"></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Fermer"></button>
    </div>
  </div>
</div>

<script>
  // Injection des sous-catégories pour JS
  window.subCategories = <?= json_encode($subCategoriesGrouped) ?>;
</script>
<!-- Si le JS reste dans /js : -->
<script src="./js/depot_contenu.js"></script>
<!-- Si tu préfères déplacer le JS dans /depots, renomme le fichier en /depots/depot_contenu.js et remplace la ligne ci-dessus par :
<script src="./depot_contenu.js"></script>
-->

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
