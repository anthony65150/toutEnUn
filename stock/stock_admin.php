<?php
// === INIT & SECURITE ===
require_once __DIR__ . '/../config/init.php';

if (!isset($_SESSION['utilisateurs']) || ($_SESSION['utilisateurs']['fonction'] ?? null) !== 'administrateur') {
    header("Location: ../connexion.php");
    exit;
}

require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/navigation/navigation.php';

// ====== Multi-entreprise ======
$ENT_ID = isset($_SESSION['utilisateurs']['entreprise_id']) ? (int)$_SESSION['utilisateurs']['entreprise_id'] : null;

// ====== Données de base ======

// --- Dépôts et chantiers (filtrés entreprise)
$sql = "SELECT id, nom FROM chantiers";
$params = [];
if ($ENT_ID !== null) { $sql .= " WHERE entreprise_id = :eid"; $params[':eid'] = $ENT_ID; }
$sql .= " ORDER BY nom";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allChantiers = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$sql = "SELECT id, nom FROM depots";
$params = [];
if ($ENT_ID !== null) { $sql .= " WHERE entreprise_id = :eid"; $params[':eid'] = $ENT_ID; }
$sql .= " ORDER BY nom";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allDepots = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// --- Quantités par dépôt (filtrées entreprise via depots)
$sql = "
    SELECT sd.stock_id, d.id AS depot_id, d.nom AS depot_nom, sd.quantite
    FROM stock_depots sd
    JOIN depots d ON sd.depot_id = d.id
    WHERE 1=1
";
$params = [];
if ($ENT_ID !== null) { $sql .= " AND d.entreprise_id = :eid"; $params[':eid'] = $ENT_ID; }

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$depotAssoc = [];
foreach ($stmt as $row) {
    $depotAssoc[(int)$row['stock_id']][] = [
        'id'       => (int)$row['depot_id'],
        'nom'      => $row['depot_nom'],
        'quantite' => (int)$row['quantite'],
    ];
}

// --- Responsable de chaque dépôt (filtré entreprise)
$sql = "
  SELECT d.id AS depot_id, u.prenom, u.nom
  FROM depots d
  LEFT JOIN utilisateurs u ON u.id = d.responsable_id
  WHERE 1=1
";
$params = [];
if ($ENT_ID !== null) { $sql .= " AND d.entreprise_id = :eid"; $params[':eid'] = $ENT_ID; }

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$depotResponsables = [];
foreach ($stmt as $r) {
    $depotResponsables[(int)$r['depot_id']] = [
        'prenom' => $r['prenom'] ?? '',
        'nom'    => $r['nom'] ?? '',
    ];
}

// --- Chef (au moins un) pour chaque chantier (filtré entreprise via chantiers)
$sql = "
  SELECT uc.chantier_id, u.prenom, u.nom
  FROM utilisateur_chantiers uc
  JOIN utilisateurs u ON u.id = uc.utilisateur_id
  JOIN chantiers c ON c.id = uc.chantier_id
  WHERE 1=1
";
$params = [];
if ($ENT_ID !== null) { $sql .= " AND c.entreprise_id = :eid"; $params[':eid'] = $ENT_ID; }
$sql .= " ORDER BY u.nom, u.prenom";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$chantierChefs = [];
foreach ($stmt as $r) {
    $cid = (int)$r['chantier_id'];
    if (!isset($chantierChefs[$cid])) {
        $chantierChefs[$cid] = [
            'prenom' => $r['prenom'] ?? '',
            'nom'    => $r['nom'] ?? '',
        ];
    }
}

// --- Quantités par chantier (filtré entreprise via chantiers)
// (la soustraction des transferts en attente reste telle quelle)
$sql = "
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
        'quantite' => max(0, (int)$row['quantite']),
    ];
}

// --- Articles + catégories/sous-catégories (filtrés entreprise)
$sql = "
    SELECT id, nom, quantite_totale, categorie, sous_categorie, document, photo
    FROM stock
    WHERE 1=1
";
$params = [];
if ($ENT_ID !== null) { $sql .= " AND entreprise_id = :eid"; $params[':eid'] = $ENT_ID; }
$sql .= " ORDER BY nom";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$stocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sql = "
    SELECT DISTINCT categorie
    FROM stock
    WHERE categorie IS NOT NULL
