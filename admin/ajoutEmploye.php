<?php
ob_start(); // Commence le tampon
require_once(__DIR__ . '/../templates/header.php');
require_once(__DIR__ . '/../fonctions/utilisateurs.php');
require_once(__DIR__ . "/../templates/navigation/navigation.php");

// Récupérer le chemin de la page actuelle
$current_page = $_SERVER['PHP_SELF'];

$errors = [];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $verif = verifieUtilisateur($_POST);
    if ($verif === true) {
        $resAdd = ajouUtilisateur($pdo, $_POST["nom"], $_POST["prenom"], $_POST["email"], $_POST["motDePasse"],  $_POST["fonction"]);
        header("Location: ../index.php");
    } else {
        $errors = $verif;
    }
}


if (!isset($_SESSION['utilisateurs']) || $_SESSION['utilisateurs']['fonction'] !== 'administrateur') {
    header('Location: ../index.php');
    exit;
}
?>

<div class="fond-gris">

    <div class="p-5">
        <div class=" container mt-1">
            <h1 class="mb-3 text-center">Ajout employés</h1>
            <form action="" method="post" class="mx-auto"  style="max-width: 1000px;">
                <div class="mb-3">
                    <label class="form-label" for="nom">Nom</label>
                    <input type="text" name="nom" id="nom" class="form-control" value="<?= htmlspecialchars($_POST['nom'] ?? '') ?>">
                    <?php if (isset($errors["nom"])) { ?>
                        <div class="alert alert-danger" role="alert">
                            <?= $errors["nom"] ?>
                        </div>
                    <?php } ?>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="prenom">Prénom</label>
                    <input type="text" name="prenom" id="prenom" class="form-control" value="<?= htmlspecialchars($_POST['prenom'] ?? '') ?>">
                    <?php if (isset($errors["prenom"])) { ?>
                        <div class="alert alert-danger" role="alert">
                            <?= $errors["prenom"] ?>
                        </div>
                    <?php } ?>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="email">Email</label>
                    <input type="email" name="email" id="email" class="form-control" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    <?php if (isset($errors["email"])) { ?>
                        <div class="alert alert-danger" role="alert">
                            <?= $errors["email"] ?>
                        </div>
                    <?php } ?>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="motDePasse">Mot de passe</label>
                    <input class="form-control" type="password" name="motDePasse" id="motDePasse">
                    <?php if (isset($errors["motDePasse"])) { ?>
                        <div class="alert alert-danger" role="alert">
                            <?= $errors["motDePasse"] ?>
                        </div>
                    <?php } ?>
                </div>
                <div class="mb-4">
                    <label for="fonction">Sélectionner une fonction</label>
                    <select name="fonction" id="fonction" class="form-control form-select">
                        <option value="employé" <?= (($_POST['fonction'] ?? '') === 'employé') ? 'selected' : '' ?>>Employé</option>
                        <option value="chef">Chef</option>
                        <option value="administrateur">Administrateur</option>
                    </select>
                </div>

                <div class="text-center mt-5">
                    <button class="btn btn-primary w-50" type="submit" name="ajoutUtilisateur">Ajouter</button>
                </div>
            </form>
        </div>

    </div>

</div>




<?php
require_once __DIR__ . '/../templates/footer.php';
ob_end_flush(); // Termine et affiche le tampon
?>