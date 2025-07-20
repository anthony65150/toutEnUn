<?php
require_once "./config/init.php";
require_once "templates/header.php";
require_once "templates/navigation/navigation.php";

// Si utilisateur non connecté, rediriger vers index.php (nouveau fichier connexion)
if (!isset($_SESSION['utilisateurs'])) {
    header('Location: index.php');
    exit;
}

// Récupérer le chemin de la page actuelle
$current_page = $_SERVER['PHP_SELF'];
// On récupère l'utilisateur connecté
$utilisateurId = $_SESSION['utilisateurs']['id'];
$utilisateurPrenom = $_SESSION['utilisateurs']['prenom'];

// Condition : afficher une photo spéciale si ID = 7
if ($utilisateurId == 7 ) {
    echo '<div class="text-center mt-4">
            <img src="/images/310756-marine-star-academy.jpg" alt="Surprise spéciale" class="img-fluid rounded" style="max-width: 300px;">
            <p>Hey ' . htmlspecialchars($utilisateurPrenom) . ', cette photo est rien que pour toi 😎 !</p>
          </div>';
}



?>

<?php
require_once "templates/footer.php";
?>
