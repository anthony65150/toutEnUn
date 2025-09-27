<?php
require_once __DIR__ . '/../config/init.php';


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


require_once(__DIR__ . '/../fonctions/pdo.php');


if (!isset($_SESSION["langue"])) {
    $_SESSION["langue"] = "Français";
}
if (isset($_GET["langue"])) {
    $_SESSION["langue"] = htmlspecialchars($_GET["langue"]);
}
$langue = $_SESSION["langue"];

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
$drapeau = $drapeaux[$langue] ?? $drapeaux['Français'];
?>

<!DOCTYPE html>
<html lang="<?= $htmlLang ?>">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) : "Simpliz" ?></title>

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="/images/Main.png" />

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="/assets/css/override-bootstrap.css" />
    <link rel="stylesheet" href="/css/styles.css" />
    <link rel="stylesheet" href="../employes/css/planning.css">
    <link rel="stylesheet" href="../pointage/css/pointage.css">
</head>


<body <?= isset($_POST['sous_categorie']) ? 'data-sous-categorie="' . htmlspecialchars($_POST['sous_categorie']) . '"' : '' ?> class="d-flex flex-column min-vh-100 fond-gris">

    <nav class="navbar navbar-expand-md navbar-light fond-gris border-bottom">
        <div class="container-fluid d-flex justify-content-between align-items-center">

            <!-- Logo -->
            <a class="navbar-brand d-flex align-items-center" href="/index.php">
                <img src="/images/simpliz-trans.png" alt="logo" width="100" height="100" />
            </a>

            <!-- Sélection langue -->
            <form action="" method="get">
                <?php if (!isset($_SESSION["utilisateurs"])) { ?>
                    <div class="dropdown ms-auto">
                        <!-- Desktop -->
                        <button class="btn btn-outline-primary dropdown-toggle d-none d-md-flex align-items-center" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="<?= $drapeau ?>" alt="Langue" width="30" height="30" class="me-1">
                            <?= $langue ?>
                        </button>
                        <!-- Mobile -->
                        <button class="btn btn-outline-primary dropdown-toggle d-md-none" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="<?= $drapeau ?>" alt="Langue" width="30" height="30">
                        </button>
                        <ul class="dropdown-menu">
                            <li><button class="dropdown-item" type="submit" name="langue" value="Français">Français</button></li>
                            <li><button class="dropdown-item" type="submit" name="langue" value="Portugais">Portugais</button></li>
                            <li><button class="dropdown-item" type="submit" name="langue" value="Arabe">Arabe</button></li>
                            <li><button class="dropdown-item" type="submit" name="langue" value="Espagnol">Espagnol</button></li>
                            <li><button class="dropdown-item" type="submit" name="langue" value="Roumain">Roumain</button></li>
                        </ul>
                    </div>
                <?php } ?>
                <button id="toggleDarkMode" style="font-size: 1.5rem; border: none; background: none; cursor: pointer;">
                    🌙
                </button>


            </form>

            <!-- Bonjour + prénom centré -->
            <?php if (isset($_SESSION['utilisateurs'])) : ?>
                <!-- Bloc Bonjour (reste centré) -->
                <div class="flex-grow-1 d-flex justify-content-center px-2">
                    <div class="d-flex align-items-center flex-nowrap overflow-auto py-2 px-2 rounded" style="max-width: 100%;">


                        <a href="/mon-profil.php" class="me-2 flex-shrink-0">
                            <?php
                            $photo = '/images/image-default.png';
                            if (!empty($_SESSION['utilisateurs']['photo'])) {
                                $cheminRelatif = ltrim($_SESSION['utilisateurs']['photo'], '/');
                                $cheminAbsolu  = $_SERVER['DOCUMENT_ROOT'] . '/' . $cheminRelatif;
                                $ext = strtolower(pathinfo($cheminAbsolu, PATHINFO_EXTENSION));
                                if (file_exists($cheminAbsolu) && in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                                    $photo = '/' . $cheminRelatif;
                                }
                            }
                            ?>
                            <img src="<?= htmlspecialchars($photo) ?>"
                                alt="Photo de profil"
                                class="rounded-circle"
                                style="width: 40px; height: 40px; object-fit: cover;">
                        </a>
                        <span class="fw-bold fs-6 text-nowrap bonjour-texte">
                            Bonjour <?= htmlspecialchars($_SESSION['utilisateurs']['prenom']) ?>
                        </span>
                    </div>
                </div>

                <!-- Bouton Déconnexion tout à droite -->
                <div class="d-none d-md-block ms-auto pe-3">
                    <a href="/deconnexion.php" class="nav-link text-danger">Déconnexion</a>
                </div>
            <?php endif; ?>



            <!-- Bouton burger (mobile) -->
            <?php $currentPage = basename($_SERVER['PHP_SELF']); ?>
            <?php if ($currentPage !== 'index.php') : ?>
                <button class="navbar-toggler d-md-none" type="button" data-bs-toggle="collapse" data-bs-target="#navbarBurgerMenu" aria-controls="navbarBurgerMenu" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
            <?php endif; ?>

        </div>
    </nav>
<script>
  window.GMAPS_KEY = "<?= htmlspecialchars(GOOGLE_MAPS_API_KEY, ENT_QUOTES) ?>";
</script>
<script src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode(GOOGLE_MAPS_API_KEY) ?>&libraries=places" defer></script>


    <main class="flex-grow-1">
