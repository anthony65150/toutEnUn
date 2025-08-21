<?php
require_once "./config/init.php";


if (!isset($_SESSION['utilisateurs'])) {
    header("Location: index.php");
    exit;
}

// Chargement des chantiers liés à l'utilisateur
$utilisateurId = $_SESSION['utilisateurs']['id'];

$stmt = $pdo->prepare("SELECT chantier_id FROM utilisateur_chantiers WHERE utilisateur_id = ?");
$stmt->execute([$utilisateurId]);
$chefChantiers = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Mise à jour de la session pour d'autres pages (optionnel mais conseillé)
$_SESSION['utilisateurs']['chantiers'] = $chefChantiers;

$utilisateurChantierId = isset($_GET['chantier_id']) ? (int)$_GET['chantier_id'] : null;
if (!$utilisateurChantierId && !empty($_SESSION['utilisateurs']['chantiers'])) {
    // Choix par défaut si non défini dans l’URL
    $utilisateurChantierId = $_SESSION['utilisateurs']['chantiers'][0] ?? null;
    if ($utilisateurChantierId) {
        header("Location: stock_chef.php?chantier_id=$utilisateurChantierId");
        exit;
    }
}


// Si un seul chantier, rediriger automatiquement
if (!$utilisateurChantierId && count($chefChantiers) === 1) {
    $chantierId = $chefChantiers[0];
    header("Location: stock_chef.php?chantier_id=$chantierId");
    exit;
}



// ⛔ Si aucun chantier ou accès non autorisé, on bloque
if (!$utilisateurChantierId || !in_array($utilisateurChantierId, $chefChantiers)) {
    $_SESSION['error_message'] = "Accès à ce chantier non autorisé.";
    header("Location: index.php");
    exit;
}


// Chantiers disponibles
$allChantiers = $pdo->query("SELECT id, nom FROM chantiers")->fetchAll(PDO::FETCH_KEY_PAIR);
$allDepots = $pdo->query("SELECT id, nom FROM depots")->fetchAll(PDO::FETCH_KEY_PAIR);
$pageTitle = "Stock - " . ($allChantiers[$utilisateurChantierId] ?? "Chantier inconnu");


require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/navigation/navigation.php';


$inClause = implode(',', array_map('intval', $chefChantiers));
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


$stmt = $pdo->query("SELECT sd.stock_id, d.id AS depot_id, d.nom AS depot_nom, sd.quantite FROM stock_depots sd JOIN depots d ON sd.depot_id = d.id");
$depotAssoc = [];
foreach ($stmt as $row) {
    $depotAssoc[$row['stock_id']][] = ['id' => $row['depot_id'], 'nom' => $row['depot_nom'], 'quantite' => (int)$row['quantite']];
}

