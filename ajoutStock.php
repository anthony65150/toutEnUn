<?php
ob_start();
require_once "./config/init.php";
// === GESTION AJAX pour récupérer sous-catégories ===
if (isset($_GET['action']) && $_GET['action'] === 'getSousCategories' && !empty($_GET['categorie'])) {
    header('Content-Type: application/json');
    $categorie = $_GET['categorie'];

    $stmt = $pdo->prepare("SELECT DISTINCT sous_categorie FROM stock WHERE categorie = ? AND sous_categorie IS NOT NULL AND sous_categorie != '' ORDER BY sous_categorie");
    $stmt->execute([$categorie]);
    $sousCategories = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode($sousCategories);
    exit;
}
require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/navigation/navigation.php';

// === Vérification connexion + rôle admin ===
if (!isset($_SESSION['utilisateurs']) || $_SESSION['utilisateurs']['fonction'] !== 'administrateur') {
    header("Location: connexion.php");
    exit;
}

$errors = [];
$success = false;

// === Traitement du formulaire POST ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom = trim($_POST['nom'] ?? '');
    $quantite = (int)($_POST['quantite'] ?? 0);

    // Gestion catégorie
    $categorie = '';
    if (!empty($_POST['nouvelleCategorie'])) {
        $categorie = trim($_POST['nouvelleCategorie']);
    } elseif (!empty($_POST['categorieSelect'])) {
        $categorie = trim($_POST['categorieSelect']);
    }

    // Gestion sous-catégorie
    $sous_categorie = '';
    if (!empty($_POST['nouvelleSousCategorie'])) {
        $sous_categorie = trim($_POST['nouvelleSousCategorie']);
    } elseif (!empty($_POST['sous_categorieSelect'])) {
        $sous_categorie = trim($_POST['sous_categorieSelect']);
    }

    $photo = $_FILES['photo'] ?? null;

    if ($nom === '') {
        $errors['nom'] = "Le nom est obligatoire.";
    }
    if ($quantite < 0) {
        $errors['quantite'] = "La quantité doit être positive.";
    }
    if ($categorie === '') {
        $errors['categorie'] = "La catégorie est obligatoire.";
    }

    if (empty($errors)) {
        $nom_fichier = null;
        if ($photo && $photo['error'] === UPLOAD_ERR_OK) {
            $extension = pathinfo($photo['name'], PATHINFO_EXTENSION);
            $nom_fichier = uniqid() . '.' . $extension;
            move_uploaded_file($photo['tmp_name'], __DIR__ . '/uploads/' . $nom_fichier);
        }

        try {
            $pdo->beginTransaction();

            // Insertion dans la table 'stock'
            $stmt = $pdo->prepare("INSERT INTO stock (nom, quantite_totale, categorie, sous_categorie, photo) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$nom, $quantite, $categorie, $sous_categorie ?: null, $nom_fichier]);

            $stockId = $pdo->lastInsertId();

            // Insertion dans stock_depots pour le dépôt 1 (à adapter si besoin)
            $depotId = 1;
            $stmtDepot = $pdo->prepare("INSERT INTO stock_depots (stock_id, depot_id, quantite) VALUES (?, ?, ?)");
            $stmtDepot->execute([$stockId, $depotId, $quantite]);

            // Mettre à jour quantite_disponible dans stock
            $stmtUpdateDispo = $pdo->prepare("
                UPDATE stock s
                SET quantite_disponible = (
                    SELECT COALESCE(SUM(quantite), 0) FROM stock_depots WHERE stock_id = s.id
                )
                WHERE s.id = ?
            ");
            $stmtUpdateDispo->execute([$stockId]);

            $pdo->commit();
            $success = true;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors['general'] = "Erreur lors de l'ajout : " . $e->getMessage();
        }
    }
}

// Récupérer catégories
$categoriesExistantes = $pdo->query("SELECT DISTINCT categorie FROM stock ORDER BY categorie")->fetchAll(PDO::FETCH_COLUMN);

// Récupérer sous-catégories selon catégorie sélectionnée POST ou vide
$sousCategoriesExistantes = [];
$categorieSelectionnee = $_POST['nouvelleCategorie'] ?? ($_POST['categorieSelect'] ?? '');
if ($categorieSelectionnee !== '') {
    $stmtSC = $pdo->prepare("SELECT DISTINCT sous_categorie FROM stock WHERE categorie = ? AND sous_categorie IS NOT NULL AND sous_categorie != '' ORDER BY sous_categorie");
    $stmtSC->execute([$categorieSelectionnee]);
    $sousCategoriesExistantes = $stmtSC->fetchAll(PDO::FETCH_COLUMN);
}
?>

<body
    <?php if (!empty($_POST['nouvelleCategorie'])): ?>data-nouvelle-categorie="1" <?php endif; ?>
    <?php if (!empty($_POST['nouvelleSousCategorie'])): ?> data-nouvelle-sous-categorie="1" <?php endif; ?>>
    <div class="fond-gris">
        <div class="p-5">
            <div class="container mt-1">
                <h1 class="mb-3 text-center">Ajouter un élément au dépôt</h1>

                <?php if ($success): ?>
                    <div class="alert alert-success text-center">✅ Élément ajouté avec succès au dépôt.</div>
                <?php elseif (isset($errors['general'])): ?>
                    <div class="alert alert-danger text-center"><?= htmlspecialchars($errors['general']) ?></div>
                <?php endif; ?>

                <form action="" method="POST" enctype="multipart/form-data" class="mx-auto" style="max-width: 1000px;">
                    <div class="mb-3">
                        <label for="nom" class="form-label">Nom de l'élément</label>
                        <input type="text" name="nom" id="nom" class="form-control" value="<?= htmlspecialchars($_POST['nom'] ?? '') ?>">
                        <?php if (isset($errors['nom'])): ?>
                            <div class="alert alert-danger mt-1"><?= $errors['nom'] ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label for="quantite" class="form-label">Quantité totale</label>
                        <input type="number" name="quantite" id="quantite" min="0" class="form-control" value="<?= htmlspecialchars($_POST['quantite'] ?? '') ?>">
                        <?php if (isset($errors['quantite'])): ?>
                            <div class="alert alert-danger mt-1"><?= $errors['quantite'] ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label for="categorieSelect" class="form-label">Catégorie</label>
                        <select name="categorieSelect" id="categorieSelect" class="form-control form-select" onchange="toggleNewCategoryInput()">
                            <option value="" disabled selected>-- Sélectionner une catégorie --</option>
                            <?php foreach ($categoriesExistantes as $cat): ?>
                                <option value="<?= htmlspecialchars($cat) ?>" <?= (($_POST['categorieSelect'] ?? '') === $cat) ? 'selected' : '' ?>><?= ucfirst(htmlspecialchars($cat)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="btnAddCategory" class="btn btn-link p-0 mt-1" onclick="showNewCategoryInput()">+ Ajouter une catégorie</button>
                        <?php if (isset($errors['categorie'])): ?>
                            <div class="alert alert-danger mt-1"><?= $errors['categorie'] ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3" id="newCategorieDiv" style="display: none;">
                        <label for="nouvelleCategorie" class="form-label">Nouvelle catégorie</label>
                        <input type="text" name="nouvelleCategorie" id="nouvelleCategorie" class="form-control" value="<?= htmlspecialchars($_POST['nouvelleCategorie'] ?? '') ?>">
                    </div>

                    <div class="mb-3">
                        <label for="sous_categorieSelect" class="form-label">Sous-catégorie (optionnel)</label>
                        <select name="sous_categorieSelect" id="sous_categorieSelect" class="form-control form-select" onchange="toggleNewSubCategoryInput()">
                            <option value="" disabled selected>-- Sélectionner une sous-catégorie --</option>
                            <?php foreach ($sousCategoriesExistantes as $sc): ?>
                                <option value="<?= htmlspecialchars($sc) ?>" <?= (($_POST['sous_categorieSelect'] ?? '') === $sc) ? 'selected' : '' ?>><?= ucfirst(htmlspecialchars($sc)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="btnAddSubCategory" class="btn btn-link p-0 mt-1" onclick="showNewSubCategoryInput()">+ Ajouter une sous-catégorie</button>
                    </div>

                    <div class="mb-3" id="newSousCategorieDiv" style="display: none;">
                        <label for="nouvelleSousCategorie" class="form-label">Nouvelle sous-catégorie</label>
                        <input type="text" name="nouvelleSousCategorie" id="nouvelleSousCategorie" class="form-control" value="<?= htmlspecialchars($_POST['nouvelleSousCategorie'] ?? '') ?>">
                    </div>

                    <div class="mb-4">
                        <label for="photo" class="form-label">Photo (optionnel)</label>
                        <input type="file" name="photo" id="photo" class="form-control">
                    </div>

                    <div class="text-center mt-5">
                        <button type="submit" class="btn btn-primary w-50">Ajouter au dépôt</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<script src="/js/ajoutStock.js"></script>

<?php
require_once __DIR__ . '/templates/footer.php';
ob_end_flush();
?>
