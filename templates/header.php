<?php

session_start();
require_once(__DIR__ . '/../fonctions/pdo.php');
if (!isset($_SESSION["langue"])) {
    $_SESSION["langue"] = "Français";
}

if (isset($_GET["langue"])) {
    $_SESSION["langue"] = htmlspecialchars($_GET["langue"]);
}


$langue = $_SESSION["langue"];



// code langue pour la balise <html lang="">
switch ($langue) {
    case 'Portugais':
        $htmlLang = 'pt';
        break;
    case 'Arabe':
        $htmlLang = 'ar';
        break;
    case 'Espagnol':
        $htmlLang = 'es';
        break;
    case 'Roumain':
        $htmlLang = 'ro';
        break;
    default:
        $htmlLang = 'fr';
        break;
}

$drapeaux = [
    "Français" => "/images/france.png",
    "Portugais" => "/images/portugal.png",
    "Arabe" => "/images/maroc.png",
    "Roumain" => "/images/roumain.png",
    "Espagnol" => "/images/espagne.png"
];
$drapeau = isset($drapeaux[$langue]) ? $drapeaux[$langue] : $drapeaux['Français'];


?>




<!DOCTYPE html>
<html lang="<?php echo $htmlLang; ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/css/override-bootstrap.css">
    <link rel="stylesheet" href="../css/styles.css">
    <title>Simpliz</title>
</head>

<body class="d-flex flex-column min-vh-100" style="background-color: #eee;">
    <div>
        <header class="d-flex align-items-center justify-content-center justify-content-around border-bottom">
            <div class="col-md-4">
                <a href="#">
                    <img class="bi" width="120" height="120" role="img" src="/images/simpliz-trans.png" alt="logo">
                </a>
            </div>


            <form action="" method="get">
                <div class="col-md-4 dropdown">
                    <button class="btn btn-outline-primary dropdown-toggle d-none d-md-block" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <img src="<?php echo $drapeau; ?>" alt="Langues" width="30" height="30">
                        <?php echo $langue; ?>
                    </button>
                    <!-- Logo pour les écrans mobiles -->
                    <button class="btn btn-outline-primary dropdown-toggle d-md-none" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <img src="<?php echo $drapeau; ?>" alt="Langues" width="30" height="30">
                    </button>

                    <ul class="dropdown-menu">
                        <li><button class="dropdown-item" type="submit" name="langue" value="Français">Français</button></li>
                        <li><button class="dropdown-item" type="submit" name="langue" value="Portugais">Portugais</button></li>
                        <li><button class="dropdown-item" type="submit" name="langue" value="Arabe">Arabe</button></li>
                        <li><button class="dropdown-item" type="submit" name="langue" value="Espagnol">Espagnol</button></li>
                        <li><button class="dropdown-item" type="submit" name="langue" value="Roumain">Roumain</button></li>
                    </ul>

                </div>

            </form>

            <div class="col-md-4 text-end">
                <?php if (isset($_SESSION["utilisateurs"])): ?>

                    <span>Bonjour <?= $_SESSION["utilisateurs"]["prenom"]; ?> </span>
                    <a class="btn btn-outline-danger me-2" href="/deconnexion.php">Deconnexion</a>

                <?php else: ?>
                    <a class="btn btn-outline-primary me-2" href="/connexion.php">Connexion</a>
                    <a class="btn btn-primary" href="/inscription.php">Inscription</a>
                <?php endif; ?>
            </div>
        </header>
    </div>
    <main class="flex-grow-1">