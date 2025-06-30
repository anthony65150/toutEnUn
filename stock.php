<?php
require_once "./config/init.php";
require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/navigation/navigation.php';

if (!isset($_SESSION['utilisateurs'])) {
    header("Location: connexion.php");
    exit;
}

// Récupération des stocks depuis la BDD
$stmt = $pdo->query("SELECT id, nom, quantite_totale AS total, quantite_disponible AS disponible, categorie, sous_categorie FROM stock ORDER BY nom");
$stocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération des catégories uniques
$stmtCat = $pdo->query("SELECT DISTINCT categorie FROM stock WHERE categorie IS NOT NULL ORDER BY categorie");
$categories = $stmtCat->fetchAll(PDO::FETCH_COLUMN);

// Récupération des sous-catégories associées
$stmtSubCat = $pdo->query("SELECT categorie, sous_categorie FROM stock WHERE sous_categorie IS NOT NULL");
$subCategoriesRaw = $stmtSubCat->fetchAll(PDO::FETCH_ASSOC);

// Regroupe les sous-catégories par catégorie
$subCategoriesGrouped = [];
foreach ($subCategoriesRaw as $row) {
    $subCategoriesGrouped[$row['categorie']][] = $row['sous_categorie'];
}
?>

<div class="container py-5">
    <div class="container py-2">
        <div class="d-flex justify-content-between align-items-center mb-5 flex-wrap">
            <div class="flex-grow-1 text-center">
                <h2 class="mb-0">Gestion de stock</h2>
            </div>
            <?php if ($_SESSION['utilisateurs']['fonction'] === 'administrateur') : ?>
                <div class="text-end">
                    <a href="ajoutStock.php" class="btn btn-success">
                        <i class="bi bi-plus-circle"></i> Ajouter un élément
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Slide horizontal de catégories dynamiques -->
        <div class="pb-2">
            <div class="d-flex justify-content-center">
                <div id="categoriesSlide" class="d-flex gap-3 px-2 overflow-auto" style="max-width: 100%; -webkit-overflow-scrolling: touch; scroll-behavior: smooth;">
                    <button class="btn btn-outline-primary flex-shrink-0" onclick="filterByCategory('')">Tous</button>
                    <?php foreach ($categories as $cat): ?>
                        <button class="btn btn-outline-primary flex-shrink-0" onclick="filterByCategory('<?= htmlspecialchars($cat) ?>')">
                            <?= ucfirst(htmlspecialchars($cat)) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Sous-catégories dynamiques -->
        <div id="subCategoriesSlide" class="overflow-auto pb-2 mt-3 d-flex gap-3 justify-content-center flex-nowrap"></div>
    </div>

    <!-- Champ de recherche -->
    <div class="mb-4">
        <input type="text" id="searchInput" class="form-control" placeholder="Rechercher un article...">
    </div>

    <!-- Tableau du stock -->
    <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle">
            <thead class="table-dark text-center">
                <tr>
                    <th>Nom</th>
                    <th>Quantité</th>
                    <th>Catégorie</th>
                    <th>Sous-catégorie</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="stockTableBody">
                <?php foreach ($stocks as $stock): ?>
                    <?php
                    $nom = $stock['nom'];
                    $total = (int)$stock['total'];
                    $dispo = (int)$stock['disponible'];
                    $surChantier = $total - $dispo;
                    ?>
                    <tr data-cat="<?= htmlspecialchars($stock['categorie']) ?>" data-subcat="<?= htmlspecialchars($stock['sous_categorie']) ?>">
                        <td>
                            <a href="article.php?id=<?= urlencode($stock['id']) ?>">
                                <?= htmlspecialchars($nom) ?> (<?= $total ?>)
                            </a>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-success"><?= $dispo ?> dispo</span>
                            <span class="badge bg-warning text-dark"><?= $surChantier ?> sur chantier</span>
                        </td>
                        <td><?= htmlspecialchars($stock['categorie']) ?></td>
                        <td><?= htmlspecialchars($stock['sous_categorie'] ?: '—') ?></td>
                        <td class="text-center">
                            <a href="#" class="btn btn-sm btn-warning"><i class="bi bi-pencil"></i></a>
                            <a href="#" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    const subCategories = <?= json_encode($subCategoriesGrouped) ?>;
</script>
<script src="/js/stock.js"></script>
<?php require_once __DIR__ . '/templates/footer.php'; ?>
