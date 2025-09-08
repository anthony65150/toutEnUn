<?php
// Fichier: /stock/stock_chef.php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';

if (!isset($_SESSION['utilisateurs'])) {
    header("Location: ../index.php");
    exit;
}

$user  = $_SESSION['utilisateurs'];
$userId = (int)($user['id'] ?? 0);
$ENT_ID = isset($user['entreprise_id']) ? (int)$user['entreprise_id'] : null;

if (!$ENT_ID) {
    $_SESSION['error_message'] = "Contexte entreprise manquant.";
    header("Location: ../index.php");
    exit;
}

/* =========================================================
   1) Chantiers du chef dans SON entreprise
   ========================================================= */
$stmt = $pdo->prepare("
    SELECT uc.chantier_id
    FROM utilisateur_chantiers uc
    JOIN chantiers c ON c.id = uc.chantier_id AND c.entreprise_id = :eid
    WHERE uc.utilisateur_id = :uid
");
$stmt->execute([':uid'=>$userId, ':eid'=>$ENT_ID]);
$chefChantiers = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Sauvegarde en session (optionnel)
$_SESSION['utilisateurs']['chantiers'] = $chefChantiers;

// Chantier actuel via URL ou valeur par défaut
$utilisateurChantierId = isset($_GET['chantier_id']) ? (int)$_GET['chantier_id'] : null;
if (!$utilisateurChantierId && !empty($chefChantiers)) {
    $utilisateurChantierId = (int)$chefChantiers[0];
    header("Location: stock_chef.php?chantier_id={$utilisateurChantierId}");
    exit;
}

// Redirection si un seul chantier
if (!$utilisateurChantierId && count($chefChantiers) === 1) {
    $utilisateurChantierId = (int)$chefChantiers[0];
    header("Location: stock_chef.php?chantier_id={$utilisateurChantierId}");
    exit;
}

// Garde-fou : le chantier demandé doit appartenir au chef ET à l’entreprise
if (!$utilisateurChantierId || !in_array($utilisateurChantierId, array_map('intval', $chefChantiers), true)) {
    $_SESSION['error_message'] = "Accès à ce chantier non autorisé.";
    header("Location: ../index.php");
    exit;
}

/* =========================================================
   2) Listes chantiers/dépôts (entreprise)
   ========================================================= */
$stmt = $pdo->prepare("SELECT id, nom FROM chantiers WHERE entreprise_id = :eid ORDER BY nom");
$stmt->execute([':eid'=>$ENT_ID]);
$allChantiers = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$stmt = $pdo->prepare("SELECT id, nom FROM depots WHERE entreprise_id = :eid ORDER BY nom");
$stmt->execute([':eid'=>$ENT_ID]);
$allDepots = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$pageTitle = "Stock - " . ($allChantiers[$utilisateurChantierId] ?? "Chantier inconnu");

require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/navigation/navigation.php';

/* =========================================================
   3) Répartitions (toujours bornées à l’entreprise)
   ========================================================= */

/* Chantiers : quantités visibles – en tenant compte des transferts en attente sortants du chantier */
$stmt = $pdo->prepare("
    SELECT 
        sc.stock_id, 
        c.id   AS chantier_id, 
        c.nom  AS chantier_nom, 
        (sc.quantite - COALESCE((
            SELECT SUM(te.quantite)
            FROM transferts_en_attente te
            /* On borne via stock */
            JOIN stock s2 ON s2.id = te.article_id AND s2.entreprise_id = :eid_te
            WHERE te.article_id   = sc.stock_id
              AND te.source_type  = 'chantier'
              AND te.source_id    = sc.chantier_id
              AND te.statut       = 'en_attente'
        ), 0)) AS quantite
    FROM stock_chantiers sc
    JOIN chantiers c ON c.id = sc.chantier_id AND c.entreprise_id = :eid_c
    JOIN stock s     ON s.id = sc.stock_id     AND s.entreprise_id = :eid_s
");
$stmt->execute([
    ':eid_te' => $ENT_ID,
    ':eid_c'  => $ENT_ID,
    ':eid_s'  => $ENT_ID,
]);

$chantierAssoc = [];
foreach ($stmt as $row) {
    $chantierAssoc[(int)$row['stock_id']][] = [
        'id'       => (int)$row['chantier_id'],
        'nom'      => $row['chantier_nom'],
        'quantite' => max(0, (int)$row['quantite']),
    ];
}

/* Dépôts : quantités par dépôt (entreprise) */
$stmt = $pdo->prepare("
    SELECT sd.stock_id, d.id AS depot_id, d.nom AS depot_nom, sd.quantite
    FROM stock_depots sd
    JOIN depots d ON d.id = sd.depot_id AND d.entreprise_id = :eid_d
    JOIN stock  s ON s.id = sd.stock_id AND s.entreprise_id = :eid_s2
");
$stmt->execute([
    ':eid_d'  => $ENT_ID,
    ':eid_s2' => $ENT_ID,
]);

$depotAssoc = [];
foreach ($stmt as $row) {
    $depotAssoc[(int)$row['stock_id']][] = [
        'id'       => (int)$row['depot_id'],
        'nom'      => $row['depot_nom'],
        'quantite' => (int)$row['quantite'],
    ];
}

/* Articles (entreprise) */
$stmt = $pdo->prepare("
  SELECT id, nom, photo, quantite_totale, categorie, sous_categorie
  FROM stock
  WHERE entreprise_id = :eid
  ORDER BY nom
");
$stmt->execute([':eid'=>$ENT_ID]);
$stocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Catégories / sous-catégories (entreprise) */
$stmt = $pdo->prepare("
  SELECT DISTINCT categorie 
  FROM stock
  WHERE entreprise_id = :eid AND categorie IS NOT NULL
  ORDER BY categorie
");
$stmt->execute([':eid'=>$ENT_ID]);
$categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

$stmt = $pdo->prepare("
  SELECT categorie, sous_categorie 
  FROM stock
  WHERE entreprise_id = :eid AND sous_categorie IS NOT NULL
");
$stmt->execute([':eid'=>$ENT_ID]);
$subCatRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
$subCategoriesGrouped = [];
foreach ($subCatRaw as $row) {
    $catKey = strtolower(trim((string)$row['categorie']));
    $subKey = strtolower(trim((string)$row['sous_categorie']));
    if ($catKey === '') continue;
    $subCategoriesGrouped[$catKey][] = $subKey;
}
foreach ($subCategoriesGrouped as &$subs) { $subs = array_values(array_unique($subs)); }
unset($subs);

/* Transferts en attente (destination = ce chantier, entreprise) */
$stmt = $pdo->prepare("
  SELECT 
    t.id AS transfert_id,
    s.nom AS article_nom,
    t.quantite,
    u.nom    AS demandeur_nom,
    u.prenom AS demandeur_prenom,
    t.source_type,
    t.source_id
  FROM transferts_en_attente t
  JOIN stock s        ON s.id = t.article_id AND s.entreprise_id = :eid
  JOIN utilisateurs u ON u.id = t.demandeur_id
  WHERE t.destination_type = 'chantier'
    AND t.destination_id   = :dest_ch
    AND t.statut           = 'en_attente'
  ORDER BY t.id DESC
");
$stmt->execute([':eid'=>$ENT_ID, ':dest_ch'=>$utilisateurChantierId]);
$transfertsEnAttente = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Libellés */
$pageChantierNom = $allChantiers[$utilisateurChantierId] ?? 'Chantier inconnu';
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
            <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Fermer"></button>
        </div>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <h2 class="text-center mb-4">Stock - <?= htmlspecialchars($pageChantierNom) ?></h2>

    <?php if (count($chefChantiers) > 1): ?>
        <div class="d-flex justify-content-center gap-2 mb-4 flex-wrap">
            <?php foreach ($chefChantiers as $chantierId): ?>
                <a href="?chantier_id=<?= (int)$chantierId ?>" class="btn btn-outline-secondary <?= ((int)$chantierId === (int)$utilisateurChantierId) ? 'active' : '' ?>">
                    <?= htmlspecialchars($allChantiers[$chantierId] ?? 'Chantier') ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Filtres -->
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

    <!-- Transferts à valider -->
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
                            <td><?= (int)$t['quantite'] ?></td>
                            <td>
                                <?php
                                $nomComplet = trim((string)($t['demandeur_prenom'] ?? ''));
                                $srcType = $t['source_type'] ?? null;
                                $srcId   = isset($t['source_id']) ? (int)$t['source_id'] : null;

                                $origine = null;
                                if ($srcType === 'chantier' && $srcId && isset($allChantiers[$srcId])) {
                                    $origine = 'Chantier ' . $allChantiers[$srcId];
                                } elseif ($srcType === 'depot' && $srcId && isset($allDepots[$srcId])) {
                                    $origine = 'Dépôt ' . $allDepots[$srcId];
                                }

                                echo htmlspecialchars($nomComplet);
                                if ($origine) echo ' (' . htmlspecialchars($origine) . ')';
                                ?>
                            </td>
                            <td>
                                <form method="post" action="validerReception_chef.php" style="display:inline;">
                                    <input type="hidden" name="transfert_id" value="<?= (int)$t['transfert_id'] ?>">
                                    <button type="submit" class="btn btn-success btn-sm me-1">✅ Valider</button>
                                </form>
                                <form method="post" action="annulerTransfert_chef.php" style="display:inline;">
                                    <input type="hidden" name="transfert_id" value="<?= (int)$t['transfert_id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">❌ Refuser</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- Tableau principal -->
    <div class="table-responsive mb-4">
        <table class="table table-striped table-bordered table-hover text-center align-middle">
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
                    $stockId        = (int)$stock['id'];
                    $quantiteTotale = (int)$stock['quantite_totale'];
                    $depotsList     = $depotAssoc[$stockId]    ?? [];
                    $chantiersList  = $chantierAssoc[$stockId] ?? [];
                    $quantiteMonChantier = 0;

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

                    $autresChantiers = [];
                    foreach ($chantiersList as $c) {
                        if ($c['id'] === $utilisateurChantierId) {
                            $quantiteMonChantier = (int)$c['quantite'];
                        } elseif ($c['quantite'] > 0) {
                            $autresChantiers[] = $c;
                        }
                    }
                    usort($autresChantiers, fn($a,$b)=>$b['quantite'] <=> $a['quantite']);
                    $chantiersHtml = count($autresChantiers)
                        ? implode('<br>', array_map(fn($c)=> htmlspecialchars($c['nom']).' ('.(int)$c['quantite'].')', $autresChantiers))
                        : '<span class="text-muted">Aucun</span>';

                    $badge = $quantiteMonChantier > 0
                        ? '<span class="badge bg-success">'.$quantiteMonChantier.'</span>'
                        : '<span class="badge bg-danger">0</span>';

                    $catSafe    = strtolower(trim((string)($stock['categorie'] ?? '')));
                    $subcatSafe = strtolower(trim((string)($stock['sous_categorie'] ?? '')));
                    ?>
                    <tr data-cat="<?= htmlspecialchars($catSafe) ?>"
                        data-subcat="<?= htmlspecialchars($subcatSafe) ?>"
                        class="<?= (isset($_SESSION['highlight_stock_id']) && (int)$_SESSION['highlight_stock_id'] === $stockId) ? 'table-success highlight-row' : '' ?>">

                        <!-- PHOTO -->
                        <td class="text-center col-photo" style="width:64px">
                            <?php
                            $photoWeb = '';
                            if (!empty($stock['photo'])) {
                                $photoWeb = '/' . ltrim($stock['photo'], '/');
                            } else {
                                $fallbackLocal = __DIR__ . "/../uploads/photos/{$stockId}.jpg";
                                if (is_file($fallbackLocal)) $photoWeb = "/uploads/photos/{$stockId}.jpg";
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

                        <!-- ARTICLE -->
                        <td class="text-center td-article">
                            <a href="article.php?id=<?= $stockId ?>&chantier_id=<?= (int)$utilisateurChantierId ?>"
                               class="fw-semibold text-decoration-none">
                                <?= htmlspecialchars($stock['nom']) ?>
                            </a>
                            <span class="ms-1 text-muted">(<?= $quantiteTotale ?>)</span>
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
                        setTimeout(() => highlighted.classList.remove("table-success","highlight-row"), 3000);
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
                                    <option value="depot_<?= (int)$id ?>"><?= htmlspecialchars($nom) ?></option>
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
    window.isChef = true;
    window.chefChantierActuel = <?= (int)$utilisateurChantierId ?>;
    const subCategories = <?= json_encode($subCategoriesGrouped) ?>;
</script>
<script src="js/stockGestion_chef.js"></script>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
