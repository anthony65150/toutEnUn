<?php
require_once "./config/init.php";
require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/navigation/navigation.php';

if (!isset($_SESSION['utilisateurs'])) {
    header("Location: connexion.php");
    exit;
}

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

    <div class="table-responsive mb-4">
        <table class="table table-bordered table-hover text-center align-middle">
            <thead class="table-dark">
                <tr>
                    <th>Nom (Total)</th>
                    <th>Photo</th>
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
                        <td><img src="uploads/photos/<?= $stockId ?>.jpg" alt="photo" style="height: 40px;"></td>
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

    <h3 class="mt-5">Transferts à valider</h3>
    <?php if ($transfertsEnAttente): ?>
        <table class="table table-bordered">
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
    <?php else: ?>
        <p class="text-muted">Aucun transfert en attente de validation.</p>
    <?php endif; ?>
</div>

<script>
    const subCategories = <?= json_encode($subCategoriesGrouped) ?>;
</script>
<script src="/js/stockGestion_chef.js"></script>
<?php require_once __DIR__ . '/templates/footer.php'; ?>
