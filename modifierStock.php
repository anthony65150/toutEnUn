<?php
require_once "./config/init.php";
file_put_contents('debug_post.txt', var_export($_POST, true));

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
    exit;
}

/* =========================================================
   Helpers
   ========================================================= */
function resolveStoredPathToAbsolute(string $stored = null): ?string {
    if (!$stored) return null;
    if (strpos($stored, 'uploads/') === 0) {
        return __DIR__ . '/' . $stored;
    }
    if (strpos($stored, '/uploads/') === 0) {
        return __DIR__ . $stored;
    }
    return __DIR__ . '/uploads/photos/' . $stored; // legacy
}
function absFromRel(?string $rel): ?string {
    if (!$rel) return null;
    return __DIR__ . '/' . ltrim($rel, '/');
}
function boolish($v): bool {
    return in_array($v, [1, '1', true, 'true', 'on', 'yes'], true);
}

/* =========================================================
   Inputs
   ========================================================= */
$stockId  = isset($_POST['stockId']) ? (int)$_POST['stockId'] : null;
$nom      = trim($_POST['nom'] ?? '');
$quantite = isset($_POST['quantite']) ? (int)$_POST['quantite'] : null;

if (!$stockId || $nom === '' || $quantite === null || $quantite < 0) {
    echo json_encode(['success' => false, 'message' => 'Données invalides']);
    exit;
}

/* =========================================================
   Documents (multi)
   ========================================================= */
$allowedDocExt = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg', 'webp'];
$docsAdded   = [];
$docsDeleted = [];
$docsAll     = [];

$deleteDocIds = $_POST['deleteDocIds'] ?? '[]';
if (!is_array($deleteDocIds)) {
    $tmp = json_decode($deleteDocIds, true);
    $deleteDocIds = is_array($tmp) ? $tmp : [];
}
$deleteDocIds = array_values(array_unique(array_map('intval', $deleteDocIds)));

$newDocsInput = $_FILES['documents'] ?? null; // name="documents[]" multiple
$legacySingle = $_FILES['document']  ?? null; // compat

$pendingInserts = [];

$docsDirRel = "uploads/documents/articles/" . $stockId . "/";
$docsDirAbs = __DIR__ . '/' . $docsDirRel;
if (!is_dir($docsDirAbs)) {
    @mkdir($docsDirAbs, 0775, true);
}

