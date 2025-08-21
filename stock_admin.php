<?php
require_once "./config/init.php";

if (!isset($_SESSION['utilisateurs']) || $_SESSION['utilisateurs']['fonction'] !== 'administrateur') {
    header("Location: connexion.php");
    exit;
}
require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/navigation/navigation.php';

// Dépôts et chantiers
$allChantiers = $pdo->query("SELECT id, nom FROM chantiers")->fetchAll(PDO::FETCH_KEY_PAIR);
$allDepots = $pdo->query("SELECT id, nom FROM depots")->fetchAll(PDO::FETCH_KEY_PAIR);

// Quantités par dépôt (ajout de d.id AS depot_id)
$stmt = $pdo->query("SELECT sd.stock_id, d.id AS depot_id, d.nom AS depot_nom, sd.quantite FROM stock_depots sd JOIN depots d ON sd.depot_id = d.id");
$depotAssoc = [];
foreach ($stmt as $row) {
    $depotAssoc[$row['stock_id']][] = [
        'id' => $row['depot_id'],
        'nom' => $row['depot_nom'],
        'quantite' => (int)$row['quantite']
    ];
}


$stmt = $pdo->query("
    SELECT 
        sc.stock_id, 
        c.id AS chantier_id, 
        c.nom AS chantier_nom, 
        (sc.quantite - COALESCE((
            SELECT SUM(te.quantite)
            FROM transferts_en_attente te
            WHERE te.article_id = sc.stock_id
            AND te.source_type = 'chantier'
            AND te.source_id = sc.chantier_id
            AND te.statut = 'en_attente'
        ), 0)) AS quantite
    FROM stock_chantiers sc
    JOIN chantiers c ON sc.chantier_id = c.id
");
$chantierAssoc = [];
foreach ($stmt as $row) {
    $chantierAssoc[$row['stock_id']][] = [
        'id' => $row['chantier_id'],
        'nom' => $row['chantier_nom'],
        'quantite' => max(0, (int)$row['quantite'])  // pour éviter d’afficher négatif
    ];
}


// Articles + catégories/sous-catégories
$stocks = $pdo->query("SELECT id, nom, quantite_totale, categorie, sous_categorie, document, photo FROM stock ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$categories = $pdo->query("SELECT DISTINCT categorie FROM stock WHERE categorie IS NOT NULL ORDER BY categorie")->fetchAll(PDO::FETCH_COLUMN);
$subCatRaw = $pdo->query("SELECT categorie, sous_categorie FROM stock WHERE sous_categorie IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
$subCategoriesGrouped = [];
foreach ($subCatRaw as $row) {
    $subCategoriesGrouped[$row['categorie']][] = $row['sous_categorie'];
}
?>

<div class="container py-4">
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($_SESSION['success_message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($_SESSION['error_message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
        </div>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <h2 class="mb-4 text-center">Gestion de stock (Admin)</h2>
    <?php
    $stmt = $pdo->query("
    SELECT t.id AS transfert_id, s.nom AS article_nom, t.quantite, 
           u.prenom, u.nom, t.source_type, t.source_id, t.destination_type, t.destination_id
    FROM transferts_en_attente t
    JOIN stock s ON t.article_id = s.id
    JOIN utilisateurs u ON t.demandeur_id = u.id
    WHERE t.statut = 'en_attente'
");
    $transfertsEnAttente = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ?>

    <?php if ($transfertsEnAttente): ?>
        <div class="mb-5">
            <h4 class="mb-3">Transferts en attente de validation</h4>
            <table class="table table-bordered text-center align-middle">
                <thead class="table-info">
                    <tr>
                        <th>Article</th>
                        <th>Quantité</th>
                        <th>Envoyé par</th>
                        <th>Source</th>
                        <th>Destination</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transfertsEnAttente as $t): ?>
                        <tr>
                            <td><?= htmlspecialchars($t['article_nom']) ?></td>
                            <td><?= $t['quantite'] ?></td>
                            <td><?= htmlspecialchars($t['prenom'] . ' ' . $t['nom']) ?></td>
                            <td><?= ucfirst($t['source_type']) ?> <?= $t['source_id'] ?></td>
                            <td><?= ucfirst($t['destination_type']) ?> <?= $t['destination_id'] ?></td>
                            <td>
                                <form method="post" action="validerReception_admin.php" style="display:inline;">
                                    <input type="hidden" name="transfert_id" value="<?= $t['transfert_id'] ?>">
                                    <button type="submit" class="btn btn-success btn-sm me-1">✅ Valider</button>
                                </form>

                                <form method="post" action="annulerTransfert_admin.php" style="display:inline;">
                                    <input type="hidden" name="transfert_id" value="<?= $t['transfert_id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">❌ Annuler</button>
                                </form>
                            </td>


                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="text-center mb-3">

        <a href="ajoutStock.php" class="btn btn-success">
            <i class="bi bi-plus-circle"></i> Ajouter un élément
        </a>
    </div>


    <!-- Filtres catégories -->
    <div class="d-flex justify-content-center mb-3 flex-wrap gap-2" id="categoriesSlide">
        <button class="btn btn-outline-primary" onclick="filterByCategory('')">Tous</button>
        <?php foreach ($categories as $cat): ?>
            <button class="btn btn-outline-primary" onclick="filterByCategory('<?= htmlspecialchars($cat) ?>')">
                <?= htmlspecialchars(ucfirst($cat)) ?>
            </button>
        <?php endforeach; ?>
    </div>
    <div id="subCategoriesSlide" class="d-flex justify-content-center mb-4 flex-wrap gap-2"></div>

    <input type="text" id="searchInput" class="form-control mb-4" placeholder="Rechercher un article...">

    <div class="table-responsive">
        <table id="stockTable" class="table table-bordered table-hover text-center align-middle">
            <thead class="table-dark">

                <tr>
                    <th style="width:82px">Photo</th>
                    <th>Article</th>
                    <th>Dépôts</th>
                    <th>Chantiers</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="stockTableBody">
                <?php foreach ($stocks as $stock): ?>
                    <?php
                    $stockId = $stock['id'];
                    $quantiteTotale = (int)$stock['quantite_totale'];
                    $depotsList = $depotAssoc[$stockId] ?? [];
                    $chantiersList = $chantierAssoc[$stockId] ?? [];

                    $depotsHtml = $depotsList
                        ? implode('<br>', array_map(function ($d) {
                            $full  = trim((string)$d['nom']);
                            $short = function_exists('mb_substr') ? mb_substr($full, 0, 4, 'UTF-8') : substr($full, 0, 4);
                            $q     = (int)$d['quantite'];

                            return '<span class="depot-nom">'
                                .   '<span class="name-full">'  . htmlspecialchars($full)  . '</span>'
                                .   '<span class="name-short">' . htmlspecialchars($short) . '</span> '
                                .   '<span class="qty">(' . $q . ')</span>'
                                . '</span>';
                        }, $depotsList))
                        : '<span class="text-muted">Aucun</span>';
                    ?>


                    <tr data-row-id="<?= $stockId ?>"
                        data-cat="<?= htmlspecialchars($stock['categorie']) ?>"
                        data-subcat="<?= htmlspecialchars($stock['sous_categorie']) ?>"
                        class="<?= (isset($_SESSION['highlight_stock_id']) && $_SESSION['highlight_stock_id'] == $stockId) ? 'table-success highlight-row' : '' ?>">

                        <!-- PHOTO -->
                        <td class="text-center col-photo" style="width:64px">
                            <?php
                            $photoWeb = !empty($stock['photo']) ? '/' . ltrim($stock['photo'], '/') : '';
                            ?>
                            <?php if ($photoWeb): ?>
                                <img src="<?= htmlspecialchars($photoWeb) ?>"
                                    alt=""
                                    class="img-thumbnail"
                                    style="width:56px;height:56px;object-fit:cover;">
                            <?php else: ?>
                                <div class="border rounded d-inline-flex align-items-center justify-content-center"
                                    style="width:56px;height:56px;">—</div>
                            <?php endif; ?>
                        </td>

                        <!-- ARTICLE (nom cliquable + sous-texte catégorie/sous-catégorie) -->
                        <td class="text-center td-article">
                            <a href="article.php?id=<?= (int)$stock['id'] ?>"
                                class="fw-semibold text-decoration-none">
                                <?= htmlspecialchars($stock['nom']) ?>
                            </a>
                            <span class="ms-1 text-muted">(<?= (int)$quantiteTotale ?>)</span>
                            <div class="small text-muted">
                                <?php
                                $chips = [];
                                if (!empty($stock['categorie']))      $chips[] = $stock['categorie'];
                                if (!empty($stock['sous_categorie'])) $chips[] = $stock['sous_categorie'];
                                echo $chips ? htmlspecialchars(implode(' • ', $chips)) : '—';
                                ?>
                            </div>
                        </td>

                        <td class="text-center">
                            <?= $depotsHtml ?>

                        </td>
                        <td class="text-center">
                            <?php
                            // 🔽 Filtrer les chantiers avec quantité > 0
                            $chantiersAvecStock = array_filter($chantiersList, fn($c) => $c['quantite'] > 0);

                            if (count($chantiersAvecStock)):
                                // 🔽 Trier par quantité décroissante
                                usort($chantiersAvecStock, fn($a, $b) => $b['quantite'] <=> $a['quantite']);
                                foreach ($chantiersAvecStock as $c):
                            ?>
                                    <div>
                                        <?= htmlspecialchars($c['nom']) ?>
                                        (<span id="qty-source-chantier-<?= $c['id'] ?>-<?= $stockId ?>"><?= $c['quantite'] ?></span>)
                                    </div>
                                <?php
                                endforeach;
                            else:
                                ?>
                                <span class="text-muted">Aucun</span>
                            <?php endif; ?>
                        </td>




                        <td class="text-center">
                            <button class="btn btn-sm btn-primary transfer-btn" data-stock-id="<?= $stockId ?>">
                                <i class="bi bi-arrow-left-right"></i>
                            </button>
                            <?php $photoWeb = !empty($stock['photo']) ? '/' . ltrim($stock['photo'], '/') : ''; ?>
                            <button
                                class="btn btn-sm btn-warning edit-btn"
                                data-stock-id="<?= $stockId ?>"
                                data-stock-nom="<?= htmlspecialchars($stock['nom']) ?>"
                                data-stock-quantite="<?= $stock['quantite_totale'] ?>"
                                data-stock-photo="<?= htmlspecialchars($photoWeb) ?>">
                                <i class="bi bi-pencil"></i>
                            </button>




                            <button class="btn btn-sm btn-danger delete-btn" data-stock-id="<?= $stockId ?>" data-stock-nom="<?= htmlspecialchars($stock['nom']) ?>">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (isset($_SESSION['highlight_stock_id'])): ?>
            <script>
                document.addEventListener("DOMContentLoaded", () => {
                    const highlighted = document.querySelector("tr.highlight-row");
                    if (highlighted) {
                        setTimeout(() => {
                            highlighted.classList.remove("table-success", "highlight-row");
                        }, 3000);
                    }
                });
            </script>
            <?php unset($_SESSION['highlight_stock_id']); ?>
        <?php endif; ?>

    </div>
</div>

<!-- MODALE TRANSFERT -->
<div class="modal fade" id="transferModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Transfert d'article</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="modalStockId">
                <div class="mb-3">
                    <label>Source</label>
                    <select class="form-select" id="sourceChantier">
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
                    <label>Quantité à transférer</label>
                    <input type="number" class="form-control" id="transferQty" min="1">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-primary" id="confirmTransfer">Transférer</button>
            </div>
        </div>
    </div>
</div>

<!-- MODALE MODIFIER -->
<div class="modal fade" id="modifyModal" tabindex="-1" aria-labelledby="modifyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" id="modifyForm" enctype="multipart/form-data" method="post">
            <div class="modal-header">
                <h5 class="modal-title" id="modifyModalLabel">Modifier l'article</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>

            <div class="modal-body">
                <!-- ⚠️ name changé: stockId (et pas stock_id) -->
                <input type="hidden" id="modifyStockId" name="stockId">

                <!-- flags globaux -->
                <input type="hidden" id="deletePhoto" name="deletePhoto" value="0">
                <!-- plus de deleteDocument côté legacy -->
                <!-- On garde deletePhoto uniquement -->
                <input type="hidden" id="deletePhoto" name="deletePhoto" value="0">

                <div class="mb-3">
                    <label for="modifyNom" class="form-label">Nom de l'article</label>
                    <input type="text" class="form-control" id="modifyNom" name="nom">
                </div>

                <div class="mb-3">
                    <label for="modifyQty" class="form-label">Quantité totale</label>
                    <input type="number" class="form-control" id="modifyQty" name="quantite" min="0">
                </div>

                <div class="mb-3">
                    <label for="modifyPhoto" class="form-label">Nouvelle photo (optionnel)</label>
                    <input type="file" class="form-control" id="modifyPhoto" name="photo" accept="image/*">
                    <div id="existingPhoto" class="mt-2"></div>
                </div>

                <!-- ===== Documents (multi) ===== -->
                <div class="mb-3">
                    <label class="form-label">Documents existants</label>
                    <div id="existingDocs" class="d-flex flex-column gap-2"></div>
                    <div class="form-text">Clique sur la corbeille pour supprimer un document.</div>
                </div>

                <div class="mb-3">
                    <label for="modifierDocument" class="form-label">Ajouter des documents</label>
                    <input
                        type="file"
                        class="form-control"
                        id="modifierDocument"
                        name="documents[]"
                        multiple
                        accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.webp">
                    <div id="newDocsPreview" class="mt-2 d-flex flex-column gap-1"></div>
                </div>

                <!-- Suppressions en lot (rempli par le JS au submit) -->
                <input type="hidden" id="deleteDocIds" name="deleteDocIds" value="[]">
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="submit" class="btn btn-primary" id="confirmModify">Enregistrer</button>
            </div>
        </form>
    </div>
</div>



<!-- MODALE SUPPRIMER -->
<div class="modal fade" id="confirmDeleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Confirmer la suppression</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
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

<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 9999;">
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