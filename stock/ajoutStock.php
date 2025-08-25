<?php
declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../config/init.php';

/* =========================================================
   AJAX: sous-catégories par catégorie
   ========================================================= */
if (isset($_GET['action']) && $_GET['action'] === 'getSousCategories' && !empty($_GET['categorie'])) {
    header('Content-Type: application/json');
    $categorie = trim((string)$_GET['categorie']);

    $stmt = $pdo->prepare("
        SELECT DISTINCT sous_categorie
        FROM stock
        WHERE categorie = ? AND sous_categorie IS NOT NULL AND sous_categorie <> ''
        ORDER BY sous_categorie
    ");
    $stmt->execute([$categorie]);
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
    // '/uploads/...' ou 'uploads/...'
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

    // Pré‑validation doc (optionnelle)
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

    // Pré‑validation photo (optionnelle)
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

            // 1) Créer l’article sans fichiers (on obtient l’ID)
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
            $stockId = (int)$pdo->lastInsertId();

            // 2) Enregistrer le document (si fourni) → /uploads/documents/articles/{id}/
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

                // Si tu gardes encore la colonne stock.document (legacy)
                $pdo->prepare("UPDATE stock SET document = ? WHERE id = ?")
                    ->execute([$document_path_web, $stockId]);

                // Si tu veux en parallèle remplir stock_documents, décommente :
                /*
                $pdo->prepare("
                    INSERT INTO stock_documents (stock_id, nom_affichage, chemin_fichier, type_mime, taille, checksum_sha1, uploaded_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $stockId,
                    $document['name'],
                    $document_path_web,
                    $document['type'] ?? null,
                    (int)($document['size'] ?? 0),
                    @sha1_file($dest_abs) ?: null,
                    (int)($_SESSION['utilisateurs']['id'] ?? 0)
                ]);
                */
            }

            // 3) Enregistrer la photo (si fournie) → /uploads/photos/articles/{id}/
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

            // 4) Mettre la quantité au dépôt #1 + recalcul dispo
            $depotId = 1;
            $pdo->prepare("
                INSERT INTO stock_depots (stock_id, depot_id, quantite)
                VALUES (?, ?, ?)
            ")->execute([$stockId, $depotId, $quantite]);

            // dispo = somme des dépôts
            $pdo->prepare("
                UPDATE stock s
                SET quantite_disponible = (
                    SELECT COALESCE(SUM(quantite), 0) FROM stock_depots WHERE stock_id = s.id
                )
                WHERE s.id = ?
            ")->execute([$stockId]);

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
$categoriesExistantes = $pdo->query("
    SELECT DISTINCT categorie FROM stock WHERE categorie IS NOT NULL AND categorie <> '' ORDER BY categorie
")->fetchAll(PDO::FETCH_COLUMN);

$sousCategoriesExistantes = [];
$categorieSelectionnee = $_POST['nouvelleCategorie'] ?? ($_POST['categorieSelect'] ?? '');
if ($categorieSelectionnee !== '') {
    $stmtSC = $pdo->prepare("
        SELECT DISTINCT sous_categorie FROM stock
        WHERE categorie = ? AND sous_categorie IS NOT NULL AND sous_categorie <> ''
        ORDER BY sous_categorie
    ");
    $stmtSC->execute([$categorieSelectionnee]);
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
