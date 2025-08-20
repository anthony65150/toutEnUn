<?php
require_once "./config/init.php";
require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/navigation/navigation.php';

if (!isset($_SESSION['utilisateurs'])) {
    header("Location: connexion.php");
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

// Récup infos dépôt
$stmtDepot = $pdo->prepare("SELECT id, nom, responsable_id FROM depots WHERE id = ?");
$stmtDepot->execute([$depotId]);
$depot = $stmtDepot->fetch(PDO::FETCH_ASSOC);

if (!$depot) {
    echo '<div class="container mt-4 alert alert-danger">Dépôt introuvable.</div>';
    require_once __DIR__ . '/templates/footer.php';
    exit;
}

// Sécurité : admin OK ; role "depot" OK seulement si responsable de CE dépôt
$allowed = ($role === 'administrateur') || ($role === 'depot' && (int)$depot['responsable_id'] === (int)$user['id']);
if (!$allowed) {
    echo '<div class="container mt-4 alert alert-danger">Accès refusé.</div>';
    require_once __DIR__ . '/templates/footer.php';
    exit;
}

// ----------- Données (uniquement pour CE dépôt) ------------

// Articles présents dans le dépôt (quantité > 0)
$sql = "
    SELECT 
        s.id           AS article_id,
        s.nom          AS article_nom,
        s.photo        AS photo,
        s.categorie    AS categorie,
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
                                <a href="article.php?id=<?= (int)$r['article_id'] ?>" class="text-decoration-underline fw-bold text-primary article-name">
                                    <?= htmlspecialchars(ucfirst(strtolower($r['article_nom']))) ?>
                                </a>
                                <?php if ($cat || $sub): ?>
                                    <div class="small text-muted">
                                        <?= htmlspecialchars($cat ?: '—') ?><?= $sub ? ' • ' . htmlspecialchars($sub) : '' ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td class="text-center fw-semibold">
                                <?php $qte = (int)$r['quantite']; ?>
                                <span class="badge <?= $qte < 10 ? 'bg-danger' : 'bg-success' ?>"><?= $qte ?></span>
                            </td>

                            <td class="text-center">
                                <a href="stock_depot.php"
                                    class="btn btn-primary btn-icon"
                                    title="Transférer" aria-label="Transférer">
                                    <i class="bi bi-arrow-left-right"></i>
                                </a>
                            </td>


                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    (function() {
        // Données sous-catégories (par catégorie)
        const subCategories = <?= json_encode($subCategoriesGrouped) ?>;

        const $catsWrap = document.getElementById('categoriesSlide');
        const $subsWrap = document.getElementById('subCategoriesSlide');
        const $rowsBody = document.getElementById('stockTableBody');
        const $search = document.getElementById('searchInput');

        let currentCat = '';
        let currentSub = '';
        let searchTerm = '';

        function normalize(str) {
            return (str || '').toString().toLowerCase().normalize('NFD').replace(/\p{Diacritic}/gu, '');
        }

        function renderSubCats(cat) {
            $subsWrap.innerHTML = '';
            if (!cat || !subCategories[cat] || subCategories[cat].length === 0) return;

            subCategories[cat].forEach(sc => {
                const b = document.createElement('button');
                b.className = 'btn btn-outline-secondary';
                b.dataset.subcat = sc;
                b.textContent = sc;
                $subsWrap.appendChild(b);
            });
        }


        function applyFilters() {
            const rows = $rowsBody.querySelectorAll('tr');
            const s = normalize(searchTerm);

            rows.forEach(tr => {
                // Skip placeholder row (no data-cat attribute)
                if (!tr.hasAttribute('data-cat')) {
                    tr.style.display = '';
                    return;
                }

                const cat = tr.getAttribute('data-cat') || '';
                const sub = tr.getAttribute('data-subcat') || '';
                const nameEl = tr.querySelector('.article-name');
                const text = normalize(nameEl ? nameEl.textContent : tr.textContent);

                const okCat = !currentCat || cat === currentCat;
                const okSub = !currentSub || sub === currentSub;
                const okSearch = !s || text.includes(s);

                tr.style.display = (okCat && okSub && okSearch) ? '' : 'none';
            });
        }

        // Catégories (délégation)
        $catsWrap.addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-cat]');
            if (!btn) return;
            // état visuel
            $catsWrap.querySelectorAll('button').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            currentCat = btn.dataset.cat || '';
            currentSub = '';
            renderSubCats(currentCat);
            // reset état visuel sous-cats
            $subsWrap.querySelectorAll('button').forEach(b => b.classList.remove('active'));
            applyFilters();
        });

        // Sous-catégories
        $subsWrap.addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-subcat]');
            if (!btn) return;
            $subsWrap.querySelectorAll('button').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            currentSub = btn.dataset.subcat || '';
            applyFilters();
        });

        // Recherche
        $search.addEventListener('input', (e) => {
            searchTerm = e.target.value || '';
            applyFilters();
        });

        // Activer "Tous" par défaut
        const firstCatBtn = $catsWrap.querySelector('button[data-cat=""]');
        if (firstCatBtn) firstCatBtn.classList.add('active');

    })();
</script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>