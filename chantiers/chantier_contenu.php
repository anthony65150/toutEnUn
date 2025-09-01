<?php
require_once __DIR__ . '/../config/init.php';

if (!isset($_SESSION['utilisateurs'])) {
    header("Location: /connexion.php");
    exit;
}
$entrepriseId = (int)($_SESSION['entreprise_id'] ?? 0);
if (!$entrepriseId) {
    http_response_code(403);
    exit('Entreprise non définie.');
}

$user = $_SESSION['utilisateurs'];
$role = $user['fonction'] ?? null;

/* ===== ID chantier depuis ?id ou ?chantier_id ===== */
$chantierId = 0;
if (isset($_GET['chantier_id'])) {
    $chantierId = (int)$_GET['chantier_id'];
} elseif (isset($_GET['id'])) {
    $chantierId = (int)$_GET['id'];
}
if (!$chantierId) {
    require_once __DIR__ . '/../templates/header.php';
    require_once __DIR__ . '/../templates/navigation/navigation.php';
    echo '<div class="container mt-4 alert alert-danger">ID de chantier manquant.</div>';
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

/* ===== Charger le chantier de l’entreprise ===== */
$stCh = $pdo->prepare("SELECT id, nom FROM chantiers WHERE id = ? AND entreprise_id = ?");
$stCh->execute([$chantierId, $entrepriseId]);
$chantier = $stCh->fetch(PDO::FETCH_ASSOC);

if (!$chantier) {
    require_once __DIR__ . '/../templates/header.php';
    require_once __DIR__ . '/../templates/navigation/navigation.php';
    echo '<div class="container mt-4 alert alert-danger">Chantier introuvable pour cette entreprise.</div>';
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

/* ===== Droits d’accès =====
   - admin : OK
   - chef  : OK s’il est assigné à ce chantier dans la même entreprise
*/
$allowed = false;
if ($role === 'administrateur') {
    $allowed = true;
} elseif ($role === 'chef') {
    // si votre table utilisateur_chantiers n’a pas entreprise_id, supprimez la condition AND entreprise_id = :eid
    $stmtAuth = $pdo->prepare("
        SELECT 1 FROM utilisateur_chantiers
        WHERE utilisateur_id = :uid AND chantier_id = :cid AND entreprise_id = :eid
        LIMIT 1
    ");
    $stmtAuth->execute([':uid' => (int)$user['id'], ':cid' => $chantierId, ':eid' => $entrepriseId]);
    $allowed = (bool)$stmtAuth->fetchColumn();
}
if (!$allowed) {
    require_once __DIR__ . '/../templates/header.php';
    require_once __DIR__ . '/../templates/navigation/navigation.php';
    echo '<div class="container mt-4 alert alert-danger">Accès refusé.</div>';
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

/* ===== Listes pour la modale transfert (scopées entreprise) ===== */
$allChantiers = $pdo->prepare("SELECT id, nom FROM chantiers WHERE entreprise_id = ? ORDER BY nom");
$allChantiers->execute([$entrepriseId]);
$allChantiers = $allChantiers->fetchAll(PDO::FETCH_KEY_PAIR);

$allDepots = $pdo->prepare("SELECT id, nom FROM depots WHERE entreprise_id = ? ORDER BY nom");
$allDepots->execute([$entrepriseId]);
$allDepots = $allDepots->fetchAll(PDO::FETCH_KEY_PAIR);

/* ===== Données stock de CE chantier =====
   Si stock_chantiers n’a pas entreprise_id, on sécurise via un JOIN chantiers c (qui lui l’a).
*/
$sql = "
    SELECT 
        s.id             AS article_id,
        s.nom            AS article_nom,
        s.photo          AS photo,
        s.categorie      AS categorie,
        s.sous_categorie AS sous_categorie,
        SUM(COALESCE(sc.quantite,0)) AS quantite
    FROM stock s
    JOIN stock_chantiers sc ON sc.stock_id = s.id
    JOIN chantiers c        ON c.id = sc.chantier_id
    WHERE sc.chantier_id = :cid
      AND c.entreprise_id = :eid
      AND COALESCE(sc.quantite,0) > 0
    GROUP BY s.id, s.nom, s.photo, s.categorie, s.sous_categorie
    ORDER BY s.nom ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':cid' => $chantierId, ':eid' => $entrepriseId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Catégories présentes sur CE chantier */
$stmtCats = $pdo->prepare("
    SELECT DISTINCT s.categorie
    FROM stock s
    JOIN stock_chantiers sc ON sc.stock_id = s.id
    JOIN chantiers c        ON c.id = sc.chantier_id
    WHERE sc.chantier_id = :cid
      AND c.entreprise_id = :eid
      AND COALESCE(sc.quantite,0) > 0
      AND s.categorie IS NOT NULL AND s.categorie <> ''
    ORDER BY s.categorie
");
$stmtCats->execute([':cid' => $chantierId, ':eid' => $entrepriseId]);
$categories = $stmtCats->fetchAll(PDO::FETCH_COLUMN);

/* Sous-catégories groupées */
$stmtSubs = $pdo->prepare("
    SELECT DISTINCT s.categorie, s.sous_categorie
    FROM stock s
    JOIN stock_chantiers sc ON sc.stock_id = s.id
    JOIN chantiers c        ON c.id = sc.chantier_id
    WHERE sc.chantier_id = :cid
      AND c.entreprise_id = :eid
      AND COALESCE(sc.quantite,0) > 0
      AND s.sous_categorie IS NOT NULL AND s.sous_categorie <> ''
");
$stmtSubs->execute([':cid' => $chantierId, ':eid' => $entrepriseId]);
$subCategoriesGrouped = [];
foreach ($stmtSubs->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $subCategoriesGrouped[$r['categorie']][] = $r['sous_categorie'];
}
foreach ($subCategoriesGrouped as $k => $arr) {
    $subCategoriesGrouped[$k] = array_values(array_unique($arr));
}

/* ======= OUTPUT ======= */
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/navigation/navigation.php';
?>
<style>
    .article-name, .article-name:hover, .article-name:focus { text-decoration: none !important; }
</style>

<div class="container mt-4">
    <div class="mb-4 text-center">
        <h1 class="mb-4 text-center">Stock du chantier : <?= htmlspecialchars($chantier['nom']) ?></h1>
    </div>

    <!-- Filtres -->
    <div class="d-flex justify-content-center mb-3 flex-wrap gap-2" id="categoriesSlide">
        <button class="btn btn-outline-primary" data-cat="">Tous</button>
        <?php foreach ($categories as $cat): ?>
            <button class="btn btn-outline-primary" data-cat="<?= htmlspecialchars($cat) ?>">
                <?= htmlspecialchars(ucfirst($cat)) ?>
            </button>
        <?php endforeach; ?>
    </div>
    <div id="subCategoriesSlide" class="d-flex justify-content-center mb-4 flex-wrap gap-2"></div>

    <!-- Recherche -->
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
                        <td colspan="4" class="text-center text-muted py-4">Aucun article sur ce chantier.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $photoWeb = !empty($r['photo']) ? '/' . ltrim($r['photo'], '/') : '';
                        $cat = $r['categorie'] ?? '';
                        $sub = $r['sous_categorie'] ?? '';
                        $qte = (int)$r['quantite'];
                        ?>
                        <tr data-cat="<?= htmlspecialchars($cat) ?>" data-subcat="<?= htmlspecialchars($sub) ?>">
                            <td class="text-center" style="width:64px">
                                <?php if (!empty($photoWeb)): ?>
                                    <img src="<?= htmlspecialchars($photoWeb) ?>" alt="" class="img-thumbnail" style="width:56px;height:56px;object-fit:cover;">
                                <?php else: ?>
                                    <div class="bg-light border rounded d-inline-flex align-items-center justify-content-center" style="width:56px;height:56px;">—</div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <a href="../stock/article.php?id=<?= (int)$r['article_id'] ?>" class="link-primary fw-semibold text-decoration-none article-name">
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
                                    data-bs-toggle="modal"
                                    data-bs-target="#transferModal"
                                    data-stock-id="<?= (int)$r['article_id'] ?>"
                                    data-stock-nom="<?= htmlspecialchars($r['article_nom']) ?>"
                                    data-source-type="chantier"
                                    data-source-id="<?= (int)$chantier['id'] ?>"
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
                <form id="transferForm" action="./transferStock_chef.php" method="post">
                    <input type="hidden" id="articleId" name="article_id">
                    <input type="hidden" id="sourceChantierId" name="source_chantier_id" value="<?= (int)$chantierId ?>">
                    <?php if (!empty($_SESSION['csrf_token'])): ?>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label>Destination</label>
                        <select class="form-select" id="destinationSelect">
                            <option value="" disabled selected>Choisir la destination</option>
                            <optgroup label="Dépôts">
                                <?php foreach ($allDepots as $id => $nom): ?>
                                    <option value="depot_<?= (int)$id ?>"><?= htmlspecialchars($nom) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Chantiers">
                                <?php foreach ($allChantiers as $id => $nom): ?>
                                    <?php if ((int)$id !== (int)$chantierId): ?>
                                        <option value="chantier_<?= (int)$id ?>"><?= htmlspecialchars($nom) ?></option>
                                    <?php endif; ?>
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
  window.CSRF_TOKEN = "<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>";
</script>

<script>
    window.subCategories = <?= json_encode($subCategoriesGrouped) ?>;
</script>
<script src="./js/chantier_contenu.js"></script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
