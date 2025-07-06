<?php
require_once "./config/init.php";
require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/navigation/navigation.php';

if (!isset($_SESSION['utilisateurs'])) {
    header("Location: connexion.php");
    exit;
}

$utilisateurChantierId = $_SESSION['utilisateurs']['chantier_id'] ?? null;


// Récupère tous les chantiers
$allChantiers = $pdo->query("SELECT id, nom FROM chantiers")->fetchAll(PDO::FETCH_KEY_PAIR);

// Récupère les associations stock ↔ chantiers
$stmt = $pdo->query("
    SELECT sc.stock_id, c.id AS chantier_id, c.nom AS chantier_nom, sc.quantite
    FROM stock_chantiers sc
    JOIN chantiers c ON sc.chantier_id = c.id
");
$chantierAssoc = [];
foreach ($stmt as $row) {
    $chantierAssoc[$row['stock_id']][] = [
        'id' => $row['chantier_id'],
        'nom' => $row['chantier_nom'],
        'quantite' => $row['quantite']
    ];
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
                    <th>Photo</th>
                    <th>Quantité</th>
                    <?php if ($_SESSION['utilisateurs']['fonction'] === 'administrateur'): ?>
                        <th>Chantiers</th>
                    <?php else: ?>
                        <th>Mon chantier</th>
                    <?php endif; ?>

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
                    $stockId = $stock['id'];
                    $quantite = 0;

                    if ($utilisateurChantierId && !empty($chantierAssoc[$stockId])) {
                        foreach ($chantierAssoc[$stockId] as $chantier) {
                            if ((int)$chantier['id'] === (int)$utilisateurChantierId) {
                                $quantite = (int)$chantier['quantite'];
                                break;
                            }
                        }
                    }
                    ?>
                    <tr data-cat="<?= htmlspecialchars($stock['categorie']) ?>" data-subcat="<?= htmlspecialchars($stock['sous_categorie']) ?>">
                        <td>
                            <a href="article.php?id=<?= urlencode($stockId) ?>">
                                <?= htmlspecialchars($nom) ?> (<?= $total ?>)
                            </a>
                        </td>
                        <td class="text-center">
                            <img src="uploads/photos/<?= $stockId ?>.jpg" alt="<?= htmlspecialchars($nom) ?>" style="height: 40px;">
                        </td>
                        <td class="text-center quantite-col">

                            <span class="badge bg-success"><?= $dispo ?> dispo</span>
                            <span class="badge bg-warning text-dark"><?= $surChantier ?> sur chantier</span>
                        </td>
                        <?php if ($_SESSION['utilisateurs']['fonction'] === 'administrateur'): ?>
                            <td class="text-center admin-col">

                                <?php if (!empty($chantierAssoc[$stockId])): ?>
                                    <?php foreach ($chantierAssoc[$stockId] as $chantier): ?>
                                        <div><?= htmlspecialchars($chantier['nom']) ?> (<?= (int)$chantier['quantite'] ?>)</div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="text-muted">Aucun</span>
                                <?php endif; ?>
                            </td>
                        <?php else: ?>
                            <td class="text-center chantier-col" data-stock-id="<?= $stockId ?>" data-chantier-id="<?= $utilisateurChantierId ?>">
                                <?= $quantite ?>
                            </td>
                        <?php endif; ?>

                        <td class="text-center">
                            <button
                                class="btn btn-sm btn-primary transfer-btn"
                                data-stock-id="<?= $stockId ?>"
                                data-stock-nom="<?= htmlspecialchars($nom) ?>">
                                <i class="bi bi-arrow-left-right"></i> Transférer
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal de transfert -->
<div class="modal fade" id="transferModal" tabindex="-1" aria-labelledby="transferModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title" id="transferModalLabel">Transfert d'article</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>

            <div class="modal-body">
                <form id="transferForm">
                    <input type="hidden" id="modalStockId">

                    <!-- Si l'utilisateur est administrateur, on affiche la sélection du chantier source -->
                    <?php if ($_SESSION['utilisateurs']['fonction'] === 'administrateur'): ?>
                        <div class="mb-3">
                            <label for="sourceChantier" class="form-label">Chantier d'origine</label>
                            <select class="form-select" id="sourceChantier" required>
                                <option value="" disabled selected>Choisir le chantier source</option>
                                <?php foreach ($allChantiers as $id => $nom): ?>
                                    <option value="<?= $id ?>"><?= htmlspecialchars($nom) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label for="destination" class="form-label">Destination</label>
                        <select class="form-select" id="destination" required>
                            <option value="" disabled selected>Choisir la destination</option>
                            <option value="depot">Dépôt</option>
                            <?php foreach ($allChantiers as $id => $nom): ?>
                                <option value="<?= $id ?>"><?= htmlspecialchars($nom) ?></option>
                            <?php endforeach; ?>
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
                <button type="submit" class="btn btn-primary" id="confirmTransfer">OK</button>
            </div>
        </div>
    </div>
</div>



<script>
    const subCategories = <?= json_encode($subCategoriesGrouped) ?>;
</script>
<script src="/js/stock.js"></script>
<script src="/js/stockGestion.js"></script>

<!-- Toast Bootstrap -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 9999">
    <div id="transferToast" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body">
                ✅ Transfert effectué avec succès !
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Fermer"></button>
        </div>
    </div>
</div>


<?php require_once __DIR__ . '/templates/footer.php'; ?>