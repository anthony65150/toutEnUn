<?php
ob_start();
require_once "./config/init.php";
require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/navigation/navigation.php';

if (!isset($_SESSION['utilisateurs']) || $_SESSION['utilisateurs']['fonction'] !== 'administrateur') {
    header("Location: connexion.php");
    exit;
}

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom = trim($_POST['nom'] ?? '');
    $quantite = (int)($_POST['quantite'] ?? 0);
    $categorie = trim($_POST['categorie'] ?? '');
    $sous_categorie = trim($_POST['sous_categorie'] ?? '');
    $photo = $_FILES['photo'] ?? null;

    // Validation individuelle pour afficher erreurs spécifiques
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

        $stmt = $pdo->prepare("INSERT INTO stock (nom, quantite_totale, quantite_disponible, depot_id, categorie, sous_categorie, photo) 
                               VALUES (?, ?, ?, ?, ?, ?, ?)");

        $stmt->execute([
            $nom,
            $quantite,
            $quantite,
            1,
            $categorie,
            $sous_categorie ?: null,
            $nom_fichier
        ]);

        $success = true;
    }
}
?>

<div class="fond-gris">
    <div class="p-5">
        <div class="container mt-1">
            <h1 class="mb-3 text-center">Ajouter un élément au dépôt</h1>

            <?php if ($success): ?>
                <div class="alert alert-success text-center">Élément ajouté avec succès au dépôt.</div>
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
                    <label for="categorie" class="form-label">Catégorie</label>
                    <select name="categorie" id="categorie" class="form-control form-select" onchange="updateSousCategorie()">
                        <option value="">-- Sélectionner une catégorie --</option>
                        <option value="etaiement" <?= (($_POST['categorie'] ?? '') === 'etaiement') ? 'selected' : '' ?>>Étaiement</option>
                        <option value="banches" <?= (($_POST['categorie'] ?? '') === 'banches') ? 'selected' : '' ?>>Banches</option>
                        <option value="coffrage Peri" <?= (($_POST['categorie'] ?? '') === 'coffrage Peri') ? 'selected' : '' ?>>Coffrage Peri</option>
                        <option value="echafaudages" <?= (($_POST['categorie'] ?? '') === 'echafaudages') ? 'selected' : '' ?>>Échafaudages</option>
                    </select>
                    <?php if (isset($errors['categorie'])): ?>
                        <div class="alert alert-danger mt-1"><?= $errors['categorie'] ?></div>
                    <?php endif; ?>
                </div>

                <div class="mb-3">
                    <label for="sous_categorie" class="form-label">Sous-catégorie (optionnel)</label>
                    <select name="sous_categorie" id="sous_categorie" class="form-control form-select">
                        <option value="">-- Sélectionner une sous-catégorie --</option>
                    </select>
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