$stocks = $pdo->query("
  SELECT id, nom, photo, quantite_totale, categorie, sous_categorie
  FROM stock
  ORDER BY nom
")->fetchAll(PDO::FETCH_ASSOC);

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

    <h2 class="text-center mb-4">Stock - <?= htmlspecialchars($allChantiers[$utilisateurChantierId] ?? 'Chantier inconnu') ?></h2>

    <?php if (count($chefChantiers) > 1): ?>
        <div class="d-flex justify-content-center gap-2 mb-4 flex-wrap">
            <?php foreach ($chefChantiers as $chantierId): ?>
                <a href="?chantier_id=<?= $chantierId ?>" class="btn btn-outline-secondary <?= $chantierId == $utilisateurChantierId ? 'active' : '' ?>">
                    <?= htmlspecialchars($allChantiers[$chantierId] ?? 'Chantier') ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>


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
                                    <button type="submit" class="btn btn-success btn-sm me-1">✅ Valider</button>
                                </form>

                                <form method="post" action="annulerTransfert_chef.php" style="display:inline;">
                                    <input type="hidden" name="transfert_id" value="<?= $t['transfert_id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">❌ Refuser</button>
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
                    <th class="col-photo">Photo</th>
                    <th>Articles</th>
                    <th>Dépôts</th>
                    <th>Chantiers</th>
                    <th>Mon chantier</th>
                    <th>Action</th>
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
                    $depotsHtml = $depotsList
                        ? implode('<br>', array_map(function ($d) {
                            $full  = trim((string)$d['nom']);
                            // garde les accents correctement
                            $short = function_exists('mb_substr') ? mb_substr($full, 0, 4, 'UTF-8') : substr($full, 0, 4);
                            $q     = (int)$d['quantite'];

                            return '<span class="depot-nom">'
                                .   '<span class="name-full">'  . htmlspecialchars($full)  . '</span>'
                                .   '<span class="name-short">' . htmlspecialchars($short) . '</span> '
                                .   '<span class="qty">(' . $q . ')</span>'
                                . '</span>';
                        }, $depotsList))
                        : '<span class="text-muted">Aucun</span>';



                    $chantiersHtml = '<span class="text-muted">Aucun</span>';
                    if ($chantiersList) {
                        $quantiteMonChantier = 0;

                        // Séparer le chantier courant et les autres
                        $autresChantiers = [];
                        foreach ($chantiersList as $c) {
                            if ($c['id'] == $utilisateurChantierId) {
                                $quantiteMonChantier = $c['quantite'];
                            } elseif ($c['quantite'] > 0) {  // ⛔ On ne garde que les chantiers avec stock > 0
                                $autresChantiers[] = $c;
                            }
                        }

                        // 🔽 Trier les autres chantiers par quantité décroissante
                        usort($autresChantiers, fn($a, $b) => $b['quantite'] <=> $a['quantite']);

                        // Générer le HTML
                        $chantiersHtml = '';
                        foreach ($autresChantiers as $chantier) {
                            $chantiersHtml .= htmlspecialchars($chantier['nom']) . ' (' . $chantier['quantite'] . ')<br>';
                        }

                        if (empty($chantiersHtml)) {
                            $chantiersHtml = '<span class="text-muted">Aucun</span>';
                        }


                        if (empty($chantiersHtml)) {
                            $chantiersHtml = '<span class="text-muted">Aucun</span>';
                        }


                        if ($chantiersHtml === '') $chantiersHtml = '<span class="text-muted">Aucun</span>';
                    }
                    $badge = $quantiteMonChantier > 0 ? '<span class="badge bg-success">' . $quantiteMonChantier . '</span>' : '<span class="badge bg-danger">0</span>';
                    ?>
                    <?php
                    $catSafe    = strtolower(trim((string)($stock['categorie'] ?? '')));
                    $subcatSafe = strtolower(trim((string)($stock['sous_categorie'] ?? '')));
                    ?>
                    <tr data-cat="<?= htmlspecialchars($catSafe) ?>"
                        data-subcat="<?= htmlspecialchars($subcatSafe) ?>"
                        class="<?= (isset($_SESSION['highlight_stock_id']) && $_SESSION['highlight_stock_id'] == $stock['id']) ? 'table-success highlight-row' : '' ?>">



                        <!-- PHOTO -->
                        <td class="text-center col-photo" style="width:64px">
                            <?php
                            $photoWeb = '';
                            if (!empty($stock['photo'])) {
                                $photoWeb = '/' . ltrim($stock['photo'], '/');
                            } else {
                                $fallbackLocal = __DIR__ . "/uploads/photos/{$stockId}.jpg";
                                if (is_file($fallbackLocal)) {
                                    $photoWeb = "/uploads/photos/{$stockId}.jpg";
                                }
                            }
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
                            <a href="article.php?id=<?= (int)$stockId ?>&chantier_id=<?= (int)$utilisateurChantierId ?>"
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
    window.chefChantierActuel = <?= (int)($_GET['chantier_id'] ?? 0) ?>;
</script>




<script>
    const subCategories = <?= json_encode($subCategoriesGrouped) ?>;
</script>
<script src="/js/stockGestion_chef.js"></script>
<?php require_once __DIR__ . '/templates/footer.php'; ?>