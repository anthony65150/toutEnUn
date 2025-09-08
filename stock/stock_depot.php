<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/navigation/navigation.php';

if (!isset($_SESSION['utilisateurs']) || ($_SESSION['utilisateurs']['fonction'] ?? '') !== 'depot') {
  header('Location: /index.php'); exit;
}

$ENT_ID = isset($_SESSION['utilisateurs']['entreprise_id']) ? (int)$_SESSION['utilisateurs']['entreprise_id'] : null;
$userId = (int)($_SESSION['utilisateurs']['id'] ?? 0);

/* ---------------------------
   1) Dépôt du user (sécurisé)
---------------------------- */
$sql = "SELECT id, nom FROM depots WHERE responsable_id = :uid";
$params = [':uid' => $userId];
if ($ENT_ID !== null) { $sql .= " AND entreprise_id = :eid"; $params[':eid'] = $ENT_ID; }

$stmtDepot = $pdo->prepare($sql);
$stmtDepot->execute($params);
$depot = $stmtDepot->fetch(PDO::FETCH_ASSOC);
$depotId = $depot ? (int)$depot['id'] : null;

if (!$depotId) {
  die("Dépôt non trouvé pour cet utilisateur.");
}
$depotNom = (string)($depot['nom'] ?? '');

/* ---------------------------
   2) Listes chantiers / dépôts
---------------------------- */
$sql = "SELECT id, nom FROM chantiers";
$params = [];
if ($ENT_ID !== null) { $sql .= " WHERE entreprise_id = :eid"; $params[':eid'] = $ENT_ID; }
$sql .= " ORDER BY nom";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$allChantiers = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$sql = "SELECT id, nom FROM depots";
$params = [];
if ($ENT_ID !== null) { $sql .= " WHERE entreprise_id = :eid"; $params[':eid'] = $ENT_ID; }
$sql .= " ORDER BY nom";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$allDepots = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

/* -----------------------------------------
   3) Répartition chantiers (filtrée ent)
------------------------------------------ */
$sql = "
  SELECT sc.stock_id, c.id AS chantier_id, c.nom AS chantier_nom, sc.quantite
  FROM stock_chantiers sc
  JOIN chantiers c ON c.id = sc.chantier_id
  WHERE 1=1
";
$params = [];
if ($ENT_ID !== null) { $sql .= " AND c.entreprise_id = :eid"; $params[':eid'] = $ENT_ID; }

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$chantierAssoc = [];
foreach ($stmt as $row) {
  $chantierAssoc[(int)$row['stock_id']][] = [
    'id'       => (int)$row['chantier_id'],
    'nom'      => $row['chantier_nom'],
    'quantite' => (int)$row['quantite']
  ];
}

/* -----------------------------------------
   4) Liste des stocks pour CE dépôt
        - s.entreprise_id = ENT
        - sous-total chantiers limité à ENT
        - autres dépôts limités à ENT et != dépôt courant
------------------------------------------ */
$pdo->query("SET SESSION group_concat_max_len = 8192");

$sql = "
  SELECT 
      s.id, s.nom, s.photo,
      s.quantite_totale,
      COALESCE(sd.quantite,0)+COALESCE(sc.total_chantier,0) AS total_recalcule,
      COALESCE(sd.quantite,0) AS quantite_stock_depot,
      COALESCE(sd.quantite,0) AS disponible_depot,
      s.categorie, s.sous_categorie,
      odo.autres_depots
  FROM stock s
  /* quantité dans CE dépôt */
  LEFT JOIN stock_depots sd 
         ON sd.stock_id = s.id
        AND sd.depot_id = :did
  /* total chantiers (de l'entreprise uniquement) */
  LEFT JOIN (
      SELECT sc2.stock_id, SUM(sc2.quantite) AS total_chantier
      FROM stock_chantiers sc2
      JOIN chantiers c2 ON c2.id = sc2.chantier_id
      WHERE 1=1
";
$params = [':did' => $depotId];

if ($ENT_ID !== null) { $sql .= " AND c2.entreprise_id = :eid1"; $params[':eid1'] = $ENT_ID; }

$sql .= "
      GROUP BY sc2.stock_id
  ) sc ON sc.stock_id = s.id
  /* autres dépôts de la même entreprise */
  LEFT JOIN (
      SELECT sd2.stock_id,
             GROUP_CONCAT(CONCAT(d2.nom, ' (', sd2.quantite, ')') ORDER BY d2.nom SEPARATOR ', ') AS autres_depots
      FROM stock_depots sd2
      JOIN depots d2 ON d2.id = sd2.depot_id
      WHERE sd2.depot_id <> :did2
";
$params[':did2'] = $depotId;

if ($ENT_ID !== null) { $sql .= " AND d2.entreprise_id = :eid2"; $params[':eid2'] = $ENT_ID; }

$sql .= "
      GROUP BY sd2.stock_id
  ) odo ON odo.stock_id = s.id
  WHERE 1=1
