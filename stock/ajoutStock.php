<?php
declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../config/init.php';

/* ================================
   MULTI-ENTREPRISE (helpers)
   ================================ */
$ENT_ID = $_SESSION['utilisateurs']['entreprise_id'] ?? null;
$ENT_ID = is_numeric($ENT_ID) ? (int)$ENT_ID : null;

function me_where_first(?int $ENT_ID, string $alias = ''): array {
    if ($ENT_ID === null) return ['', []];
    $pfx = $alias ? $alias . '.' : '';
    return [" WHERE {$pfx}entreprise_id = :eid ", [':eid' => $ENT_ID]];
}
function me_where(?int $ENT_ID, string $alias = ''): array {
    if ($ENT_ID === null) return ['', []];
    $pfx = $alias ? $alias . '.' : '';
    return [" AND {$pfx}entreprise_id = :eid ", [':eid' => $ENT_ID]];
}

/* =========================================================
   AJAX: sous-catégories par catégorie
   ========================================================= */
if (isset($_GET['action']) && $_GET['action'] === 'getSousCategories' && !empty($_GET['categorie'])) {
    header('Content-Type: application/json');
    $categorie = trim((string)$_GET['categorie']);

    list($frag, $p) = me_where($ENT_ID, 's');
    $sql = "
        SELECT DISTINCT s.sous_categorie
        FROM stock s
        WHERE s.categorie = :c AND s.sous_categorie IS NOT NULL AND s.sous_categorie <> ''
        {$frag}
        ORDER BY s.sous_categorie
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([':c' => $categorie], $p));
    echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
    exit;
}

/* =========================================================
   Accès admin
   ========================================================= */
if (!isset($_SESSION['utilisateurs']) || ($_SESSION['utilisateurs']['fonction'] ?? null) !== 'administrateur') {
    header("Location: /connexion.php");
    exit;
}

/* =========================================================
   Helpers
   ========================================================= */
function web_to_abs(string $webPath): string {
    $rel = ltrim($webPath, '/');
    return rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/' . $rel;
}

$errors  = [];
$success = false;