";
$params = [];
if ($ENT_ID !== null) { $sql .= " AND entreprise_id = :eid"; $params[':eid'] = $ENT_ID; }
$sql .= " ORDER BY categorie";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

// --- Catégories (propres, affichables et utilisables comme clés JS)
$sql = "
    SELECT DISTINCT TRIM(categorie) AS categorie
    FROM stock
    WHERE categorie IS NOT NULL AND TRIM(categorie) <> ''
";
$params = [];
if ($ENT_ID !== null){ $sql .= " AND entreprise_id = :eid"; $params[':eid'] = $ENT_ID; }
$sql .= " ORDER BY categorie";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$categoriesRaw = $stmt->fetchAll(PDO::FETCH_COLUMN);

// jolis libellés + dédoublon
$toLabel = function(string $s): string{
  $s = preg_replace('/\s+/u', ' ', trim($s));
  $s = mb_strtolower($s, 'UTF-8');
  return $s === '' ? '' : mb_strtoupper(mb_substr($s,0,1,'UTF-8'),'UTF-8').mb_substr($s,1,null,'UTF-8');
};

$seen = [];
$categories = [];
foreach ($categoriesRaw as $c){
  $label = $toLabel((string)$c);
  $k = mb_strtolower($label,'UTF-8');
  if ($label !== '' && !isset($seen[$k])){
    $seen[$k] = true;
    $categories[] = $label;
  }
}



// Normalisation d'affichage (Trim, espaces multiples -> 1, casse)
$normalizeLabel = function (?string $s): string {
    $s = preg_replace('/\s+/u', ' ', trim((string)$s));
    $s = mb_strtolower($s, 'UTF-8');
    return $s === '' ? '' : mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($s, 1, null, 'UTF-8');
};
// Clé de dédoublonnage (trim + minuscules + sans espaces multiples)
// (si tu veux aussi ignorer les accents, ajoute une translit ici)
$keyOf = function (?string $s): string {
    $s = preg_replace('/\s+/u', ' ', trim((string)$s));
    $s = mb_strtolower($s, 'UTF-8');
    return $s;
};

// --- Sous-catégories groupées par libellé de catégorie (clés = libellés)
$sql = "
    SELECT TRIM(categorie) AS categorie, TRIM(sous_categorie) AS sous_categorie
    FROM stock
    WHERE sous_categorie IS NOT NULL AND TRIM(sous_categorie) <> ''
";
$params = [];
if ($ENT_ID !== null){ $sql .= " AND entreprise_id = :eid"; $params[':eid'] = $ENT_ID; }

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$subCatRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$toLabel = function(string $s): string{
  $s = preg_replace('/\s+/u', ' ', trim($s));
  $s = mb_strtolower($s, 'UTF-8');
  return $s === '' ? '' : mb_strtoupper(mb_substr($s,0,1,'UTF-8'),'UTF-8').mb_substr($s,1,null,'UTF-8');
};

$subCategoriesGrouped = [];            // ex: ['Bungalow' => ['Vestiaire', 'Sanitaire']]
$seenPerCat = [];

foreach ($subCatRaw as $row){
  $cat = $toLabel((string)($row['categorie'] ?? ''));
  $sub = $toLabel((string)($row['sous_categorie'] ?? ''));
  if ($cat === '' || $sub === '') continue;

  if (!isset($seenPerCat[$cat])) { $seenPerCat[$cat] = []; }
  $ksub = mb_strtolower($sub,'UTF-8'); // clé de dédoublon

  if (!isset($seenPerCat[$cat][$ksub])){
    $seenPerCat[$cat][$ksub] = true;
    $subCategoriesGrouped[$cat][] = $sub;
  }
}