";

if ($ENT_ID !== null) { $sql .= " AND s.entreprise_id = :eid3"; $params[':eid3'] = $ENT_ID; }

$sql .= " ORDER BY s.nom";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$stocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* -----------------------------------------
   5) Catégories & sous-catégories (ENT)
------------------------------------------ */
$sql = "SELECT DISTINCT categorie FROM stock WHERE categorie IS NOT NULL";
$params = [];
if ($ENT_ID !== null) { $sql .= " AND entreprise_id = :eid"; $params[':eid'] = $ENT_ID; }
$sql .= " ORDER BY categorie";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

$sql = "SELECT categorie, sous_categorie FROM stock WHERE sous_categorie IS NOT NULL";
$params = [];
if ($ENT_ID !== null) { $sql .= " AND entreprise_id = :eid"; $params[':eid'] = $ENT_ID; }
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$subCatRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$subCategoriesGrouped = [];
foreach ($subCatRaw as $row) {
  $catKey = strtolower(trim((string)$row['categorie']));
  $subKey = strtolower(trim((string)$row['sous_categorie']));
  $subCategoriesGrouped[$catKey][] = $subKey;
}
foreach ($subCategoriesGrouped as &$subs) { $subs = array_values(array_unique($subs)); }
unset($subs);

/* -----------------------------------------
   6) Transferts en attente vers CE dépôt
       (limités aux articles de l’entreprise)
------------------------------------------ */
$sql = "
  SELECT 
    t.id AS transfert_id,
    s.nom AS article_nom,
    t.quantite,
    u.prenom AS demandeur_prenom,
    t.source_type,
    t.source_id,
    c.nom AS chantier_nom,
    d.nom AS depot_nom
  FROM transferts_en_attente t
  JOIN stock s        ON s.id = t.article_id
  JOIN utilisateurs u ON u.id = t.demandeur_id
  LEFT JOIN chantiers c ON (t.source_type = 'chantier' AND c.id = t.source_id)
  LEFT JOIN depots d    ON (t.source_type = 'depot'    AND d.id = t.source_id)
  WHERE t.destination_type = 'depot'
    AND t.destination_id = :did3
    AND t.statut = 'en_attente'
