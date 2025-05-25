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


if (!isset($_SESSION['utilisateurs']) || $_SESSION['utilisateurs']['fonction'] !== 'administrateur' && $_SESSION['utilisateurs']['fonction'] !== 'chef') {
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
                    <a href="/admin/pointage.php" class="nav-link <?php echo (strpos($current_page, 'admin/pointage.php') !== false) ? 'active' : ''; ?> text-center">
                        Pointage
                    </a>
                </li>
            <?php endif; ?>
            <?php if (isset($_SESSION['utilisateurs']['fonction']) && $_SESSION['utilisateurs']['fonction'] === 'administrateur') : ?>
                <li class="p-2">
                    <a href="/admin/ajoutEmploye.php" class="nav-link <?php echo ($current_page == 'ajoutEmploye.php') ? 'active' : ''; ?> text-center">
                        Ajout employés
                    </a>
                </li>
            <?php endif; ?>
        </ul>

    </div>

</div>