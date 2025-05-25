<?php
require_once(__DIR__ . '/../templates/header.php');
require_once(__DIR__ . '/../fonctions/utilisateurs.php');

// Récupérer le chemin de la page actuelle
$current_page = $_SERVER['PHP_SELF'];

$errors = [];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $verif = verifieUtilisateur($_POST);
    if ($verif === true) {
        $resAdd = ajouUtilisateur($pdo, $_POST["nom"], $_POST["prenom"], $_POST["email"], $_POST["motDePasse"],  $_POST["fonction"]);
        header("Location: ../connexion.php");
    } else {
        $errors = $verif;
    }
}


if (!isset($_SESSION['utilisateurs']) || $_SESSION['utilisateurs']['fonction'] !== 'administrateur') {
    header('Location: ../index.php');
    exit;
}
?>

<div class="row flex-grow-1" style="min-height: 100%;" style="background-color: #eee;">

    <div class="d-flex flex-column justify-content-between col-md-3 border-end p-0">

        <ul class="nav nav-pills flex-column mb-auto">
            <li class="nav-item p-2">
                <a href="../index.php" class="nav-link <?php echo ($current_page == '/index.php') ? 'active' : ''; ?> text-center" aria-current="page">
                    Accueil
                </a>
            </li>
            <li class="p-2">
                <a href="mes_documents.php" class="nav-link <?php echo ($current_page == '/mes_documents.php') ? 'active' : ''; ?> text-center">
                    Mes documents
                </a>
            </li>
            <li class="p-2">
                <a href="mes_conges.php" class="nav-link <?php echo ($current_page == '/mes_conges.php') ? 'active' : ''; ?> text-center">
                    Mes congés
                </a>
            </li>
            <li class="p-2">
                <a href="mon_pointage.php" class="nav-link <?php echo ($current_page == '/mon_pointage.php') ? 'active' : ''; ?> text-center">
                    Mon pointage
                </a>
            </li>
            <li class="p-2">
                <a href="autres_demandes.php" class="nav-link <?php echo ($current_page == '/autres_demandes.php') ? 'active' : ''; ?> text-center">
                    Autres demandes
                </a>
            </li>
            <?php if (isset($_SESSION['utilisateurs']['fonction']) && ($_SESSION['utilisateurs']['fonction'] === 'administrateur' || $_SESSION['utilisateurs']['fonction'] === 'chef')) : ?>
                <li class="p-2">
                    <a href="/admin/pointage.php" class="nav-link <?php echo ($current_page == 'pointage.php') ? 'active' : ''; ?> text-center">
                        Pointage
                    </a>
                </li>
            <?php endif; ?>
            <li class="p-2">
                <a href="#" class="nav-link <?php echo (strpos($current_page, 'admin/ajoutEmploye.php') !== false) ? 'active' : ''; ?> text-center">
                    Ajout employés
                </a>
            </li>
        </ul>

    </div>

    <div class="col-md-9 p-5">
        <div class=" container form-signin mt-4">
            <h1 class="mb-3 text-center">Ajout employés</h1>
            <form action="" method="post">
                <div class="mb-3">
                    <label class="form-label" for="nom">Nom</label>
                    <input class="form-control" type="text" name="nom" id="nom">
                    <?php if (isset($errors["nom"])) { ?>
                        <div class="alert alert-danger" role="alert">
                            <?= $errors["nom"] ?>
                        </div>
                    <?php } ?>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="prenom">Prénom</label>
                    <input class="form-control" type="text" name="prenom" id="prenom">
                    <?php if (isset($errors["prenom"])) { ?>
                        <div class="alert alert-danger" role="alert">
                            <?= $errors["prenom"] ?>
                        </div>
                    <?php } ?>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="email">Email</label>
                    <input class="form-control" type="email" name="email" id="email">
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
                <div class="mb-3">
                    <label for="fonction">Fonction</label>
                    <select name="fonction" id="fonction" class="form-control">
                        <option value="">-- Sélectionner une fonction --</option>
                        <option value="employé">Employé</option>
                        <option value="chef">Chef</option>
                        <option value="administrateur">Administrateur</option>
                    </select>
                </div>

                <button class="btn btn-primary w-100" type="submit" name="ajoutUtilisateur">Ajouter</button>
            </form>
        </div>

    </div>

</div>




<?php
require_once __DIR__ . '/../templates/footer.php';
?>