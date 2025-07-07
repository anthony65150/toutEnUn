<?php
ob_start();
require_once(__DIR__ . '/templates/header.php');
require_once(__DIR__ . '/fonctions/utilisateurs.php');
require_once(__DIR__ . "/templates/navigation/navigation.php");

if (!isset($_SESSION['utilisateurs']) || $_SESSION['utilisateurs']['fonction'] !== 'administrateur') {
    header('Location: ../index.php');
    exit;
}

$current_page = $_SERVER['PHP_SELF'];

// Récupération des chantiers
$chantiers = $pdo->query("SELECT id, nom FROM chantiers ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $verif = verifieUtilisateur($_POST);
    if ($verif === true) {
        $chantier_id = ($_POST["fonction"] === "chef") ? (int)$_POST["chantier_id"] : null;
        $resAdd = ajouUtilisateur($pdo, $_POST["nom"], $_POST["prenom"], $_POST["email"], $_POST["motDePasse"], $_POST["fonction"], $chantier_id);
        header("Location: ../index.php");
        exit;
    } else {
        $errors = $verif;
    }
}
?>

<div class="fond-gris">
    <div class="p-5">
        <div class="container mt-1">
            <h1 class="mb-3 text-center">Ajout employés</h1>
            <form action="" method="post" class="mx-auto" style="max-width: 1000px;">
                <div class="mb-3">
                    <label class="form-label" for="nom">Nom</label>
                    <input type="text" name="nom" id="nom" class="form-control" value="<?= htmlspecialchars($_POST['nom'] ?? '') ?>">
                    <?php if (isset($errors["nom"])): ?>
                        <div class="alert alert-danger"><?= $errors["nom"] ?></div>
                    <?php endif; ?>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="prenom">Prénom</label>
                    <input type="text" name="prenom" id="prenom" class="form-control" value="<?= htmlspecialchars($_POST['prenom'] ?? '') ?>">
                    <?php if (isset($errors["prenom"])): ?>
                        <div class="alert alert-danger"><?= $errors["prenom"] ?></div>
                    <?php endif; ?>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="email">Email</label>
                    <input type="email" name="email" id="email" class="form-control" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    <?php if (isset($errors["email"])): ?>
                        <div class="alert alert-danger"><?= $errors["email"] ?></div>
                    <?php endif; ?>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="motDePasse">Mot de passe</label>
                    <input type="password" name="motDePasse" id="motDePasse" class="form-control">
                    <?php if (isset($errors["motDePasse"])): ?>
                        <div class="alert alert-danger"><?= $errors["motDePasse"] ?></div>
                    <?php endif; ?>
                </div>

                <div class="mb-3">
                    <label for="fonction">Fonction</label>
                    <select name="fonction" id="fonction" class="form-select">
                        <option value="">-- Choisir une fonction --</option>
                        <option value="employé" <?= ($_POST['fonction'] ?? '') === 'employé' ? 'selected' : '' ?>>Employé</option>
                        <option value="chef" <?= ($_POST['fonction'] ?? '') === 'chef' ? 'selected' : '' ?>>Chef</option>
                        <option value="depot" <?= ($_POST['fonction'] ?? '') === 'depot' ? 'selected' : '' ?>>Dépôt</option>
                        <option value="administrateur" <?= ($_POST['fonction'] ?? '') === 'administrateur' ? 'selected' : '' ?>>Administrateur</option>
                    </select>
                    <?php if (isset($errors["fonction"])): ?>
                        <div class="alert alert-danger"><?= $errors["fonction"] ?></div>
                    <?php endif; ?>
                </div>

                <!-- Chantier à attribuer (uniquement si chef sélectionné) -->
                <div class="mb-3" id="chantierContainer" style="display: none;">
                    <label for="chantier_id" class="form-label">Affecter au chantier</label>
                    <select name="chantier_id" id="chantier_id" class="form-select">
                        <option value="">-- Choisir un chantier --</option>
                        <?php foreach ($chantiers as $chantier): ?>
                            <option value="<?= $chantier['id'] ?>" <?= (($_POST['chantier_id'] ?? '') == $chantier['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($chantier['nom']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors["chantier_id"])): ?>
                        <div class="alert alert-danger"><?= $errors["chantier_id"] ?></div>
                    <?php endif; ?>
                </div>

                <div class="text-center mt-5">
                    <button class="btn btn-primary w-50" type="submit" name="ajoutUtilisateur">Ajouter</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function () {
        const fonctionSelect = document.getElementById('fonction');
        const chantierContainer = document.getElementById('chantierContainer');

        function toggleChantier() {
            chantierContainer.style.display = (fonctionSelect.value === 'chef') ? 'block' : 'none';
        }

        fonctionSelect.addEventListener('change', toggleChantier);

        // Active au chargement si "chef" est pré-sélectionné
        toggleChantier();
    });
</script>

<?php
require_once __DIR__ . '/templates/footer.php';
ob_end_flush();
?>