";
$params = [':did3' => $depotId];
if ($ENT_ID !== null) { $sql .= " AND s.entreprise_id = :eid4"; $params[':eid4'] = $ENT_ID; }

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transfertsEnAttente = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="container py-5">
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

  <h2 class="text-center mb-4">Stock dépôt<?= $depotNom ? ' – ' . htmlspecialchars($depotNom) : '' ?></h2>

  <!-- Filtres catégories -->
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
        <tr><th>Article</th><th>Quantité</th><th>Envoyé par</th><th>Action</th></tr>
        </thead>
        <tbody>
        <?php foreach ($transfertsEnAttente as $t): ?>
          <tr>
            <td><?= htmlspecialchars($t['article_nom']) ?></td>
            <td><?= (int)$t['quantite'] ?></td>
            <td>
              <?php
                $origine = null;
                if (($t['source_type'] ?? null) === 'chantier' && !empty($t['chantier_nom'])) {
                  $origine = 'Chantier ' . $t['chantier_nom'];
                } elseif (($t['source_type'] ?? null) === 'depot' && !empty($t['depot_nom'])) {
                  $origine = 'Dépôt ' . $t['depot_nom'];
                }
                echo htmlspecialchars(trim((string)($t['demandeur_prenom'] ?? '')));
                if ($origine) echo ' (' . htmlspecialchars($origine) . ')';
              ?>
            </td>
            <td>
              <form method="post" action="validerReception_depot.php" style="display:inline;">
                <input type="hidden" name="transfert_id" value="<?= (int)$t['transfert_id'] ?>">
                <button type="submit" class="btn btn-success btn-sm me-1">✅ Valider</button>
              </form>
              <form method="post" action="annulerTransfert_depot.php" style="display:inline;">
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

  <div class="table-responsive">
    <table class="table table-striped table-bordered table-hover align-middle text-center" id="stockTableBody">
      <thead class="table-dark">
        <tr>
          <th class="col-photo">Photo</th>
          <th>Articles</th>
          <th>Disponible au dépôt</th>
          <th>Autres dépôts</th>
          <th>Chantiers</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($stocks as $stock):
        $stockId = (int)$stock['id'];
        $quantiteTotale = (int)$stock['quantite_totale'];
        $dispoDepot = (int)$stock['disponible_depot'];
        $cat = strtolower(trim((string)$stock['categorie']));
        $subcat = strtolower(trim((string)$stock['sous_categorie']));
        $chantierList = $chantierAssoc[$stockId] ?? [];
        $nomArticle = htmlspecialchars($stock['nom']);
        // photo web
        $photoWeb = !empty($stock['photo']) ? '/' . ltrim($stock['photo'], '/') : '';
      ?>
        <tr data-cat="<?= $cat ?>" data-subcat="<?= $subcat ?>"
            class="<?= (isset($_SESSION['highlight_stock_id']) && (int)$_SESSION['highlight_stock_id'] === $stockId) ? 'table-success highlight-row' : '' ?>">
          <!-- PHOTO -->
          <td class="text-center col-photo" style="width:64px">
            <?php if ($photoWeb): ?>
              <img src="<?= htmlspecialchars($photoWeb) ?>" alt="" class="img-thumbnail" style="width:56px;height:56px;object-fit:cover;">
            <?php else: ?>
              <div class="border rounded d-inline-flex align-items-center justify-content-center" style="width:56px;height:56px;">—</div>
            <?php endif; ?>
          </td>

          <!-- ARTICLE -->
          <td class="text-center td-article">
            <a href="article.php?id=<?= $stockId ?>&depot_id=<?= $depotId ?>" class="fw-semibold text-decoration-none">
              <?= $nomArticle ?>
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

          <td>
            <span class="badge quantite-disponible <?= $dispoDepot < 10 ? 'bg-danger' : 'bg-success' ?>"><?= $dispoDepot ?></span>
          </td>

          <td>
            <?php
            $autres = (string)($stock['autres_depots'] ?? '');
            if ($autres) {
              foreach (explode(', ', $autres) as $item) {
                if (preg_match('/^(.*)\s\((\d+)\)$/u', $item, $m)) {
                  $nomDepot = trim($m[1]); $qte = (int)$m[2];
                  $short = function_exists('mb_substr') ? mb_substr($nomDepot, 0, 4, 'UTF-8') : substr($nomDepot, 0, 4);
                  echo '<div class="depot-nom">'
                      .   '<span class="name-full">'  . htmlspecialchars($nomDepot)  . '</span>'
                      .   '<span class="name-short">' . htmlspecialchars($short)     . '</span> '
                      .   '<span class="qty">(' . $qte . ')</span>'
                      . '</div>';
                } else {
                  echo '<div>' . htmlspecialchars($item) . '</div>';
                }
              }
            } else {
              echo '<span class="text-muted">Aucun</span>';
            }
            ?>
          </td>

          <td>
            <?php
            $chantiersAvecStock = array_filter($chantierList, fn($c) => (int)$c['quantite'] > 0);
            if ($chantiersAvecStock):
              usort($chantiersAvecStock, fn($a,$b)=>$b['quantite']<=>$a['quantite']);
              foreach ($chantiersAvecStock as $chantier):
            ?>
              <div><?= htmlspecialchars($chantier['nom']) ?> (<?= (int)$chantier['quantite'] ?>)</div>
            <?php endforeach; else: ?>
              <span class="text-muted">Aucun</span>
            <?php endif; ?>
          </td>

          <td>
            <button class="btn btn-sm btn-primary transfer-btn"
                    data-stock-id="<?= $stockId ?>"
                    data-stock-nom="<?= $nomArticle ?>"
                    title="Transférer" aria-label="Transférer">
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
          if (highlighted) setTimeout(() => highlighted.classList.remove("table-success","highlight-row"), 3000);
        });
      </script>
      <?php unset($_SESSION['highlight_stock_id']); ?>
    <?php endif; ?>
  </div>
</div>

<!-- Modal Transfert -->
<div class="modal fade" id="transferModal" tabindex="-1" aria-labelledby="transferModalLabel" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title" id="transferModalLabel">Transférer du stock</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
    </div>
    <div class="modal-body">
      <form id="transferForm">
        <input type="hidden" id="articleId" name="article_id">
        <input type="hidden" id="sourceDepotId" name="source_depot_id" value="<?= $depotId ?>">
        <div class="mb-3">
          <label>Destination</label>
          <select class="form-select" id="destinationChantier">
            <option value="" disabled selected>Choisir la destination</option>
            <optgroup label="Dépôts">
              <?php foreach ($allDepots as $id => $nom): if ((int)$id === $depotId) continue; ?>
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
  </div></div>
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

<script>window.isChef = false;</script>
<script>const subCategories = <?= json_encode($subCategoriesGrouped) ?>;</script>
<script src="/stock/js/stockGestion_depot.js"></script>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