/* =========================================================
   POST
   ========================================================= */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $nom       = ucfirst(mb_strtolower(trim($_POST['nom'] ?? ''), 'UTF-8'));
    $quantite  = (int)($_POST['quantite'] ?? 0);

    // Catégorie
    $categorie = '';
    if (!empty($_POST['nouvelleCategorie'])) {
        $categorie = ucfirst(mb_strtolower(trim($_POST['nouvelleCategorie']), 'UTF-8'));
    } elseif (!empty($_POST['categorieSelect'])) {
        $categorie = trim((string)$_POST['categorieSelect']);
    }

    // Sous-catégorie
    $sous_categorie = '';
    if (!empty($_POST['nouvelleSousCategorie'])) {
        $sous_categorie = ucfirst(mb_strtolower(trim($_POST['nouvelleSousCategorie']), 'UTF-8'));
    } elseif (!empty($_POST['sous_categorieSelect'])) {
        $sous_categorie = trim((string)$_POST['sous_categorieSelect']);
    }

    // Fichiers (photo + document)
    $photo    = $_FILES['photo']    ?? null;
    $document = $_FILES['document'] ?? null;

    // Validations
    if ($nom === '')                $errors['nom'] = "Le nom est obligatoire.";
    if ($quantite < 0)              $errors['quantite'] = "La quantité doit être positive.";
    if ($categorie === '')          $errors['categorie'] = "La catégorie est obligatoire.";
    if ($ENT_ID === null) {
        // Optionnel: tu peux forcer le multi-entreprise uniquement — ici on autorise le fallback.
        // $errors['entreprise'] = "Aucune entreprise en session.";
    }

    // Pré-validation doc (optionnelle)
    $document_path_web = null;
    if ($document && $document['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($document['error'] !== UPLOAD_ERR_OK) {
            $errors['document'] = "Erreur lors de l’upload du document.";
        } else {
            $ext = strtolower(pathinfo($document['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf','doc','docx','xls','xlsx','png','jpg','jpeg','webp'];
            if (!in_array($ext, $allowed, true)) {
                $errors['document'] = "Type de fichier non autorisé.";
            }
        }
    }

    // Pré-validation photo (optionnelle)
    $photo_path_web = null;
    if ($photo && $photo['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($photo['error'] !== UPLOAD_ERR_OK) {
            $errors['photo'] = "Erreur lors de l’upload de la photo.";
        } else {
            $extp = strtolower(pathinfo($photo['name'], PATHINFO_EXTENSION));
            $allowedImg = ['jpg','jpeg','png','gif','webp'];
            if (!in_array($extp, $allowedImg, true)) {
                $errors['photo'] = "Format d’image non autorisé.";
            }
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Trouver le 1er dépôt de l'entreprise
            $depotId = null;
            $sqlDepot = "SELECT d.id FROM depots d";
            list($fragD, $pD) = me_where_first($ENT_ID, 'd');
            $sqlDepot .= $fragD . " ORDER BY d.id ASC LIMIT 1";
            $stDepot = $pdo->prepare($sqlDepot);
            $stDepot->execute($pD);
            $depotId = (int)($stDepot->fetchColumn() ?: 0);

            if ($depotId <= 0) {
                throw new RuntimeException("Aucun dépôt n'est configuré pour cette entreprise.");
            }

            // 1) Créer l’article sans fichiers (on obtient l’ID) + entreprise_id
            if ($ENT_ID !== null) {
                $stmt = $pdo->prepare("
                    INSERT INTO stock (nom, quantite_totale, quantite_disponible, categorie, sous_categorie, photo, document, entreprise_id)
                    VALUES (?, ?, 0, ?, ?, NULL, NULL, ?)
                ");
                $stmt->execute([
                    $nom,
                    $quantite,
                    $categorie,
                    $sous_categorie !== '' ? $sous_categorie : null,
                    $ENT_ID
                ]);
            } else {
                // Fallback ancien projet (colonne entreprise_id absente ou non utilisée)
                $stmt = $pdo->prepare("
                    INSERT INTO stock (nom, quantite_totale, quantite_disponible, categorie, sous_categorie, photo, document)
                    VALUES (?, ?, 0, ?, ?, NULL, NULL)
                ");
                $stmt->execute([
                    $nom,
                    $quantite,
                    $categorie,
                    $sous_categorie !== '' ? $sous_categorie : null
                ]);
            }
            $stockId = (int)$pdo->lastInsertId();

            // 2) Enregistrer le document (si fourni)
            if ($document && $document['error'] === UPLOAD_ERR_OK) {
                $ext  = strtolower(pathinfo($document['name'], PATHINFO_EXTENSION));
                $dir_web = "uploads/documents/articles/{$stockId}/";
                $dir_abs = web_to_abs($dir_web);
                if (!is_dir($dir_abs)) @mkdir($dir_abs, 0775, true);

                $filename = uniqid('', true) . '.' . $ext;
                $dest_abs = rtrim($dir_abs, '/') . '/' . $filename;

                if (!move_uploaded_file($document['tmp_name'], $dest_abs)) {
                    throw new RuntimeException("Échec de l’enregistrement du document.");
                }
                $document_path_web = $dir_web . $filename;

                $pdo->prepare("UPDATE stock SET document = ? WHERE id = ?")
                    ->execute([$document_path_web, $stockId]);
            }

            // 3) Enregistrer la photo (si fournie)
            if ($photo && $photo['error'] === UPLOAD_ERR_OK) {
                $extp = strtolower(pathinfo($photo['name'], PATHINFO_EXTENSION));
                $dir_web = "uploads/photos/articles/{$stockId}/";
                $dir_abs = web_to_abs($dir_web);
                if (!is_dir($dir_abs)) @mkdir($dir_abs, 0775, true);

                $filename = uniqid('', true) . '.' . $extp;
                $dest_abs = rtrim($dir_abs, '/') . '/' . $filename;

                if (!move_uploaded_file($photo['tmp_name'], $dest_abs)) {
                    throw new RuntimeException("Échec de l’enregistrement de la photo.");
                }
                $photo_path_web = $dir_web . $filename;

                $pdo->prepare("UPDATE stock SET photo = ? WHERE id = ?")
                    ->execute([$photo_path_web, $stockId]);
            }

            // 4) Mettre la quantité dans le dépôt choisi + recalcul dispo
            //    (avec fallback si stock_depots n'a pas entreprise_id)
            $insertOk = false;
            if ($ENT_ID !== null) {
                try {
                    $pdo->prepare("
                        INSERT INTO stock_depots (stock_id, depot_id, quantite, entreprise_id)
                        VALUES (?, ?, ?, ?)
                    ")->execute([$stockId, $depotId, $quantite, $ENT_ID]);
                    $insertOk = true;
                } catch (Throwable $e) {
                    // La colonne entreprise_id n'existe pas -> fallback
                }
            }
            if (!$insertOk) {
                $pdo->prepare("
                    INSERT INTO stock_depots (stock_id, depot_id, quantite)
                    VALUES (?, ?, ?)
                ")->execute([$stockId, $depotId, $quantite]);
            }

            // dispo = somme des dépôts de l'entreprise
            $sqlSum = "
                UPDATE stock s
                SET quantite_disponible = (
                    SELECT COALESCE(SUM(sd.quantite), 0)
                    FROM stock_depots sd
                    JOIN depots d ON d.id = sd.depot_id
                    WHERE sd.stock_id = s.id
                )
                WHERE s.id = ?
            ";
            // Si multi-entreprise actif, limite aux dépôts de l’entreprise
            if ($ENT_ID !== null) {
                $sqlSum = "
                    UPDATE stock s
                    SET quantite_disponible = (
                        SELECT COALESCE(SUM(sd.quantite), 0)
                        FROM stock_depots sd
                        JOIN depots d ON d.id = sd.depot_id
                        WHERE sd.stock_id = s.id AND d.entreprise_id = :eid
                    )
                    WHERE s.id = :sid
                ";
                $stSum = $pdo->prepare($sqlSum);
                $stSum->execute([':eid' => $ENT_ID, ':sid' => $stockId]);
            } else {
                $stSum = $pdo->prepare($sqlSum);
                $stSum->execute([$stockId]);
            }

            $pdo->commit();
            $success = true;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors['general'] = "Erreur lors de l'ajout : " . $e->getMessage();
        }
    }
}

/* =========================================================
   Données pour le formulaire
   ========================================================= */
list($fragCat, $pCat) = me_where_first($ENT_ID, 's');
$sql = "
    SELECT DISTINCT s.categorie
    FROM stock s
    {$fragCat}
    AND s.categorie IS NOT NULL AND s.categorie <> ''
    ORDER BY s.categorie
";
$stmt = $pdo->prepare($sql);
$stmt->execute($pCat);
$categoriesExistantes = $stmt->fetchAll(PDO::FETCH_COLUMN);

$sousCategoriesExistantes = [];
$categorieSelectionnee = $_POST['nouvelleCategorie'] ?? ($_POST['categorieSelect'] ?? '');
if ($categorieSelectionnee !== '') {
    list($fragSub, $pSub) = me_where($ENT_ID, 's');
    $stmtSC = $pdo->prepare("
        SELECT DISTINCT s.sous_categorie
        FROM stock s
        WHERE s.categorie = :c AND s.sous_categorie IS NOT NULL AND s.sous_categorie <> ''
        {$fragSub}
        ORDER BY s.sous_categorie
    ");
    $stmtSC->execute(array_merge([':c' => $categorieSelectionnee], $pSub));
    $sousCategoriesExistantes = $stmtSC->fetchAll(PDO::FETCH_COLUMN);
}

// Header + Nav
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/navigation/navigation.php';
?>
<body
  <?= !empty($_POST['nouvelleCategorie']) ? 'data-nouvelle-categorie="1"' : '' ?>
  <?= !empty($_POST['nouvelleSousCategorie']) ? ' data-nouvelle-sous-categorie="1"' : '' ?>
>
  <div class="fond-gris">
    <div class="p-5">
      <div class="container mt-1">
        <h1 class="mb-3 text-center">Ajouter un élément au dépôt</h1>

        <?php if ($success): ?>
          <div class="alert alert-success text-center">✅ Élément ajouté avec succès au dépôt.</div>
        <?php elseif (!empty($errors['general'])): ?>
          <div class="alert alert-danger text-center"><?= htmlspecialchars($errors['general']) ?></div>
        <?php endif; ?>

        <form action="" method="POST" enctype="multipart/form-data" class="mx-auto" style="max-width: 1000px;">
          <div class="mb-3">
            <label for="nom" class="form-label">Nom de l'élément</label>
            <input type="text" name="nom" id="nom" class="form-control"
                   value="<?= htmlspecialchars($_POST['nom'] ?? '') ?>">
            <?php if (!empty($errors['nom'])): ?>
              <div class="alert alert-danger mt-1"><?= $errors['nom'] ?></div>
            <?php endif; ?>
          </div>

          <div class="mb-3">
            <label for="quantite" class="form-label">Quantité totale</label>
            <input type="number" name="quantite" id="quantite" min="0" class="form-control"
                   value="<?= htmlspecialchars($_POST['quantite'] ?? '') ?>">
            <?php if (!empty($errors['quantite'])): ?>
              <div class="alert alert-danger mt-1"><?= $errors['quantite'] ?></div>
            <?php endif; ?>
          </div>

          <div class="mb-3">
            <label for="categorieSelect" class="form-label">Catégorie</label>
            <select name="categorieSelect" id="categorieSelect" class="form-control form-select" onchange="toggleNewCategoryInput()">
              <option value="" disabled <?= empty($_POST['categorieSelect']) && empty($_POST['nouvelleCategorie']) ? 'selected' : '' ?>>-- Sélectionner une catégorie --</option>
              <?php foreach ($categoriesExistantes as $cat): ?>
                <option value="<?= htmlspecialchars($cat) ?>" <?= (($_POST['categorieSelect'] ?? '') === $cat) ? 'selected' : '' ?>>
                  <?= ucfirst(htmlspecialchars($cat)) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button type="button" id="btnAddCategory" class="btn btn-link p-0 mt-1" onclick="showNewCategoryInput()">+ Ajouter une catégorie</button>
            <?php if (!empty($errors['categorie'])): ?>
              <div class="alert alert-danger mt-1"><?= $errors['categorie'] ?></div>
            <?php endif; ?>
          </div>

          <div class="mb-3" id="newCategorieDiv" style="display: none;">
            <label for="nouvelleCategorie" class="form-label">Nouvelle catégorie</label>
            <input type="text" name="nouvelleCategorie" id="nouvelleCategorie" class="form-control"
                   value="<?= htmlspecialchars($_POST['nouvelleCategorie'] ?? '') ?>">
          </div>

          <div class="mb-3">
            <label for="sous_categorieSelect" class="form-label">Sous-catégorie (optionnel)</label>
            <select name="sous_categorieSelect" id="sous_categorieSelect" class="form-control form-select" onchange="toggleNewSubCategoryInput()">
              <option value="" disabled <?= empty($_POST['sous_categorieSelect']) && empty($_POST['nouvelleSousCategorie']) ? 'selected' : '' ?>>-- Sélectionner une sous-catégorie --</option>
              <?php foreach ($sousCategoriesExistantes as $sc): ?>
                <option value="<?= htmlspecialchars($sc) ?>" <?= (($_POST['sous_categorieSelect'] ?? '') === $sc) ? 'selected' : '' ?>>
                  <?= ucfirst(htmlspecialchars($sc)) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button type="button" id="btnAddSubCategory" class="btn btn-link p-0 mt-1" onclick="showNewSubCategoryInput()">+ Ajouter une sous-catégorie</button>
          </div>

          <div class="mb-3" id="newSousCategorieDiv" style="display: none;">
            <label for="nouvelleSousCategorie" class="form-label">Nouvelle sous-catégorie</label>
            <input type="text" name="nouvelleSousCategorie" id="nouvelleSousCategorie" class="form-control"
                   value="<?= htmlspecialchars($_POST['nouvelleSousCategorie'] ?? '') ?>">
          </div>

          <div class="mb-4">
            <label for="photo" class="form-label">Photo (optionnel)</label>
            <input type="file" name="photo" id="photo" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp">
          </div>

          <div class="mb-3">
            <label for="modifierDocument" class="form-label">Document technique (PDF, notice, etc.)</label>
            <input type="file" name="document" id="modifierDocument" class="form-control"
                   accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.webp">
          </div>

          <div class="text-center mt-5">
            <button type="submit" class="btn btn-primary w-50">Ajouter au dépôt</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="/stock/js/ajoutStock.js"></script>

  <?php
  require_once __DIR__ . '/../templates/footer.php';
  ob_end_flush();
  ?>
