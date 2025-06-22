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

switch ($langue) {
    case 'Portugais': $htmlLang = 'pt'; break;
    case 'Arabe': $htmlLang = 'ar'; break;
    case 'Espagnol': $htmlLang = 'es'; break;
    case 'Roumain': $htmlLang = 'ro'; break;
    default: $htmlLang = 'fr'; break;
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
    <title>Simpliz</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="/assets/css/override-bootstrap.css" />
    <link rel="stylesheet" href="../css/styles.css" />
</head>
<body class="d-flex flex-column min-vh-100 fond-gris">

<nav class="navbar navbar-expand-md navbar-light bg-light border-bottom">
  <div class="container-fluid">

    <!-- Logo -->
    <a class="navbar-brand d-flex align-items-center" href="#">
      <img src="/images/simpliz-trans.png" alt="logo" width="120" height="120" />
    </a>

    <!-- Sélection langue -->
    <form action="" method="get" class="d-flex align-items-center me-3">
      <?php if (!isset($_SESSION["utilisateurs"])) : ?>
      <div class="dropdown">
        <button class="btn btn-outline-primary dropdown-toggle d-flex align-items-center" type="button" id="langDropdown" data-bs-toggle="dropdown" aria-expanded="false">
          <img src="<?= $drapeau ?>" alt="Langue <?= $langue ?>" width="30" height="30" class="me-2" />
          <?= htmlspecialchars($langue) ?>
        </button>
        <ul class="dropdown-menu" aria-labelledby="langDropdown">
          <li><button class="dropdown-item" type="submit" name="langue" value="Français">Français</button></li>
          <li><button class="dropdown-item" type="submit" name="langue" value="Portugais">Portugais</button></li>
          <li><button class="dropdown-item" type="submit" name="langue" value="Arabe">Arabe</button></li>
          <li><button class="dropdown-item" type="submit" name="langue" value="Espagnol">Espagnol</button></li>
          <li><button class="dropdown-item" type="submit" name="langue" value="Roumain">Roumain</button></li>
        </ul>
      </div>
      <?php endif; ?>
    </form>

    <!-- Greeting + déconnexion -->
    <div class="d-flex align-items-center me-3">
      <?php if (isset($_SESSION["utilisateurs"])) : ?>
        <span class="me-3">Bonjour <?= htmlspecialchars($_SESSION["utilisateurs"]["prenom"]) ?></span>
        <a class="btn btn-outline-danger" href="/deconnexion.php">Déconnexion</a>
      <?php endif; ?>
    </div>

    <!-- Bouton burger (visible < md) -->
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarBurgerMenu" aria-controls="navbarBurgerMenu" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <!-- Menu -->
    <div class="collapse navbar-collapse" id="navbarBurgerMenu">
      <ul class="navbar-nav ms-auto mb-2 mb-md-0">
        <li class="nav-item"><a class="nav-link" href="/accueil.php">Accueil</a></li>
        <li class="nav-item"><a class="nav-link" href="/profil.php">Mon profil</a></li>
        <li class="nav-item"><a class="nav-link" href="/aide.php">Aide</a></li>
        <?php if (isset($_SESSION["utilisateurs"])) : ?>
          <li class="nav-item"><a class="nav-link text-danger" href="/deconnexion.php">Déconnexion</a></li>
        <?php endif; ?>
      </ul>
    </div>

  </div>
</nav>

<main class="flex-grow-1">
<!-- Le reste de ta page -->