// 1) documents[] multiples
if ($newDocsInput && is_array($newDocsInput['name'])) {
    $count = count($newDocsInput['name']);
    for ($i = 0; $i < $count; $i++) {
        if ($newDocsInput['error'][$i] !== UPLOAD_ERR_OK) continue;
        $origName = $newDocsInput['name'][$i];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedDocExt)) continue;

        $uniq = uniqid('', true) . '.' . $ext;
        $destAbs = $docsDirAbs . $uniq;
        if (move_uploaded_file($newDocsInput['tmp_name'][$i], $destAbs)) {
            $relPath = $docsDirRel . $uniq;
            $pendingInserts[] = [
                'nom'   => $origName,
                'rel'   => $relPath,
                'mime'  => $newDocsInput['type'][$i] ?? null,
                'size'  => (int)($newDocsInput['size'][$i] ?? 0),
                'sha1'  => @sha1_file($destAbs) ?: null,
            ];
        }
    }
}
// 2) compat "document" singulier
if ($legacySingle && $legacySingle['error'] === UPLOAD_ERR_OK) {
    $ext = strtolower(pathinfo($legacySingle['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedDocExt)) {
        echo json_encode(['success' => false, 'message' => 'Type de fichier non autorisé (document).']);
        exit;
    }
    $uniq = uniqid('', true) . '.' . $ext;
    $destAbs = $docsDirAbs . $uniq;
    if (move_uploaded_file($legacySingle['tmp_name'], $destAbs)) {
        $relPath = $docsDirRel . $uniq;
        $pendingInserts[] = [
            'nom'   => $legacySingle['name'],
            'rel'   => $relPath,
            'mime'  => $legacySingle['type'] ?? null,
            'size'  => (int)($legacySingle['size'] ?? 0),
            'sha1'  => @sha1_file($destAbs) ?: null,
        ];
    }
}

/* =========================================================
   Photo
   ========================================================= */
$photo            = $_FILES['photo'] ?? null;
$deletePhoto      = boolish($_POST['deletePhoto'] ?? '0');
$nom_photo_db     = null;
$photoDeletedPhysically = false;

try {
    // ancienne photo
    $stmt = $pdo->prepare("SELECT photo FROM stock WHERE id = ?");
    $stmt->execute([$stockId]);
    $anciennePhotoStored = $stmt->fetchColumn();

    // suppression demandée ?
    if ($deletePhoto && $anciennePhotoStored) {
        $abs = resolveStoredPathToAbsolute($anciennePhotoStored);
        if ($abs && is_file($abs)) {
            @unlink($abs);
            $photoDeletedPhysically = true;
        }
    }

    // upload nouvelle photo
    if ($photo && $photo['error'] === UPLOAD_ERR_OK) {
        $extensionPhoto = strtolower(pathinfo($photo['name'], PATHINFO_EXTENSION));
        $extensionsAutorisees = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($extensionPhoto, $extensionsAutorisees)) {
            echo json_encode(['success' => false, 'message' => 'Format d’image non autorisé.']);
            exit;
        }

        $dirRel  = "uploads/photos/articles/" . $stockId . "/";
        $dirAbs  = __DIR__ . '/' . $dirRel;
        if (!is_dir($dirAbs)) {
            if (!@mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
                echo json_encode(['success' => false, 'message' => 'Impossible de créer le dossier de destination.']);
                exit;
            }
        }

        $nomFic  = uniqid('', true) . '.' . $extensionPhoto;
        $destAbs = $dirAbs . $nomFic;
        if (!move_uploaded_file($photo['tmp_name'], $destAbs)) {
            echo json_encode(['success' => false, 'message' => 'Échec de l’enregistrement de la photo.']);
            exit;
        }

        // supprime l’ancienne si on remplace (et pas déjà supprimée via flag)
        if ($anciennePhotoStored && !$deletePhoto) {
            $abs = resolveStoredPathToAbsolute($anciennePhotoStored);
            if ($abs && is_file($abs)) {
                @unlink($abs);
            }
        }
        $nom_photo_db = $dirRel . $nomFic;
    }

    /* =========================================================
       Transaction: update stock + stock_depots + docs DB
       ========================================================= */
    $pdo->beginTransaction();

    // ancienne quantité
    $stmt = $pdo->prepare("SELECT quantite_totale FROM stock WHERE id = ?");
    $stmt->execute([$stockId]);
    $ancienneQuantite = (int)$stmt->fetchColumn();
    $diff = $quantite - $ancienneQuantite;

    // update stock
    $updateSql = "UPDATE stock SET nom = ?, quantite_totale = ?, quantite_disponible = quantite_disponible + ?";
    $params    = [$nom, $quantite, $diff];

    if ($nom_photo_db !== null) {
        $updateSql .= ", photo = ?";
        $params[] = $nom_photo_db;
    } elseif ($deletePhoto) {
        $updateSql .= ", photo = NULL";
    }
    $updateSql .= " WHERE id = ?";
    $params[] = $stockId;

    $stmt = $pdo->prepare($updateSql);
    $stmt->execute($params);

    // MAJ stock_depots pour depot_id=1 (si présent)
    $stmtDepotCheck = $pdo->prepare("SELECT quantite FROM stock_depots WHERE stock_id = ? AND depot_id = 1");
    $stmtDepotCheck->execute([$stockId]);
    $quantiteDepot = $stmtDepotCheck->fetchColumn();

    if ($quantiteDepot !== false) {
        $nouvelleQuantiteDepot = max(0, (int)$quantiteDepot + $diff);
        $stmtDepotUpdate = $pdo->prepare("UPDATE stock_depots SET quantite = ? WHERE stock_id = ? AND depot_id = 1");
        $stmtDepotUpdate->execute([$nouvelleQuantiteDepot, $stockId]);
    } else {
        $nouvelleQuantiteDepot = max(0, $quantite);
        $stmtDepotInsert = $pdo->prepare("INSERT INTO stock_depots (stock_id, depot_id, quantite) VALUES (?, 1, ?)");
        $stmtDepotInsert->execute([$stockId, $nouvelleQuantiteDepot]);
    }

    // DOCS: suppressions ciblées (DB)
    if (!empty($deleteDocIds)) {
        $in  = implode(',', array_fill(0, count($deleteDocIds), '?'));
        $sql = "SELECT id, chemin_fichier FROM stock_documents WHERE stock_id = ? AND id IN ($in)";
        $paramsSel = array_merge([$stockId], $deleteDocIds);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($paramsSel);
        $toDeleteFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sqlDel = "DELETE FROM stock_documents WHERE stock_id = ? AND id IN ($in)";
        $stmt = $pdo->prepare($sqlDel);
        $stmt->execute($paramsSel);

        foreach ($toDeleteFiles as $r) {
            $docsDeleted[] = (int)$r['id'];
        }
        $filesToUnlink = $toDeleteFiles; // à supprimer après commit
    } else {
        $filesToUnlink = [];
    }

    // DOCS: insert nouveaux (DB)
    if (!empty($pendingInserts)) {
        $uploadedBy = isset($_SESSION['utilisateurs']['id']) ? (int)$_SESSION['utilisateurs']['id'] : null;
        $ins = $pdo->prepare("
            INSERT INTO stock_documents (stock_id, nom_affichage, chemin_fichier, type_mime, taille, checksum_sha1, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($pendingInserts as $pi) {
            $ins->execute([
                $stockId,
                $pi['nom'],
                $pi['rel'],
                $pi['mime'],
                $pi['size'],
                $pi['sha1'],
                $uploadedBy
            ]);
            $newId = (int)$pdo->lastInsertId();
            $docsAdded[] = [
                'id'   => $newId,
                'nom'  => $pi['nom'],
                'url'  => '/' . ltrim($pi['rel'], '/'),
                'size' => (int)$pi['size']
            ];
        }
    }

    $pdo->commit();

    // supprimer fichiers docs après commit
    foreach ($filesToUnlink as $r) {
        $abs = absFromRel($r['chemin_fichier']);
        if ($abs && is_file($abs)) {
            @unlink($abs);
        }
    }

    /* =========================================================
       Récup final (quantité dispo, photo, docs)
       ========================================================= */
    $stmt = $pdo->prepare("SELECT quantite_disponible, photo, categorie, sous_categorie FROM stock WHERE id = ?");
    $stmt->execute([$stockId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT id, nom_affichage, chemin_fichier, taille FROM stock_documents WHERE stock_id = ? ORDER BY id DESC");
    $stmt->execute([$stockId]);
    $docsAll = array_map(function ($r) {
        return [
            'id'   => (int)$r['id'],
            'nom'  => $r['nom_affichage'],
            'url'  => '/' . ltrim($r['chemin_fichier'], '/'),
            'size' => isset($r['taille']) ? (int)$r['taille'] : null
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    /* =========================================================
       Re-render de la ligne <tr> pour remplacement AJAX
       ========================================================= */

    // Recharger infos stock + quantités dépôts/chantiers pour le rendu
    $stmt = $pdo->prepare("SELECT id, nom, quantite_totale, categorie, sous_categorie, photo FROM stock WHERE id = ?");
    $stmt->execute([$stockId]);
    $stk = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
      SELECT d.id, d.nom, sd.quantite
      FROM stock_depots sd
      JOIN depots d ON d.id = sd.depot_id
      WHERE sd.stock_id = ?
      ORDER BY d.nom
    ");
    $stmt->execute([$stockId]);
    $depotsList = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
      SELECT c.id, c.nom,
             (sc.quantite - COALESCE((
                SELECT SUM(te.quantite)
                FROM transferts_en_attente te
                WHERE te.article_id = sc.stock_id
                  AND te.source_type = 'chantier'
                  AND te.source_id   = sc.chantier_id
                  AND te.statut = 'en_attente'
             ),0)) AS quantite
      FROM stock_chantiers sc
      JOIN chantiers c ON c.id = sc.chantier_id
      WHERE sc.stock_id = ?
    ");
    $stmt->execute([$stockId]);
    $chantiersList = array_map(function($r){
        $r['quantite'] = max(0, (int)$r['quantite']);
        return $r;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

   ob_start();
?>
<tr data-row-id="<?= (int)$stk['id'] ?>"
    data-cat="<?= htmlspecialchars($stk['categorie'] ?? '') ?>"
    data-subcat="<?= htmlspecialchars($stk['sous_categorie'] ?? '') ?>">

  <!-- PHOTO (identique à la page) -->
  <td class="text-center" style="width:82px">
    <?php $photoWeb = !empty($stk['photo']) ? '/'.ltrim($stk['photo'],'/') : ''; ?>
    <?php if ($photoWeb): ?>
      <img src="<?= htmlspecialchars($photoWeb) ?>?t=<?= time() ?>"
           alt="<?= htmlspecialchars($stk['nom']) ?>"
           class="rounded border"
           style="width:64px;height:64px;object-fit:cover">
    <?php else: ?>
      <div class="border rounded d-inline-flex align-items-center justify-content-center"
           style="width:64px;height:64px;opacity:.6">—</div>
    <?php endif; ?>
  </td>

  <!-- ARTICLE (identique à la page) -->
  <td class="td-article text-center">
    <a href="article.php?id=<?= (int)$stk['id'] ?>" class="fw-semibold text-decoration-none">
      <?= htmlspecialchars($stk['nom']) ?>
    </a>
    <span class="ms-1 text-muted">(<?= (int)$stk['quantite_totale'] ?>)</span>
    <div class="small text-muted">
      <?php
        $chips = [];
        if (!empty($stk['categorie']))      $chips[] = $stk['categorie'];
        if (!empty($stk['sous_categorie'])) $chips[] = $stk['sous_categorie'];
        echo $chips ? htmlspecialchars(implode(' • ', $chips)) : '—';
      ?>
    </div>
  </td>

  <!-- DÉPÔTS (aucun d-flex / aucune rounded-pill) -->
  <td class="text-center">
    <?php if ($depotsList): foreach ($depotsList as $d): ?>
      <div>
        <?= htmlspecialchars($d['nom']) ?>
        <span id="qty-source-depot-<?= (int)$d['id'] ?>-<?= (int)$stk['id'] ?>"
              class="badge <?= ((int)$d['quantite'] < 10) ? 'bg-danger' : 'bg-success' ?>">
          <?= (int)$d['quantite'] ?>
        </span>
      </div>
    <?php endforeach; else: ?>
      <span class="text-muted">Aucun</span>
    <?php endif; ?>
  </td>

  <!-- CHANTIERS (même parenthèsage serré que ta page) -->
  <td class="text-center">
    <?php
      $chWith = array_values(array_filter($chantiersList, fn($c)=>$c['quantite']>0));
      if ($chWith):
        usort($chWith, fn($a,$b)=>$b['quantite']<=>$a['quantite']);
        foreach ($chWith as $c):
    ?>
      <div>
        <?= htmlspecialchars($c['nom']) ?>
        (<span id="qty-source-chantier-<?= (int)$c['id'] ?>-<?= (int)$stk['id'] ?>"><?= (int)$c['quantite'] ?></span>)
      </div>
    <?php
        endforeach;
      else:
    ?>
      <span class="text-muted">Aucun</span>
    <?php endif; ?>
  </td>

  <!-- ACTIONS (pas de div flex) -->
  <td class="text-center">
    <button class="btn btn-sm btn-primary transfer-btn" data-stock-id="<?= (int)$stk['id'] ?>">
      <i class="bi bi-arrow-left-right"></i>
    </button>
    <button class="btn btn-sm btn-warning edit-btn"
            data-stock-id="<?= (int)$stk['id'] ?>"
            data-stock-nom="<?= htmlspecialchars($stk['nom']) ?>"
            data-stock-quantite="<?= (int)$stk['quantite_totale'] ?>"
            data-stock-photo="<?= htmlspecialchars($photoWeb) ?>">
      <i class="bi bi-pencil"></i>
    </button>
    <button class="btn btn-sm btn-danger delete-btn"
            data-stock-id="<?= (int)$stk['id'] ?>"
            data-stock-nom="<?= htmlspecialchars($stk['nom']) ?>">
      <i class="bi bi-trash"></i>
    </button>
  </td>
</tr>
<?php
$rowHtml = ob_get_clean();


    // Réponse JSON finale
    echo json_encode([
        'success'            => true,
        'rowHtml'            => $rowHtml,
        'rowId'              => (int)$stk['id'],

        'newNom'             => $nom,
        'newQuantiteTotale'  => $quantite,
        'quantiteDispo'      => (int)($row['quantite_disponible'] ?? 0),
        'newPhotoUrl'        => !empty($row['photo']) ? '/' . ltrim($row['photo'], '/') : null,

        'docsAdded'          => $docsAdded,
        'docsDeleted'        => $docsDeleted,
        'docsAll'            => $docsAll,

        'photoDeleted'       => (bool)$photoDeletedPhysically,
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