// tri joli
ksort($subCategoriesGrouped, SORT_NATURAL | SORT_FLAG_CASE);
foreach ($subCategoriesGrouped as &$list){
  sort($list, SORT_NATURAL | SORT_FLAG_CASE);
}
unset($list);


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
    // --- Transferts en attente de validation
    // On se limite aux articles de l’entreprise via s.entreprise_id
    $sql = "
      SELECT
        t.id AS transfert_id, s.nom AS article_nom, t.quantite,
        u.prenom, u.nom, u.fonction AS demandeur_role,
        t.source_type, t.source_id, t.destination_type, t.destination_id
      FROM transferts_en_attente t
      JOIN stock s ON t.article_id = s.id
      JOIN utilisateurs u ON t.demandeur_id = u.id
      WHERE t.statut = 'en_attente'
    ";
    $params = [];
    if ($ENT_ID !== null) { $sql .= " AND s.entreprise_id = :eid"; $params[':eid'] = $ENT_ID; }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
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
                        <td><?= (int)$t['quantite'] ?></td>
                        <td>
                            <?php
                            $prenom  = trim($t['prenom'] ?? '');
                            $role    = strtolower($t['demandeur_role'] ?? '');
                            $srcType = $t['source_type'] ?? null;
                            $srcId   = isset($t['source_id']) ? (int)$t['source_id'] : null;

                            $origine = null;
                            if ($srcType === 'chantier' && $srcId && isset($allChantiers[$srcId])) {
                                $origine = 'Chantier ' . $allChantiers[$srcId];
                            } elseif ($srcType === 'depot' && $srcId && isset($allDepots[$srcId])) {
                                $origine = 'Dépôt ' . $allDepots[$srcId];
                            }

                            echo htmlspecialchars($prenom);
                            if ($role !== 'administrateur' && $origine) {
                                echo ' (' . htmlspecialchars($origine) . ')';
                            }
                            ?>
                        </td>

                        <td>
                            <?php
                            $srcType = $t['source_type'] ?? null;
                            $srcId   = isset($t['source_id']) ? (int)$t['source_id'] : null;

                            $label = '';
                            $respPrenom = '';

                            if ($srcType === 'chantier' && $srcId) {
                                $label = isset($allChantiers[$srcId]) ? ('Chantier ' . $allChantiers[$srcId]) : '';
                                if (!empty($chantierChefs[$srcId]['prenom'])) {
                                    $respPrenom = trim($chantierChefs[$srcId]['prenom']);
                                }
                            } elseif ($srcType === 'depot' && $srcId) {
                                $label = isset($allDepots[$srcId]) ? ('Dépôt ' . $allDepots[$srcId]) : '';
                                if (!empty($depotResponsables[$srcId]['prenom'])) {
                                    $respPrenom = trim($depotResponsables[$srcId]['prenom']);
                                }
                            }

                            if ($respPrenom !== '' && $label !== '') {
                                echo htmlspecialchars($respPrenom) . ' (' . htmlspecialchars($label) . ')';
                            } else {
                                echo htmlspecialchars($label ?: '—');
                            }
                            ?>
                        </td>

                        <td>
                            <?php
                            $dstType = $t['destination_type'] ?? null;
                            $dstId   = isset($t['destination_id']) ? (int)$t['destination_id'] : null;

                            $label = '';
                            $respPrenom = '';

                            if ($dstType === 'chantier' && $dstId) {
                                $label = isset($allChantiers[$dstId]) ? ('Chantier ' . $allChantiers[$dstId]) : '';
                                if (!empty($chantierChefs[$dstId]['prenom'])) {
                                    $respPrenom = trim($chantierChefs[$dstId]['prenom']);
                                }
                            } elseif ($dstType === 'depot' && $dstId) {
                                $label = isset($allDepots[$dstId]) ? ('Dépôt ' . $allDepots[$dstId]) : '';
                                if (!empty($depotResponsables[$dstId]['prenom'])) {
                                    $respPrenom = trim($depotResponsables[$dstId]['prenom']);
                                }
                            }

                            if ($respPrenom !== '' && $label !== '') {
                                echo htmlspecialchars($respPrenom) . ' (' . htmlspecialchars($label) . ')';
                            } else {
                                echo htmlspecialchars($label ?: '—');
                            }
                            ?>
                        </td>

                        <td>
                            <form method="post" action="validerReception_admin.php" style="display:inline;">
                                <input type="hidden" name="transfert_id" value="<?= (int)$t['transfert_id'] ?>">
                                <button type="submit" class="btn btn-success btn-sm me-1">✅ Valider</button>
                            </form>

                            <form method="post" action="annulerTransfert_admin.php" style="display:inline;">
                                <input type="hidden" name="transfert_id" value="<?= (int)$t['transfert_id'] ?>">
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
        <table id="stockTable" class="table table-striped table-bordered table-hover text-center align-middle">
            <thead class="table-dark">
                <tr>
                    <th style="width:82px" class="col-photo">Photo</th>
                    <th>Articles</th>
                    <th>Dépôts</th>
                    <th>Chantiers</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="stockTableBody">
                <?php foreach ($stocks as $stock): ?>
                    <?php
                    $stockId        = (int)$stock['id'];
                    $quantiteTotale = (int)$stock['quantite_totale'];
                    $depotsList     = $depotAssoc[$stockId]    ?? [];
                    $chantiersList  = $chantierAssoc[$stockId] ?? [];

                    $photoWeb = !empty($stock['photo']) ? '../' . ltrim($stock['photo'], '/') : '';

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
                        data-cat="<?= htmlspecialchars($stock['categorie'] ?? '') ?>"
                        data-subcat="<?= htmlspecialchars($stock['sous_categorie'] ?? '') ?>"
                        class="<?= (isset($_SESSION['highlight_stock_id']) && (int)$_SESSION['highlight_stock_id'] === $stockId) ? 'table-success highlight-row' : '' ?>">

                        <!-- PHOTO -->
                        <td class="text-center col-photo" style="width:64px">
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
                            <a href="article.php?id=<?= $stockId ?>" class="fw-semibold text-decoration-none">
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

                        <!-- DEPOTS -->
                        <td class="text-center">
                            <?= $depotsHtml ?>
                        </td>

                        <!-- CHANTIERS -->
                        <td class="text-center">
                            <?php
                            $chantiersAvecStock = array_filter($chantiersList, fn($c) => $c['quantite'] > 0);
                            if (count($chantiersAvecStock)):
                                usort($chantiersAvecStock, fn($a, $b) => $b['quantite'] <=> $a['quantite']);
                                foreach ($chantiersAvecStock as $c):
                            ?>
                                <div>
                                    <?= htmlspecialchars($c['nom']) ?>
                                    ( <span id="qty-source-chantier-<?= (int)$c['id'] ?>-<?= $stockId ?>"><?= (int)$c['quantite'] ?></span> )
                                </div>
                            <?php
                                endforeach;
                            else:
                                echo '<span class="text-muted">Aucun</span>';
                            endif;
                            ?>
                        </td>

                        <!-- ACTIONS -->
                        <td class="text-center">
                            <button class="btn btn-sm btn-primary transfer-btn" data-stock-id="<?= $stockId ?>">
                                <i class="bi bi-arrow-left-right"></i>
                            </button>

                            <button
                                class="btn btn-sm btn-warning edit-btn"
                                data-stock-id="<?= $stockId ?>"
                                data-stock-nom="<?= htmlspecialchars($stock['nom']) ?>"
                                data-stock-quantite="<?= (int)$stock['quantite_totale'] ?>"
                                data-stock-photo="<?= htmlspecialchars($photoWeb) ?>">
                                <i class="bi bi-pencil"></i>
                            </button>

                            <button class="btn btn-sm btn-danger delete-btn"
                                    data-stock-id="<?= $stockId ?>"
                                    data-stock-nom="<?= htmlspecialchars($stock['nom']) ?>">
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
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="modalStockId">
        <div class="mb-3">
          <label>Source</label>
          <select class="form-select" id="sourceChantier">
            <option value="" disabled selected>Choisir la source</option>
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
        <input type="hidden" id="modifyStockId" name="stockId">
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

        <!-- Documents -->
        <div class="mb-3">
          <label class="form-label">Documents existants</label>
          <div id="existingDocs" class="d-flex flex-column gap-2"></div>
          <div class="form-text">Clique sur la corbeille pour supprimer un document.</div>
        </div>

        <div class="mb-3">
          <label for="modifierDocument" class="form-label">Ajouter des documents</label>
          <input type="file" class="form-control" id="modifierDocument" name="documents[]" multiple
                 accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.webp">
          <div id="newDocsPreview" class="mt-2 d-flex flex-column gap-1"></div>
        </div>

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
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
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

<!-- TOASTS -->
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
<!-- JS du module Stock (déplacés dans /stock/js) -->
<script src="./js/stock.js?v=1"></script>
<script src="./js/stockGestion_admin.js?v=1"></script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
